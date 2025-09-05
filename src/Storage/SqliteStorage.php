<?php

namespace YetiSearch\Storage;

use YetiSearch\Contracts\StorageInterface;
use YetiSearch\Exceptions\StorageException;
use YetiSearch\Geo\GeoPoint;
use YetiSearch\Geo\GeoBounds;
use YetiSearch\Cache\QueryCache;
use YetiSearch\Storage\PreparedStatementCache;

class SqliteStorage implements StorageInterface
{
    private ?\PDO $connection = null;
    private array $config = [];
    private array $searchConfig = [];
    private ?PreparedStatementCache $stmtCache = null;
    private ?bool $rtreeSupport = null;
    private bool $useTermsIndex = false;
    private ?bool $hasMathFunctions = null;
    private array $ftsColumnsCache = [];
    private array $spatialEnabledCache = [];
    private bool $externalContentDefault = false;
    private ?QueryCache $queryCache = null;
    
    public function connect(array $config): void
    {
        $this->config = $config;
        // Support both 'database' and 'path' keys for database location
        $dbPath = $config['database'] ?? $config['path'] ?? ':memory:';
        
        // Extract search config if available
        $this->searchConfig = $config['search'] ?? [];
        
        // Only use terms index if Levenshtein fuzzy search is enabled
        $this->useTermsIndex = ($this->searchConfig['enable_fuzzy'] ?? false) && 
                               ($this->searchConfig['fuzzy_algorithm'] ?? 'basic') === 'levenshtein';
        // External-content/doc_id mode (opt-in)
        $this->externalContentDefault = (bool)($config['external_content'] ?? false);
        
        // Create directory if it doesn't exist and path is not :memory:
        if ($dbPath !== ':memory:') {
            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0755, true)) {
                    throw new StorageException("Failed to create directory: {$dir}");
                }
            }
        }
        
        try {
            $this->connection = new \PDO("sqlite:{$dbPath}", null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
            
            $this->connection->exec('PRAGMA foreign_keys = ON');
            $this->connection->exec('PRAGMA journal_mode = WAL');
            $this->connection->exec('PRAGMA synchronous = OFF'); // Most aggressive for bulk loading
            $this->connection->exec('PRAGMA temp_store = MEMORY');
            $this->connection->exec('PRAGMA cache_size = -20000');
            $this->connection->exec('PRAGMA mmap_size = 268435456'); // 256MB memory map
            if (!empty($this->config['exclusive_lock'])) {
                $this->connection->exec('PRAGMA locking_mode = EXCLUSIVE');
            }
            
            $this->initializeDatabase();
            // Improve query planning on connect
            $this->connection->exec('PRAGMA optimize');

            // Detect availability of math functions for Haversine
            try {
                $this->connection->query('SELECT sin(0.0), cos(0.0), pi()')->fetch();
                $this->hasMathFunctions = true;
            } catch (\PDOException $e) {
                $this->hasMathFunctions = false;
            }
            
            // Initialize query cache if enabled
            $cacheConfig = $config['cache'] ?? [];
            if ($cacheConfig['enabled'] ?? false) {  // Default to disabled for backward compatibility
                $this->queryCache = new QueryCache($this->connection, $cacheConfig);
            }
            
            // Initialize prepared statement cache
            $maxStmts = isset($config['prepared_statements']['max_size']) 
                ? $config['prepared_statements']['max_size'] 
                : 50;
            $this->stmtCache = new PreparedStatementCache($maxStmts);
        } catch (\PDOException $e) {
            throw new StorageException("Failed to connect to SQLite: " . $e->getMessage());
        }
    }
    
    public function disconnect(): void
    {
        if ($this->stmtCache) {
            $this->stmtCache->clear();
        }
        $this->connection = null;
    }
    
    public function createIndex(string $name, array $options = []): void
    {
        $this->ensureConnected();
        
        try {
            $useExternal = (bool)($options['external_content'] ?? $this->externalContentDefault);
            
            // Check if we should use multi-column FTS mode (default: true for better performance)
            // NOTE: Multi-column FTS is incompatible with external content when using JSON storage
            $useMultiColumnFts = $options['multi_column_fts'] ?? $this->searchConfig['multi_column_fts'] ?? true;
            
            // Force single-column mode for external content with JSON storage
            if ($useExternal) {
                $useMultiColumnFts = false;
            }
            
            $ftsColumns = $options['fields'] ?? ['content'];
            
            
            // If multi-column mode is disabled, use single content column
            if (!$useMultiColumnFts) {
                $ftsColumns = ['content'];
            } else if (count($ftsColumns) <= 1 && $ftsColumns[0] === 'content' && !isset($options['fields'])) {
                // If only default 'content' field and no explicit fields provided, keep single-column mode
                $useMultiColumnFts = false;
            }
            // Otherwise keep the provided fields for multi-column mode
            
            // Ensure per-index meta table exists
            $this->connection->exec("CREATE TABLE IF NOT EXISTS {$name}_meta (key TEXT PRIMARY KEY, value TEXT)");
            // Persist schema mode and FTS configuration
            $this->setIndexMeta($name, 'schema_mode', $useExternal ? 'external' : 'legacy');
            $this->setIndexMeta($name, 'multi_column_fts', $useMultiColumnFts ? '1' : '0');
            $this->setIndexMeta($name, 'fts_columns', json_encode($ftsColumns));
            if ($useExternal) {
                $sql = "
                    CREATE TABLE IF NOT EXISTS {$name} (
                        doc_id INTEGER PRIMARY KEY,
                        id TEXT UNIQUE,
                        content TEXT NOT NULL,
                        metadata TEXT,
                        language TEXT,
                        type TEXT DEFAULT 'default',
                        timestamp INTEGER DEFAULT (strftime('%s', 'now'))
                    )
                ";
                $this->connection->exec($sql);
            } else {
                $sql = "
                    CREATE TABLE IF NOT EXISTS {$name} (
                        id TEXT PRIMARY KEY,
                        content TEXT NOT NULL,
                        metadata TEXT,
                        language TEXT,
                        type TEXT DEFAULT 'default',
                        score REAL DEFAULT 0,
                        timestamp INTEGER DEFAULT (strftime('%s', 'now')),
                        indexed_at INTEGER DEFAULT (strftime('%s', 'now'))
                    )
                ";
                $this->connection->exec($sql);
            }
            
            $this->connection->exec("CREATE INDEX IF NOT EXISTS idx_{$name}_language ON {$name}(language)");
            $this->connection->exec("CREATE INDEX IF NOT EXISTS idx_{$name}_type ON {$name}(type)");
            $this->connection->exec("CREATE INDEX IF NOT EXISTS idx_{$name}_timestamp ON {$name}(timestamp)");
            
            // Determine FTS configuration
            // Note: $ftsColumns and $useMultiColumnFts were already set above
            // We only need to process them here if they weren't already configured
            // This section is kept for backward compatibility with old option format
            // Build optional prefix config
            $prefix = $options['fts']['prefix'] ?? $options['fts_prefix'] ?? $this->searchConfig['fts_prefix'] ?? null;
            $prefixSql = '';
            if (is_array($prefix) && !empty($prefix)) {
                $prefixSql = ", prefix='" . implode(' ', array_map('intval', $prefix)) . "'";
            }
            // Optional FTS detail
            $detail = $options['fts']['detail'] ?? null; // 'full' | 'column' | 'none'
            $detailSql = '';
            if (is_string($detail) && in_array(strtolower($detail), ['full','column','none'], true)) {
                $detailSql = ", detail='" . strtolower($detail) . "'";
            }
            // Store FTS columns in meta
            $this->setIndexMeta($name, 'fts_columns', json_encode($ftsColumns));
            if ($detail) { $this->setIndexMeta($name, 'fts_detail', strtolower($detail)); }

            // Create FTS5 table with configured columns
            $cols = array_map(function($c){ return $c; }, $ftsColumns);
            if ($useExternal) {
                $ftsColsSql = implode(', ', $cols);
                $sql = "CREATE VIRTUAL TABLE IF NOT EXISTS {$name}_fts USING fts5({$ftsColsSql}, content='{$name}', content_rowid='doc_id', tokenize='unicode61'{$prefixSql}{$detailSql})";
                $this->connection->exec($sql);
            } else {
                $ftsColsSql = 'id UNINDEXED, ' . implode(', ', $cols);
                $sql = "CREATE VIRTUAL TABLE IF NOT EXISTS {$name}_fts USING fts5({$ftsColsSql}, tokenize='unicode61'{$prefixSql}{$detailSql})";
                $this->connection->exec($sql);
            }
            
            // Only create terms table if Levenshtein fuzzy search is enabled
            if ($this->useTermsIndex) {
                $termsSql = "
                    CREATE TABLE IF NOT EXISTS {$name}_terms (
                        term TEXT NOT NULL,
                        document_id TEXT NOT NULL,
                        field TEXT NOT NULL,
                        frequency INTEGER DEFAULT 1,
                        positions TEXT,
                        PRIMARY KEY (term, document_id, field)
                    )
                ";
                $this->connection->exec($termsSql);
                
                $this->connection->exec("CREATE INDEX IF NOT EXISTS idx_{$name}_terms_term ON {$name}_terms(term)");
                $this->connection->exec("CREATE INDEX IF NOT EXISTS idx_{$name}_terms_doc ON {$name}_terms(document_id)");
            }
            
            // Create spatial support (R-tree if available, otherwise a normal table for consistency)
            $spatialEnabled = (bool)($options['enable_spatial'] ?? true);
            $this->setIndexMeta($name, 'spatial_enabled', $spatialEnabled ? '1' : '0');
            $this->spatialEnabledCache[$name] = $spatialEnabled;
            if ($spatialEnabled) {
                if ($this->hasRTreeSupport()) {
                    $spatialSql = "
                        CREATE VIRTUAL TABLE IF NOT EXISTS {$name}_spatial USING rtree(
                            id,              -- Document ID (integer required by R-tree)
                            minLat, maxLat,  -- Latitude bounds
                            minLng, maxLng   -- Longitude bounds
                        )
                    ";
                    $this->connection->exec($spatialSql);
                } else {
                    // Fallback: regular table to satisfy tests and enable basic maintenance
                    $spatialSql = "
                        CREATE TABLE IF NOT EXISTS {$name}_spatial (
                            id INTEGER PRIMARY KEY,
                            minLat REAL, maxLat REAL,
                            minLng REAL, maxLng REAL
                        )
                    ";
                    $this->connection->exec($spatialSql);
                }
                // Legacy-only id_map
                if (!$useExternal) {
                    $mappingSql = "
                        CREATE TABLE IF NOT EXISTS {$name}_id_map (
                            string_id TEXT PRIMARY KEY,
                            numeric_id INTEGER
                        )
                    ";
                    $this->connection->exec($mappingSql);
                    $this->connection->exec("CREATE INDEX IF NOT EXISTS idx_{$name}_id_map_numeric ON {$name}_id_map(numeric_id)");
                }
            }
            
        } catch (\PDOException $e) {
            throw new StorageException("Failed to create index '{$name}': " . $e->getMessage());
        }
    }
    
    public function dropIndex(string $name): void
    {
        $this->ensureConnected();
        
        try {
            $this->connection->exec("DROP TABLE IF EXISTS {$name}");
            $this->connection->exec("DROP TABLE IF EXISTS {$name}_fts");
            $this->connection->exec("DROP TABLE IF EXISTS {$name}_terms");
            $this->connection->exec("DROP TABLE IF EXISTS {$name}_spatial");
            $this->connection->exec("DROP TABLE IF EXISTS {$name}_id_map");
            $this->connection->exec("DROP TABLE IF EXISTS {$name}_meta");
        } catch (\PDOException $e) {
            throw new StorageException("Failed to drop index '{$name}': " . $e->getMessage());
        }
    }
    
    public function indexExists(string $name): bool
    {
        $this->ensureConnected();
        
        $stmt = $this->connection->prepare(
            "SELECT name FROM sqlite_master WHERE type='table' AND name=?"
        );
        $stmt->execute([$name]);
        
        return $stmt->fetch() !== false;
    }
    
    public function insert(string $index, array $document): void
    {
        $this->ensureConnected();
        
        // Invalidate cache for this index when inserting
        if ($this->queryCache) {
            $this->queryCache->invalidate($index);
        }
        
        try {
            $this->connection->beginTransaction();
            
            $id = $document['id'];
            $content = json_encode($document['content']);
            // Persist metadata and, when R-tree or math functions are unavailable, embed geo for JSON fallback
            $metadataArr = $document['metadata'] ?? [];
            if (!$this->hasRTreeSupport() || !$this->hasMathFunctions) {
                if (isset($document['geo']) && is_array($document['geo'])) {
                    $g = $document['geo'];
                    if (isset($g['lat'], $g['lng'])) {
                        $metadataArr['_geo'] = [
                            'lat' => (float)$g['lat'],
                            'lng' => (float)$g['lng'],
                        ];
                    }
                }
                if (isset($document['geo_bounds']) && is_array($document['geo_bounds'])) {
                    $b = $document['geo_bounds'];
                    if (isset($b['north'],$b['south'],$b['east'],$b['west'])) {
                        $metadataArr['_geo_bounds'] = [
                            'north' => (float)$b['north'],
                            'south' => (float)$b['south'],
                            'east'  => (float)$b['east'],
                            'west'  => (float)$b['west'],
                        ];
                    }
                }
            }
            $metadata = json_encode($metadataArr);
            $language = $document['language'] ?? null;
            $type = $document['type'] ?? 'default';
            $timestamp = $document['timestamp'] ?? time();
            
            $schema = $this->getSchemaMode($index);
            if ($schema === 'external') {
                $sql = "
                    INSERT INTO {$index} (id, content, metadata, language, type, timestamp)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON CONFLICT(id) DO UPDATE SET
                        content=excluded.content,
                        metadata=excluded.metadata,
                        language=excluded.language,
                        type=excluded.type,
                        timestamp=excluded.timestamp
                ";
                $stmt = $this->connection->prepare($sql);
                $stmt->execute([$id, $content, $metadata, $language, $type, $timestamp]);
            } else {
                $sql = "
                    INSERT OR REPLACE INTO {$index} 
                    (id, content, metadata, language, type, timestamp)
                    VALUES (?, ?, ?, ?, ?, ?)
                ";
                $stmt = $this->connection->prepare($sql);
                $stmt->execute([$id, $content, $metadata, $language, $type, $timestamp]);
            }
            
            // Insert into FTS with dynamic columns
            $ftsColumns = $this->getFtsColumns($index);
            if ($schema === 'external') {
                $docId = $this->getDocId($index, $id);
                // For external content, FTS5 reads from the content table
                // We only need to insert the rowid to trigger indexing
                $ftsSql = "INSERT OR REPLACE INTO {$index}_fts (rowid, content) VALUES (?, ?)";
                $ftsStmt = $this->connection->prepare($ftsSql);
                // Combine all content into a single text value for FTS
                $contentText = $this->getFieldText($document['content'], 'content', $index);
                $ftsStmt->execute([$docId, $contentText]);
            } else {
                $placeholders = implode(', ', array_fill(0, count($ftsColumns) + 1, '?'));
                $columnsSql = 'id, ' . implode(', ', $ftsColumns);
                $ftsSql = "INSERT OR REPLACE INTO {$index}_fts ({$columnsSql}) VALUES ({$placeholders})";
                $ftsStmt = $this->connection->prepare($ftsSql);
                $values = [$id];
                foreach ($ftsColumns as $col) {
                    $values[] = $this->getFieldText($document['content'], $col, $index);
                }
                $ftsStmt->execute($values);
            }
            
            // Only index terms if Levenshtein fuzzy search is enabled
            if ($this->useTermsIndex) {
                $this->indexTerms($index, $id, $document['content']);
            }
            
            // Handle spatial indexing
            $this->indexSpatialData($index, $id, $document);
            
            $this->connection->commit();
        } catch (\PDOException $e) {
            $this->connection->rollBack();
            throw new StorageException("Failed to insert document: " . $e->getMessage());
        }
    }
    
    public function insertBatch(string $index, array $documents): void
    {
        $this->ensureConnected();
        
        // Invalidate cache for this index when batch inserting
        if ($this->queryCache) {
            $this->queryCache->invalidate($index);
        }
        
        if (empty($documents)) {
            return;
        }
        
        try {
            $this->connection->beginTransaction();
            
            // Prepare statements once
            $schema = $this->getSchemaMode($index);
            if ($schema === 'external') {
                $docStmt = $this->connection->prepare("
                    INSERT INTO {$index} (id, content, metadata, language, type, timestamp)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON CONFLICT(id) DO UPDATE SET
                        content=excluded.content,
                        metadata=excluded.metadata,
                        language=excluded.language,
                        type=excluded.type,
                        timestamp=excluded.timestamp
                ");
            } else {
                $docStmt = $this->connection->prepare("
                    INSERT OR REPLACE INTO {$index} 
                    (id, content, metadata, language, type, timestamp)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
            }
            
            // Prepare FTS insert statement dynamically
            $ftsColumns = $this->getFtsColumns($index);
            if ($schema === 'external') {
                // External content with JSON storage only supports single-column FTS
                $ftsStmt = $this->connection->prepare("INSERT OR REPLACE INTO {$index}_fts (rowid, content) VALUES (?, ?)");
            } else {
                // Sanitize column names to ensure they're valid SQL identifiers
                $validColumns = [];
                foreach ($ftsColumns as $col) {
                    // Only keep valid column names (letters, numbers, underscores)
                    if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) {
                        $validColumns[] = $col;
                    }
                }
                
                // Fallback to 'content' if no valid columns
                if (empty($validColumns)) {
                    $validColumns = ['content'];
                }
                
                $columnsSql = 'id, ' . implode(', ', $validColumns);
                $placeholders = implode(', ', array_fill(0, count($validColumns) + 1, '?'));
                $ftsStmt = $this->connection->prepare("INSERT OR REPLACE INTO {$index}_fts ({$columnsSql}) VALUES ({$placeholders})");
                
                // Update ftsColumns to use validated columns
                $ftsColumns = $validColumns;
            }
            
            $termsStmt = null;
            if ($this->useTermsIndex) {
                $termsStmt = $this->connection->prepare("
                    INSERT OR REPLACE INTO {$index}_terms 
                    (term, document_id, field, frequency, positions)
                    VALUES (?, ?, ?, ?, ?)
                ");
            }
            
            // Process all documents in a single transaction
            foreach ($documents as $document) {
                $id = $document['id'];
                $content = json_encode($document['content']);
                // Persist metadata and, when R-tree or math functions are unavailable, embed geo for JSON fallback
                $metadataArr = $document['metadata'] ?? [];
                if (!$this->hasRTreeSupport() || !$this->hasMathFunctions) {
                    if (isset($document['geo']) && is_array($document['geo'])) {
                        $g = $document['geo'];
                        if (isset($g['lat'], $g['lng'])) {
                            $metadataArr['_geo'] = [
                                'lat' => (float)$g['lat'],
                                'lng' => (float)$g['lng'],
                            ];
                        }
                    }
                    if (isset($document['geo_bounds']) && is_array($document['geo_bounds'])) {
                        $b = $document['geo_bounds'];
                        if (isset($b['north'],$b['south'],$b['east'],$b['west'])) {
                            $metadataArr['_geo_bounds'] = [
                                'north' => (float)$b['north'],
                                'south' => (float)$b['south'],
                                'east'  => (float)$b['east'],
                                'west'  => (float)$b['west'],
                            ];
                        }
                    }
                }
                $metadata = json_encode($metadataArr);
                $language = $document['language'] ?? null;
                $type = $document['type'] ?? 'default';
                $timestamp = $document['timestamp'] ?? time();
                
                // Insert main document
                $docStmt->execute([$id, $content, $metadata, $language, $type, $timestamp]);
                
                // Insert FTS content
                if ($schema === 'external') {
                    // For external content, just insert rowid and combined content
                    $docId = $this->getDocId($index, $id);
                    $contentText = $this->getFieldText($document['content'], 'content', $index);
                    $ftsStmt->execute([$docId, $contentText]);
                } else {
                    // For non-external, insert with all columns
                    $values = [$id];
                    // Use the validated columns from earlier
                    foreach ($ftsColumns as $col) {
                        $values[] = $this->getFieldText($document['content'], $col, $index);
                    }
                    $ftsStmt->execute($values);
                }
                
                // Only index terms if Levenshtein fuzzy search is enabled
                if ($this->useTermsIndex && $termsStmt) {
                    $this->indexTermsWithStatement($termsStmt, $id, $document['content']);
                }
                
                // Handle spatial indexing
                $this->indexSpatialData($index, $id, $document);
            }
            
            $this->connection->commit();
        } catch (\PDOException $e) {
            $this->connection->rollBack();
            throw new StorageException("Failed to insert batch: " . $e->getMessage());
        }
    }
    
    private function indexTermsWithStatement(\PDOStatement $stmt, string $documentId, array $content): void
    {
        foreach ($content as $field => $value) {
            if (!is_string($value)) {
                continue;
            }
            
            $terms = $this->tokenizeText($value);
            $termFrequencies = array_count_values($terms);
            
            foreach ($termFrequencies as $term => $frequency) {
                $positions = $this->findTermPositions($value, $term);
                $stmt->execute([
                    $term,
                    $documentId,
                    $field,
                    $frequency,
                    json_encode($positions)
                ]);
            }
        }
    }
    
    public function update(string $index, string $id, array $document): void
    {
        // Invalidate cache for this index when updating
        if ($this->queryCache) {
            $this->queryCache->invalidate($index);
        }
        
        $document['id'] = $id;
        $this->insert($index, $document);
    }
    
    public function delete(string $index, string $id): void
    {
        $this->ensureConnected();
        
        // Invalidate cache for this index when deleting
        if ($this->queryCache) {
            $this->queryCache->invalidate($index);
        }
        
        try {
            $this->connection->beginTransaction();
            // Capture doc_id early for external schema
            $schema = $this->getSchemaMode($index);
            $savedDocId = null;
            if ($schema === 'external') {
                $savedDocId = $this->getDocId($index, $id);
            }

            // Delete from main table first
            $this->connection->prepare("DELETE FROM {$index} WHERE id = ?")->execute([$id]);

            // Handle FTS deletion based on schema
            if ($schema === 'external') {
                // For external content tables, we need to rebuild the FTS to sync with content table
                // The 'rebuild' command rebuilds the FTS index from the content table
                $this->connection->exec("INSERT INTO {$index}_fts({$index}_fts) VALUES('rebuild')");
            } else {
                // For non-external content, regular DELETE works
                $this->connection->prepare("DELETE FROM {$index}_fts WHERE id = ?")->execute([$id]);
            }

            // Terms table
            if ($this->useTermsIndex) {
                $this->connection->prepare("DELETE FROM {$index}_terms WHERE document_id = ?")->execute([$id]);
            }

            // Spatial cleanup (works for both R-tree and fallback table)
            if ($schema === 'external') {
                if ($savedDocId !== null) {
                    $this->connection->prepare("DELETE FROM {$index}_spatial WHERE id = ?")->execute([$savedDocId]);
                }
            } else {
                $this->connection->prepare("DELETE FROM {$index}_id_map WHERE string_id = ?")->execute([$id]);
                $spatialId = $this->getNumericId($id);
                $this->connection->prepare("DELETE FROM {$index}_spatial WHERE id = ?")->execute([$spatialId]);
            }

            $this->connection->commit();
        } catch (\PDOException $e) {
            $this->connection->rollBack();
            throw new StorageException("Failed to delete document: " . $e->getMessage());
        }
    }
    
    public function search(string $index, array $query): array
    {
        $this->ensureConnected();
        
        // Check cache first if enabled
        if ($this->queryCache && !($query['bypass_cache'] ?? false)) {
            $cached = $this->queryCache->get($index, $query);
            if ($cached !== null) {
                return $cached;
            }
        }
        
        $searchQuery = $query['query'] ?? '';
        $filters = $query['filters'] ?? [];
        $limit = $query['limit'] ?? 20;
        $offset = $query['offset'] ?? 0;
        $sort = $query['sort'] ?? [];
        $language = $query['language'] ?? null;
        $geoFilters = $query['geoFilters'] ?? [];
        $fieldWeights = $query['field_weights'] ?? [];
        
        // Build spatial query components
        $spatial = $this->buildSpatialQuery($index, $geoFilters);
        
        
        // Check if we have a search query
        $hasSearchQuery = !empty(trim($searchQuery));
        
        
        // Detect if we need PHP-based sorting (FTS5 + distance sorting)
        $needsPhpSort = false;
        $hasSpatialData = !empty($spatial['select']) && (
            strpos($spatial['select'], 'distance') !== false ||
            strpos($spatial['select'], '_centroid_lat') !== false
        );
        
        if ($hasSpatialData && (isset($geoFilters['distance_sort']) || isset($sort['distance']))) {
            $needsPhpSort = true;
        }
        
        // When using field weights, we need to fetch more results to ensure proper scoring
        // Increase the candidate pool significantly to catch documents that might rank high after field weighting
        $effectiveLimit = (!empty($fieldWeights) && $hasSearchQuery) ? min(max($limit * 50, 1000), 5000) : $limit;
        
        
        // Nearest-neighbor mode (k-NN): ignore text, order by distance asc, limit k
        if (isset($geoFilters['nearest'])) {
            $k = is_array($geoFilters['nearest']) ? (int)($geoFilters['nearest']['k'] ?? 10) : (int)$geoFilters['nearest'];
            $k = max(1, min($k, 1000));
            $from = $geoFilters['nearest']['from'] ?? ($geoFilters['distance_sort']['from'] ?? null);
            if ($from && is_array($from)) { $from = new GeoPoint($from['lat'], $from['lng']); }
            if ($from instanceof GeoPoint) {
                $lat = $from->getLatitude(); $lng = $from->getLongitude();
                $schema = $this->getSchemaMode($index);
                $join = $schema === 'external'
                    ? " INNER JOIN {$index}_spatial s ON s.id = d.doc_id"
                    : " INNER JOIN {$index}_id_map m ON m.string_id = d.id INNER JOIN {$index}_spatial s ON s.id = m.numeric_id";
                $distanceExpr = $this->getDistanceExpression($lat, $lng);
                $sql = "SELECT d.id, d.content, d.metadata, d.language, d.type, d.timestamp, " . $distanceExpr . " AS distance, ((s.minLat+s.maxLat)/2.0) AS _centroid_lat, ((s.minLng+s.maxLng)/2.0) AS _centroid_lng FROM {$index} d" . $join . " WHERE 1=1";
                $params = [];
                if (isset($geoFilters['max_distance'])) {
                    $maxD = (float)$geoFilters['max_distance'];
                    $units = $geoFilters['units'] ?? ($this->searchConfig['geo_units'] ?? null);
                    if (is_string($units)) { $u=strtolower($units); if ($u==='km') $maxD*=1000.0; elseif ($u==='mi'||$u==='mile'||$u==='miles') $maxD*=1609.344; }
                    $sql .= " AND (".$distanceExpr.") <= ?"; $params[] = $maxD;
                }
                // Apply standard filters
                foreach ($filters as $filter) {
                    $field = $filter['field']; $operator = $filter['operator'] ?? '='; $value = $filter['value'];
                    if (in_array($field, ['type','language','id','timestamp'])) { $sql .= " AND d.{$field} {$operator} ?"; $params[]=$value; }
                }
                $sql .= " ORDER BY distance ASC LIMIT ?"; $params[] = $k;

                try {
                    $stmt = $this->getPreparedStatement($sql);
                    $stmt->execute($params);
                    $results = [];
                    while ($row = $stmt->fetch()) {
                        $content = json_decode($row['content'], true);
                        $results[] = [
                            'id' => $row['id'],
                            'score' => abs($row['rank'] ?? 0),
                            'document' => $content,
                            'metadata' => json_decode($row['metadata'], true),
                            'language' => $row['language'],
                            'type' => $row['type'],
                            'timestamp' => $row['timestamp'],
                            'distance' => (float)($row['distance'] ?? 0),
                            'centroid_lat' => $row['_centroid_lat'] ?? null,
                            'centroid_lng' => $row['_centroid_lng'] ?? null,
                        ];
                    }
                    
                    // Cache the results before returning
                    if ($this->queryCache) {
                        $this->queryCache->set($index, $query, $results);
                    }
                    
                    return $results;
                } catch (\PDOException $e) {
                    throw new StorageException("Search failed: " . $e->getMessage());
                }
            }
        }

        if ($hasSearchQuery) {
            // Full text search with optional spatial filters
            // Compute bm25 weights per FTS column (if available)
            $ftsColumns = $this->getFtsColumns($index);
            $isMultiColumn = $this->getIndexMeta($index, 'multi_column_fts') === '1';
            
            $weights = [];
            if ($isMultiColumn && !empty($fieldWeights)) {
                // In multi-column mode, apply weights to actual FTS columns
                foreach ($ftsColumns as $col) {
                    $weights[] = (float)($fieldWeights[$col] ?? 1.0);
                }
            } else if (!$isMultiColumn && !empty($fieldWeights)) {
                // In single-column mode, BM25 weights don't apply per field
                // We'll use post-processing field weighting
                $weights = [1.0];
            } else {
                // No field weights specified
                foreach ($ftsColumns as $col) {
                    $weights[] = 1.0;
                }
            }
            
            // Use table name for bm25() (SQLite expects the FTS table name)
            $bm25 = 'bm25(' . $index . '_fts' . (count($weights) ? ', ' . implode(', ', $weights) : '') . ') as rank';
            $schema = $this->getSchemaMode($index);
            if ($schema === 'external') {
                $inner = "SELECT d.*, {$bm25}" . $spatial['select'] . " FROM {$index} d INNER JOIN {$index}_fts f ON f.rowid = d.doc_id" . $spatial['join'] . " WHERE {$index}_fts MATCH ?" . $spatial['where'];
            } else {
                $inner = "SELECT d.*, {$bm25}" . $spatial['select'] . " FROM {$index} d INNER JOIN {$index}_fts f ON d.id = f.id" . $spatial['join'] . " WHERE {$index}_fts MATCH ?" . $spatial['where'];
            }
            $params = array_merge([$searchQuery], $spatial['params']);
            if (isset($geoFilters['near']) && !empty($spatial['select']) && strpos($spatial['select'], 'distance') !== false) {
                $radius = (float)$geoFilters['near']['radius'];
                $units = $geoFilters['units'] ?? ($this->searchConfig['geo_units'] ?? null);
                if (is_string($units)) { $u=strtolower($units); if ($u==='km') $radius*=1000.0; elseif(in_array($u,['mi','mile','miles'])) $radius*=1609.344; }
                $sql = "SELECT * FROM (" . $inner . ") t WHERE t.distance <= ?";
                $params[] = $radius;
            } else {
                $sql = $inner;
            }
        } else {
            // No text search, only filters and/or spatial search
            $sql = "
                SELECT 
                    d.id, d.content, d.metadata, d.language, d.type, d.timestamp,
                    0 as rank" . $spatial['select'] . "
                FROM {$index} d" . 
                (!empty($spatial['join']) ? $spatial['join'] : "") . "
                WHERE 1=1" . $spatial['where'] . "
            ";
            $params = $spatial['params'];
        }
        
        
        if ($language) {
            $sql .= " AND d.language = ?";
            $params[] = $language;
        }
        
        foreach ($filters as $filter) {
            $field = $filter['field'];
            $operator = $filter['operator'] ?? '=';
            $value = $filter['value'];
            
            if (in_array($field, ['type', 'language', 'id', 'timestamp'])) {
                // Direct column filtering
                $sql .= " AND d.{$field} {$operator} ?";
                $params[] = $value;
            } elseif (strpos($field, 'metadata.') === 0) {
                // Metadata field filtering using JSON extraction
                $metaField = substr($field, 9); // Remove 'metadata.' prefix
                
                // Use SQLite's JSON extraction
                switch ($operator) {
                    case '=':
                        $sql .= " AND json_extract(d.metadata, '$.{$metaField}') = ?";
                        break;
                    case '!=':
                        $sql .= " AND json_extract(d.metadata, '$.{$metaField}') != ?";
                        break;
                    case '>':
                        $sql .= " AND CAST(json_extract(d.metadata, '$.{$metaField}') AS REAL) > ?";
                        break;
                    case '<':
                        $sql .= " AND CAST(json_extract(d.metadata, '$.{$metaField}') AS REAL) < ?";
                        break;
                    case '>=':
                        $sql .= " AND CAST(json_extract(d.metadata, '$.{$metaField}') AS REAL) >= ?";
                        break;
                    case '<=':
                        $sql .= " AND CAST(json_extract(d.metadata, '$.{$metaField}') AS REAL) <= ?";
                        break;
                    case 'in':
                        if (is_array($value)) {
                            $placeholders = implode(',', array_fill(0, count($value), '?'));
                            $sql .= " AND json_extract(d.metadata, '$.{$metaField}') IN ({$placeholders})";
                            $params = array_merge($params, $value);
                            continue 2; // Skip adding single value to params
                        }
                        break;
                    case 'contains':
                        $sql .= " AND json_extract(d.metadata, '$.{$metaField}') LIKE ?";
                        $value = '%' . $value . '%';
                        break;
                    case 'exists':
                        $sql .= " AND json_extract(d.metadata, '$.{$metaField}') IS NOT NULL";
                        continue 2; // No value needed
                }
                $params[] = $value;
            }
        }
        
        // Handle sorting
        if ($needsPhpSort) {
            // For PHP sorting, we need to fetch more results to ensure accurate pagination
            // Dynamic default: 20x limit (min 200), capped at 1000; override with candidate_cap
            $fetchLimit = min(1000, max($limit * 20, 200));
            if (isset($geoFilters['candidate_cap'])) {
                $fetchLimit = min($fetchLimit, max(10, (int)$geoFilters['candidate_cap']));
            }
            
            // Apply basic ordering by rank to get most relevant results first
            $sql .= " ORDER BY rank ASC";
            $sql .= " LIMIT ?";
            $params[] = $fetchLimit;
        } else {
            // Normal SQL sorting
            if (isset($geoFilters['distance_sort']) && !empty($spatial['select']) && strpos($spatial['select'], 'distance') !== false) {
                $dir = strtoupper($geoFilters['distance_sort']['direction']) === 'DESC' ? 'DESC' : 'ASC';
                $sql .= " ORDER BY distance {$dir}";
            } elseif (empty($sort)) {
                $sql .= " ORDER BY rank ASC";
            } else {
                $orderClauses = [];
                foreach ($sort as $field => $direction) {
                    $dir = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
                    if ($field === '_score') {
                        $orderClauses[] = "rank {$dir}";
                    } elseif ($field === 'distance' && !empty($spatial['select'])) {
                        $orderClauses[] = "distance {$dir}";
                    } elseif (in_array($field, ['type', 'language', 'id', 'timestamp'])) {
                        // Direct columns
                        $orderClauses[] = "d.{$field} {$dir}";
                    } elseif (strpos($field, 'metadata.') === 0) {
                        // Metadata field - use JSON extraction
                        $metaField = substr($field, 9);
                        $orderClauses[] = "CAST(json_extract(d.metadata, '$.{$metaField}') AS REAL) {$dir}";
                    } else {
                        // Assume it's a direct column (for backward compatibility)
                        $orderClauses[] = "d.{$field} {$dir}";
                    }
                }
                $sql .= " ORDER BY " . implode(', ', $orderClauses);
            }
            
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = $effectiveLimit;
            $params[] = $offset;
        }
        
        try {
            // Debug mode: return SQL and params without executing
            if (!empty($query['_debug_sql'])) {
                return [
                    '_sql' => $sql,
                    '_params' => $params,
                    '_uses_php_sort' => $needsPhpSort,
                    '_spatial_select' => $spatial['select'],
                ];
            }

            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            
            $results = [];
            $radiusFilter = null;
            
            // Check if we're using multi-column FTS
            $isMultiColumn = $this->getIndexMeta($index, 'multi_column_fts') === '1';
            
            // Check if we need to do radius post-filtering (ensure meters)
            if (isset($geoFilters['near'])) {
                $radiusFilter = (float)$geoFilters['near']['radius'];
                $units = $geoFilters['units'] ?? ($this->searchConfig['geo_units'] ?? null);
                if (is_string($units)) {
                    $u = strtolower($units);
                    if ($u === 'km') { $radiusFilter *= 1000.0; }
                    elseif ($u === 'mi' || $u === 'mile' || $u === 'miles') { $radiusFilter *= 1609.344; }
                }
            }
            
            $rowCount = 0;
            // Determine a reference point for distance if needed
            $refPoint = null;
            if (isset($geoFilters['distance_sort']['from'])) {
                $from = $geoFilters['distance_sort']['from'];
                $refPoint = is_array($from) ? new GeoPoint($from['lat'], $from['lng']) : $from;
            } elseif (isset($geoFilters['near']['point'])) {
                $from = $geoFilters['near']['point'];
                $refPoint = is_array($from) ? new GeoPoint($from['lat'], $from['lng']) : $from;
            }

            while ($row = $stmt->fetch()) {
                $rowCount++;
                $content = json_decode($row['content'], true);
                $baseScore = abs($row['rank']);
                
                // Apply field weights if provided
                // Even in multi-column mode, we should apply additional weighting for exact matches
                if (!empty($fieldWeights) && $hasSearchQuery && is_array($content)) {
                    // Apply post-processing field weights for better exact match scoring
                    $weightedScore = $this->calculateFieldWeightedScore($searchQuery, $content, $fieldWeights, $baseScore);
                    $finalScore = $weightedScore;
                    
                } else {
                    // No field weights configured
                    $finalScore = $baseScore;
                }
                
                // Merge content fields directly into result
                $result = array_merge($content ?: [], [
                    'id' => $row['id'],
                    'score' => $finalScore,
                    'metadata' => json_decode($row['metadata'], true),
                    'language' => $row['language'],
                    'type' => $row['type'],
                    'timestamp' => $row['timestamp']
                ]);
                
                // Add or compute distance
                $distance = null;
                if (isset($row['distance'])) {
                    $distance = (float)$row['distance'];
                } elseif ($refPoint && isset($row['_centroid_lat'], $row['_centroid_lng'])) {
                    $pt = new GeoPoint((float)$row['_centroid_lat'], (float)$row['_centroid_lng']);
                    $distance = $refPoint->distanceTo($pt);
                }
                if ($distance !== null) {
                    if ($radiusFilter !== null && $distance > $radiusFilter) { continue; }
                    $result['distance'] = $distance;
                }
                
                $results[] = $result;
            }
            
            // Re-sort results if field weights were applied (only in single-column mode)
            if (!$isMultiColumn && !empty($fieldWeights) && $hasSearchQuery) {
                usort($results, function($a, $b) {
                    // Sort by score descending (highest score first)
                    return $b['score'] <=> $a['score'];
                });
                
                // Apply the actual limit after sorting
                $results = array_slice($results, 0, $limit);
            }
            
            // Apply PHP sorting if needed
            if ($needsPhpSort) {
                // Determine sort field and direction
                $sortField = 'distance';
                $sortDir = 'ASC';
                
                if (isset($geoFilters['distance_sort'])) {
                    $sortDir = strtoupper($geoFilters['distance_sort']['direction']) === 'DESC' ? 'DESC' : 'ASC';
                } elseif (isset($sort['distance'])) {
                    $sortDir = strtoupper($sort['distance']) === 'DESC' ? 'DESC' : 'ASC';
                }
                
                // Sort results by distance
                usort($results, function($a, $b) use ($sortDir) {
                    $distA = $a['distance'] ?? PHP_FLOAT_MAX;
                    $distB = $b['distance'] ?? PHP_FLOAT_MAX;
                    
                    if ($sortDir === 'ASC') {
                        return $distA <=> $distB;
                    } else {
                        return $distB <=> $distA;
                    }
                });
                
                // Apply pagination after sorting
                $results = array_slice($results, $offset, $limit);
            }
            
            // Cache the results before returning
            if ($this->queryCache) {
                $this->queryCache->set($index, $query, $results);
            }
            
            return $results;
        } catch (\PDOException $e) {
            throw new StorageException("Search failed: " . $e->getMessage());
        }
    }
    
    public function count(string $index, array $query): int
    {
        $this->ensureConnected();
        
        $searchQuery = $query['query'] ?? '';
        $filters = $query['filters'] ?? [];
        $language = $query['language'] ?? null;
        $geoFilters = $query['geoFilters'] ?? [];
        
        // Build spatial query components
        $spatial = $this->buildSpatialQuery($index, $geoFilters);
        
        // Check if we have a search query
        $hasSearchQuery = !empty(trim($searchQuery));
        
        if ($hasSearchQuery) {
            $schema = $this->getSchemaMode($index);
            if ($schema === 'external') {
                $inner = "SELECT d.id" . $spatial['select'] . " FROM {$index} d INNER JOIN {$index}_fts f ON f.rowid = d.doc_id" . $spatial['join'] . " WHERE {$index}_fts MATCH ?" . $spatial['where'];
            } else {
                $inner = "SELECT d.id" . $spatial['select'] . " FROM {$index} d INNER JOIN {$index}_fts f ON d.id = f.id" . $spatial['join'] . " WHERE {$index}_fts MATCH ?" . $spatial['where'];
            }
            $params = array_merge([$searchQuery], $spatial['params']);

            // Apply language and filters inside the inner query (so columns/JSON are available)
            if ($language) {
                $inner .= " AND d.language = ?";
                $params[] = $language;
            }
            foreach ($filters as $filter) {
                $field = $filter['field'];
                $operator = $filter['operator'] ?? '=';
                $value = $filter['value'];
                if (in_array($field, ['type','language','id','timestamp'])) {
                    $inner .= " AND d.{$field} {$operator} ?";
                    $params[] = $value;
                } elseif (strpos($field, 'metadata.') === 0) {
                    $metaField = substr($field, 9);
                    switch ($operator) {
                        case '=':     $inner .= " AND json_extract(d.metadata, '$.{$metaField}') = ?"; break;
                        case '!=':    $inner .= " AND json_extract(d.metadata, '$.{$metaField}') != ?"; break;
                        case '>':     $inner .= " AND CAST(json_extract(d.metadata, '$.{$metaField}') AS REAL) > ?"; break;
                        case '<':     $inner .= " AND CAST(json_extract(d.metadata, '$.{$metaField}') AS REAL) < ?"; break;
                        case '>=':    $inner .= " AND CAST(json_extract(d.metadata, '$.{$metaField}') AS REAL) >= ?"; break;
                        case '<=':    $inner .= " AND CAST(json_extract(d.metadata, '$.{$metaField}') AS REAL) <= ?"; break;
                        case 'in':
                            if (is_array($value)) {
                                $placeholders = implode(',', array_fill(0, count($value), '?'));
                                $inner .= " AND json_extract(d.metadata, '$.{$metaField}') IN ({$placeholders})";
                                $params = array_merge($params, $value);
                                continue 2;
                            }
                            break;
                        case 'contains':
                            $inner .= " AND json_extract(d.metadata, '$.{$metaField}') LIKE ?";
                            $value = '%' . $value . '%';
                            break;
                        case 'exists':
                            $inner .= " AND json_extract(d.metadata, '$.{$metaField}') IS NOT NULL";
                            $value = null; // will be skipped by continue 2
                            $params = $params; // no-op to keep style
                            continue 2;
                    }
                    $params[] = $value;
                }
            }

            if (isset($geoFilters['near']) && !empty($spatial['select']) && strpos($spatial['select'], 'distance') !== false) {
                $radius = (float)$geoFilters['near']['radius'];
                $units = $geoFilters['units'] ?? ($this->searchConfig['geo_units'] ?? null);
                if (is_string($units)) { $u=strtolower($units); if ($u==='km') $radius*=1000.0; elseif(in_array($u,['mi','mile','miles'])) $radius*=1609.344; }
                $sql = "SELECT COUNT(*) as total FROM (" . $inner . ") t WHERE t.distance <= ?";
                $params[] = $radius;
            } else {
                $sql = "SELECT COUNT(*) as total FROM (" . $inner . ") t";
            }
        } else {
            $sql = "
                SELECT COUNT(*) as total
                FROM {$index} d" . 
                (!empty($spatial['join']) ? $spatial['join'] : "") . "
                WHERE 1=1" . $spatial['where'] . "
            ";
            $params = $spatial['params'];
            if ($language) {
                $sql .= " AND d.language = ?";
                $params[] = $language;
            }
            foreach ($filters as $filter) {
                $field = $filter['field'];
                $operator = $filter['operator'] ?? '=';
                $value = $filter['value'];
                if (in_array($field, ['type','language','id','timestamp'])) {
                    $sql .= " AND d.{$field} {$operator} ?";
                    $params[] = $value;
                } elseif (strpos($field, 'metadata.') === 0) {
                    $metaField = substr($field, 9);
                    switch ($operator) {
                        case '=':     $sql .= " AND json_extract(d.metadata, '$.{$metaField}') = ?"; break;
                        case '!=':    $sql .= " AND json_extract(d.metadata, '$.{$metaField}') != ?"; break;
                        case '>':     $sql .= " AND CAST(json_extract(d.metadata, '$.{$metaField}') AS REAL) > ?"; break;
                        case '<':     $sql .= " AND CAST(json_extract(d.metadata, '$.{$metaField}') AS REAL) < ?"; break;
                        case '>=':    $sql .= " AND CAST(json_extract(d.metadata, '$.{$metaField}') AS REAL) >= ?"; break;
                        case '<=':    $sql .= " AND CAST(json_extract(d.metadata, '$.{$metaField}') AS REAL) <= ?"; break;
                        case 'in':
                            if (is_array($value)) {
                                $placeholders = implode(',', array_fill(0, count($value), '?'));
                                $sql .= " AND json_extract(d.metadata, '$.{$metaField}') IN ({$placeholders})";
                                $params = array_merge($params, $value);
                                continue 2;
                            }
                            break;
                        case 'contains':
                            $sql .= " AND json_extract(d.metadata, '$.{$metaField}') LIKE ?";
                            $value = '%' . $value . '%';
                            break;
                        case 'exists':
                            $sql .= " AND json_extract(d.metadata, '$.{$metaField}') IS NOT NULL";
                            continue 2;
                    }
                    $params[] = $value;
                }
            }
        }
        
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            
            return (int) $stmt->fetchColumn();
        } catch (\PDOException $e) {
            throw new StorageException("Count failed: " . $e->getMessage());
        }
    }
    
    public function getDocument(string $index, string $id): ?array
    {
        $this->ensureConnected();
        
        $stmt = $this->connection->prepare("SELECT * FROM {$index} WHERE id = ?");
        $stmt->execute([$id]);
        
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        
        return [
            'id' => $row['id'],
            'content' => json_decode($row['content'], true),
            'metadata' => json_decode($row['metadata'], true),
            'language' => $row['language'],
            'type' => $row['type'],
            'timestamp' => $row['timestamp']
        ];
    }
    
    public function optimize(string $index): void
    {
        $this->ensureConnected();
        
        try {
            $this->connection->exec("INSERT INTO {$index}_fts({$index}_fts) VALUES('optimize')");
            $this->connection->exec("VACUUM");
            $this->connection->exec("ANALYZE");
        } catch (\PDOException $e) {
            throw new StorageException("Optimization failed: " . $e->getMessage());
        }
    }
    
    public function getIndexStats(string $index): array
    {
        $this->ensureConnected();
        
        $stats = [
            'document_count' => 0,
            'index_size' => 0,
            'languages' => [],
            'types' => []
        ];
        
        try {
            $stmt = $this->connection->query("SELECT COUNT(*) FROM {$index}");
            $stats['document_count'] = (int) $stmt->fetchColumn();
            
            $stmt = $this->connection->query("
                SELECT language, COUNT(*) as count 
                FROM {$index} 
                WHERE language IS NOT NULL 
                GROUP BY language
            ");
            while ($row = $stmt->fetch()) {
                $stats['languages'][$row['language']] = $row['count'];
            }
            
            $stmt = $this->connection->query("
                SELECT type, COUNT(*) as count 
                FROM {$index} 
                GROUP BY type
            ");
            while ($row = $stmt->fetch()) {
                $stats['types'][$row['type']] = $row['count'];
            }
            
        } catch (\PDOException $e) {
            // Ignore stats errors
        }
        
        return $stats;
    }

    /**
     * Migrate a legacy index (string id PK) to external-content/doc_id schema.
     * - Creates a docs2 table with INTEGER PRIMARY KEY doc_id and UNIQUE id
     * - Copies all rows, swaps tables, sets schema_mode meta to 'external'
     * - Recreates spatial table without id_map
     * - Rebuilds FTS content
     */
    public function migrateToExternalContent(string $index): void
    {
        $this->ensureConnected();
        try {
            $this->connection->beginTransaction();

            // Create new table with doc_id
            $this->connection->exec("CREATE TABLE IF NOT EXISTS {$index}_docs2 (doc_id INTEGER PRIMARY KEY, id TEXT UNIQUE, content TEXT NOT NULL, metadata TEXT, language TEXT, type TEXT DEFAULT 'default', timestamp INTEGER)");
            $this->connection->exec("INSERT INTO {$index}_docs2 (id, content, metadata, language, type, timestamp) SELECT id, content, metadata, language, type, timestamp FROM {$index}");

            // Swap tables
            $this->connection->exec("ALTER TABLE {$index} RENAME TO {$index}_old");
            $this->connection->exec("ALTER TABLE {$index}_docs2 RENAME TO {$index}");

            // Ensure meta table and set schema mode
            $this->connection->exec("CREATE TABLE IF NOT EXISTS {$index}_meta (key TEXT PRIMARY KEY, value TEXT)");
            $this->setIndexMeta($index, 'schema_mode', 'external');

            // Recreate spatial; drop legacy id_map
            $this->connection->exec("DROP TABLE IF EXISTS {$index}_spatial");
            $this->connection->exec("DROP TABLE IF EXISTS {$index}_id_map");
            if ($this->hasRTreeSupport()) {
                $this->connection->exec("CREATE VIRTUAL TABLE {$index}_spatial USING rtree(id, minLat, maxLat, minLng, maxLng)");
            } else {
                $this->connection->exec("CREATE TABLE {$index}_spatial (id INTEGER PRIMARY KEY, minLat REAL, maxLat REAL, minLng REAL, maxLng REAL)");
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw new StorageException('Migration to external-content failed: ' . $e->getMessage());
        }

        // Rebuild FTS after structure change
        $this->rebuildFts($index);
    }
    
    public function listIndices(): array
    {
        $this->ensureConnected();
        
        $indices = [];
        
        try {
            // Query for all tables that match our index pattern
            $stmt = $this->connection->query("
                SELECT name 
                FROM sqlite_master 
                WHERE type = 'table' 
                AND name NOT LIKE '%_fts' 
                AND name NOT LIKE '%_terms'
                AND name NOT LIKE 'sqlite_%'
                ORDER BY name
            ");
            
            while ($row = $stmt->fetch()) {
                $tableName = $row['name'];
                
                // Verify this is a valid index by checking for corresponding FTS table
                $ftsExists = $this->connection->query("
                    SELECT COUNT(*) 
                    FROM sqlite_master 
                    WHERE type = 'table' 
                    AND name = '{$tableName}_fts'
                ")->fetchColumn() > 0;
                
                if ($ftsExists) {
                    // Get additional metadata about the index
                    $stats = $this->getIndexStats($tableName);
                    $indices[] = [
                        'name' => $tableName,
                        'document_count' => $stats['document_count'],
                        'languages' => array_keys($stats['languages']),
                        'types' => array_keys($stats['types'])
                    ];
                }
            }
            
        } catch (\PDOException $e) {
            throw new StorageException("Failed to list indices: " . $e->getMessage());
        }
        
        return $indices;
    }
    
    public function getStats(string $index): array
    {
        return $this->getIndexStats($index);
    }
    
    public function clear(string $index): void
    {
        $this->ensureConnected();
        
        try {
            $this->connection->beginTransaction();
            
            // Delete all documents from the index
            $this->connection->exec("DELETE FROM {$index}");
            
            // Delete from FTS table
            $this->connection->exec("DELETE FROM {$index}_fts");
            
            // Delete from spatial table if exists
            if ($this->hasSpatialIndex($index)) {
                $this->connection->exec("DELETE FROM {$index}_spatial");
            }
            
            // Delete from terms table if exists
            if ($this->useTermsIndex) {
                $this->connection->exec("DELETE FROM {$index}_terms");
            }
            
            $this->connection->commit();
        } catch (\PDOException $e) {
            $this->connection->rollBack();
            throw new StorageException("Failed to clear index: " . $e->getMessage());
        }
    }
    
    public function searchMultiple(array $indices, array $query): array
    {
        $this->ensureConnected();
        
        $allResults = [];
        $totalCount = 0;
        $searchTime = microtime(true);
        
        // Search each index
        foreach ($indices as $indexName) {
            if (!$this->indexExists($indexName)) {
                continue;
            }
            
            try {
                $results = $this->search($indexName, $query);
                
                // Add index name to each result
                foreach ($results as &$result) {
                    $result['_index'] = $indexName;
                }
                
                $allResults = array_merge($allResults, $results);
                
            } catch (\Exception $e) {
                // Log error but continue with other indices
                // In production, you might want to log this error
                continue;
            }
        }
        
        // Sort merged results by score/rank
        usort($allResults, function($a, $b) {
            $scoreA = $a['rank'] ?? $a['_score'] ?? 0;
            $scoreB = $b['rank'] ?? $b['_score'] ?? 0;
            return $scoreB <=> $scoreA; // Descending order
        });
        
        // Apply limit and offset to merged results
        $limit = $query['limit'] ?? 20;
        $offset = $query['offset'] ?? 0;
        $allResults = array_slice($allResults, $offset, $limit);
        
        // Calculate search time
        $searchTime = round((microtime(true) - $searchTime) * 1000, 2);
        
        // Return results in a format similar to single index search
        return [
            'results' => $allResults,
            'total' => count($allResults),
            'search_time' => $searchTime,
            'indices_searched' => array_filter($indices, [$this, 'indexExists'])
        ];
    }
    
    private function ensureConnected(): void
    {
        if ($this->connection === null) {
            throw new StorageException("Not connected to database");
        }
    }
    
    private function hasSpatialIndex(string $index): bool
    {
        if (!$this->connection) {
            return false;
        }
        
        try {
            $stmt = $this->connection->prepare(
                "SELECT name FROM sqlite_master WHERE type='table' AND name=?"
            );
            $stmt->execute([$index . '_spatial']);
            return $stmt->fetch() !== false;
        } catch (\PDOException $e) {
            return false;
        }
    }
    
    private function getPreparedStatement(string $sql): \PDOStatement
    {
        $key = md5($sql);
        
        // Check cache first
        if ($this->stmtCache) {
            $stmt = $this->stmtCache->get($key);
            if ($stmt !== null) {
                return $stmt;
            }
        }
        
        // Prepare new statement
        $stmt = $this->connection->prepare($sql);
        
        // Cache it
        if ($this->stmtCache) {
            $this->stmtCache->set($key, $stmt);
        }
        
        return $stmt;
    }
    
    private function initializeDatabase(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS yetisearch_metadata (
                key TEXT PRIMARY KEY,
                value TEXT,
                updated_at INTEGER DEFAULT (strftime('%s', 'now'))
            )
        ";
        $this->connection->exec($sql);
    }

    private function setIndexMeta(string $index, string $key, string $value): void
    {
        $stmt = $this->connection->prepare("INSERT OR REPLACE INTO {$index}_meta (key, value) VALUES (?, ?)");
        $stmt->execute([$key, $value]);
    }

    private function getIndexMeta(string $index, string $key): ?string
    {
        try {
            $stmt = $this->connection->prepare("SELECT value FROM {$index}_meta WHERE key = ?");
            $stmt->execute([$key]);
            $row = $stmt->fetch();
            return $row ? $row['value'] : null;
        } catch (\PDOException $e) {
            return null;
        }
    }

    private function getSchemaMode(string $index): string
    {
        $mode = $this->getIndexMeta($index, 'schema_mode');
        if ($mode === 'external' || $mode === 'legacy') {
            return $mode;
        }
        return $this->externalContentDefault ? 'external' : 'legacy';
    }

    private function getDocId(string $index, string $stringId): ?int
    {
        try {
            $stmt = $this->connection->prepare("SELECT doc_id FROM {$index} WHERE id = ?");
            $stmt->execute([$stringId]);
            $val = $stmt->fetchColumn();
            if ($val === false || $val === null) { return null; }
            return (int)$val;
        } catch (\PDOException $e) {
            return null;
        }
    }

    public function rebuildFts(string $index): void
    {
        $this->ensureConnected();
        $schema = $this->getSchemaMode($index);
        $ftsColumns = $this->getFtsColumns($index);
        $prefix = $this->searchConfig['fts_prefix'] ?? null;
        $prefixSql = '';
        if (is_array($prefix) && !empty($prefix)) {
            $prefixSql = ", prefix='" . implode(' ', array_map('intval', $prefix)) . "'";
        }
        $cols = implode(', ', array_map(fn($c) => $c, $ftsColumns));
        $this->connection->exec("DROP TABLE IF EXISTS {$index}_fts");
        if ($schema === 'external') {
            $sql = "CREATE VIRTUAL TABLE {$index}_fts USING fts5({$cols}, content='{$index}', content_rowid='doc_id', tokenize='unicode61'{$prefixSql})";
        } else {
            $sql = "CREATE VIRTUAL TABLE {$index}_fts USING fts5(id UNINDEXED, {$cols}, tokenize='unicode61'{$prefixSql})";
        }
        $this->connection->exec($sql);

        // Repopulate from stored docs
        $stmt = $this->connection->query("SELECT id, content FROM {$index}");
        $insCols = ($schema === 'external' ? 'rowid, ' : 'id, ') . implode(', ', $ftsColumns);
        $placeholders = implode(', ', array_fill(0, count($ftsColumns) + 1, '?'));
        $ins = $this->connection->prepare("INSERT INTO {$index}_fts ({$insCols}) VALUES ({$placeholders})");
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $id = $row['id'];
            $doc = json_decode($row['content'], true) ?: [];
            $vals = [];
            if ($schema === 'external') {
                $docId = $this->getDocId($index, $id);
                if ($docId === null) { continue; }
                $vals[] = $docId;
            } else {
                $vals[] = $id;
            }
            foreach ($ftsColumns as $col) {
                $vals[] = $this->getFieldText($doc, $col, $index);
            }
            $ins->execute($vals);
        }
    }

    private function getFtsColumns(string $index): array
    {
        if (isset($this->ftsColumnsCache[$index])) {
            return $this->ftsColumnsCache[$index];
        }
        $value = $this->getIndexMeta($index, 'fts_columns');
        if ($value) {
            $cols = json_decode($value, true) ?: ['content'];
        } else {
            $cols = ['content'];
        }
        return $this->ftsColumnsCache[$index] = $cols;
    }

    private function isSpatialEnabled(string $index): bool
    {
        if (array_key_exists($index, $this->spatialEnabledCache)) {
            return (bool)$this->spatialEnabledCache[$index];
        }
        $val = $this->getIndexMeta($index, 'spatial_enabled');
        return $this->spatialEnabledCache[$index] = ($val === null ? true : $val === '1');
    }

    //

    private function getFieldText(array $content, string $field, string $index): string
    {
        // Check if we're in multi-column mode
        $isMultiColumn = $this->getIndexMeta($index, 'multi_column_fts') === '1';
        $cols = $this->getFtsColumns($index);
        
        // If in single-column mode (['content']), aggregate all fields
        if (!$isMultiColumn && count($cols) === 1 && $cols[0] === 'content' && $field === 'content') {
            return $this->extractSearchableContent($content);
        }

        // In multi-column mode, return the specific field value
        if (isset($content[$field])) {
            $v = $content[$field];
            if (is_string($v)) return $v;
            if (is_array($v)) return $this->extractSearchableContent($v);
            return (string)$v;
        }
        
        // If field doesn't exist in content, return empty string
        return '';
    }
    
    private function extractSearchableContent(array $content): string
    {
        $searchable = [];
        
        foreach ($content as $field => $value) {
            if (is_string($value)) {
                $searchable[] = $value;
            } elseif (is_array($value)) {
                $searchable[] = $this->extractSearchableContent($value);
            }
        }
        
        return implode(' ', $searchable);
    }
    
    private function indexTerms(string $index, string $documentId, array $content): void
    {
        $deleteStmt = $this->connection->prepare(
            "DELETE FROM {$index}_terms WHERE document_id = ?"
        );
        $deleteStmt->execute([$documentId]);
        
        $insertStmt = $this->connection->prepare("
            INSERT OR REPLACE INTO {$index}_terms 
            (term, document_id, field, frequency, positions)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        foreach ($content as $field => $value) {
            if (!is_string($value)) {
                continue;
            }
            
            $terms = $this->tokenizeText($value);
            $termFrequencies = array_count_values($terms);
            
            foreach ($termFrequencies as $term => $frequency) {
                $positions = $this->findTermPositions($value, $term);
                $insertStmt->execute([
                    $term,
                    $documentId,
                    $field,
                    $frequency,
                    json_encode($positions)
                ]);
            }
        }
    }
    
    private function tokenizeText(string $text): array
    {
        $text = strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $tokens = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        
        return array_filter($tokens, function ($token) {
            return strlen($token) > 2;
        });
    }
    
    private function findTermPositions(string $text, string $term): array
    {
        $positions = [];
        $offset = 0;
        
        while (($pos = stripos($text, $term, $offset)) !== false) {
            $positions[] = $pos;
            $offset = $pos + 1;
        }
        
        return $positions;
    }
    
    private function indexSpatialData(string $index, string $id, array $document): void
    {
        // Skip if spatial disabled via config
        if (!$this->isSpatialEnabled($index)) {
            return;
        }
        // Ensure spatial table exists (handles both R-tree and fallback)
        $this->ensureSpatialTableExists($index);
        
        // Fast-path exit: if no geo/bounds, skip any ID computation or deletes
        $hasGeo = isset($document['geo']) || isset($document['geo_bounds']);
        if (!$hasGeo) {
            return;
        }

        // Determine spatial key only when needed
        $schema = $this->getSchemaMode($index);
        if ($schema === 'external') {
            $docId = $this->getDocId($index, $id);
            if ($docId === null) { return; }
            $spatialId = (int)$docId;
        } else {
            // R-tree requires integer IDs, so we create a numeric hash of the string ID
            $spatialId = $this->getNumericId($id);
        }

        // Delete any existing spatial data for this document
        $deleteStmt = $this->connection->prepare("DELETE FROM {$index}_spatial WHERE id = ?");
        $deleteStmt->execute([$spatialId]);
        
        
        $minLat = $maxLat = $minLng = $maxLng = null;
        
        // Handle point data
        if (isset($document['geo']) && is_array($document['geo'])) {
            $geo = $document['geo'];
            if (isset($geo['lat']) && isset($geo['lng'])) {
                $minLat = $maxLat = (float)$geo['lat'];
                $minLng = $maxLng = (float)$geo['lng'];
            }
        }
        
        // Handle bounds data (overrides point if both present)
        if (isset($document['geo_bounds']) && is_array($document['geo_bounds'])) {
            $bounds = $document['geo_bounds'];
            if (isset($bounds['north']) && isset($bounds['south']) && 
                isset($bounds['east']) && isset($bounds['west'])) {
                $minLat = (float)$bounds['south'];
                $maxLat = (float)$bounds['north'];
                $minLng = (float)$bounds['west'];
                $maxLng = (float)$bounds['east'];
            }
        }
        
        // Insert into spatial index if we have valid coordinates
        if ($minLat !== null && $maxLat !== null && $minLng !== null && $maxLng !== null) {
            
            // Store the ID mapping (legacy schema only)
            if ($schema !== 'external') {
                $mapStmt = $this->connection->prepare("
                    INSERT OR REPLACE INTO {$index}_id_map (string_id, numeric_id)
                    VALUES (?, ?)
                ");
                $mapStmt->execute([$id, $spatialId]);
            }
            
            // Insert spatial data (works for both R-tree and fallback table)
            $spatialStmt = $this->connection->prepare("
                INSERT OR REPLACE INTO {$index}_spatial (id, minLat, maxLat, minLng, maxLng)
                VALUES (?, ?, ?, ?, ?)
            ");
            $spatialStmt->execute([$spatialId, $minLat, $maxLat, $minLng, $maxLng]);
        }
    }
    
    private function getNumericId(string $id): int
    {
        // Create a stable numeric ID from string ID using CRC32
        // We use abs() to ensure positive integer for R-tree
        return abs(crc32($id));
    }
    
    private function buildSpatialQuery(string $index, array $geoFilters): array
    {
        $spatialSql = '';
        $spatialParams = [];
        $spatialJoin = '';
        $distanceSelect = '';
        $centroidSelect = '';
        
        // If spatial is disabled entirely, bail out
        if (!$this->isSpatialEnabled($index)) {
            return [ 'join' => '', 'where' => '', 'params' => [], 'select' => '' ];
        }

        // JSON-based fallback when R-tree or SQL math functions are unavailable
        if (!$this->hasRTreeSupport() || !$this->hasMathFunctions) {
            $where = '';
            $params = [];
            $select = '';
            // Prefer using the spatial table we maintain (regular table when no RTree)
            $schema = $this->getSchemaMode($index);
            if ($schema === 'external') {
                $spatialJoin = " LEFT JOIN {$index}_spatial s ON s.id = d.doc_id";
            } else {
                $spatialJoin = " LEFT JOIN {$index}_id_map m ON m.string_id = d.id LEFT JOIN {$index}_spatial s ON s.id = m.numeric_id";
            }
            // Centroid from stored bbox
            $select .= ", ((s.minLat+s.maxLat)/2.0) AS _centroid_lat, ((s.minLng+s.maxLng)/2.0) AS _centroid_lng";

            if (isset($geoFilters['near'])) {
                $near = $geoFilters['near'];
                $point = is_array($near['point']) ? new GeoPoint($near['point']['lat'], $near['point']['lng']) : $near['point'];
                $radius = (float)$near['radius'];
                $units = $geoFilters['units'] ?? ($this->searchConfig['geo_units'] ?? null);
                if (is_string($units)) { $u=strtolower($units); if ($u==='km') $radius*=1000.0; elseif (in_array($u,['mi','mile','miles'])) $radius*=1609.344; }
                // Bounding box approximation with lon scaling by cos(lat)
                $degLat = $radius / 111000.0;
                $latRad = deg2rad($point->getLatitude());
                $cosLat = max(0.000001, cos($latRad));
                $degLon = $radius / (111000.0 * $cosLat);
                $where .= " AND ((s.minLat+s.maxLat)/2.0) BETWEEN ? AND ? AND ((s.minLng+s.maxLng)/2.0) BETWEEN ? AND ?";
                $params[] = $point->getLatitude() - $degLat;
                $params[] = $point->getLatitude() + $degLat;
                $params[] = $point->getLongitude() - $degLon;
                $params[] = $point->getLongitude() + $degLon;
            }

            if (isset($geoFilters['within'])) {
                $b = $geoFilters['within']['bounds'];
                $bounds = is_array($b) ? GeoBounds::fromArray($b) : $b;
                $north=$bounds->getNorth(); $south=$bounds->getSouth(); $east=$bounds->getEast(); $west=$bounds->getWest();
                // Intersection test using stored bbox
                if ($west > $east) {
                    $where .= " AND ((s.maxLat >= ? AND s.minLat <= ?) AND ((s.minLng <= ? AND s.maxLng >= -180) OR (s.minLng <= 180 AND s.maxLng >= ?)))";
                    array_push($params, $south, $north, $east, $west);
                } else {
                    $where .= " AND (s.maxLat >= ? AND s.minLat <= ? AND s.maxLng >= ? AND s.minLng <= ?)";
                    array_push($params, $south, $north, $east, $west);
                }
            }

            if (isset($geoFilters['distance_sort']) && isset($geoFilters['distance_sort']['from'])) {
                $from = $geoFilters['distance_sort']['from'];
                if (is_array($from)) { $from = new GeoPoint($from['lat'], $from['lng']); }
                // Centroid already selected
                if (isset($geoFilters['max_distance'])) {
                    $maxD = (float)$geoFilters['max_distance'];
                    $u = strtolower($geoFilters['units'] ?? ($this->searchConfig['geo_units'] ?? 'm'));
                    if ($u==='km') $maxD*=1000.0; elseif (in_array($u,['mi','mile','miles'])) $maxD*=1609.344;
                    $degLat = $maxD / 111000.0;
                    $latRad = deg2rad($from->getLatitude());
                    $cosLat = max(0.000001, cos($latRad));
                    $degLon = $maxD / (111000.0 * $cosLat);
                    $where .= " AND ((s.minLat+s.maxLat)/2.0) BETWEEN ? AND ? AND ((s.minLng+s.maxLng)/2.0) BETWEEN ? AND ?";
                    $params[] = $from->getLatitude() - $degLat;
                    $params[] = $from->getLatitude() + $degLat;
                    $params[] = $from->getLongitude() - $degLon;
                    $params[] = $from->getLongitude() + $degLon;
                }
            }

            return [ 'join' => $spatialJoin, 'where' => $where, 'params' => $params, 'select' => $select ];
        }
        
        // Check if we need distance calculation for sorting
        $needsDistance = isset($geoFilters['distance_sort']) && !isset($geoFilters['near']);
        if ($needsDistance && isset($geoFilters['distance_sort']['from'])) {
            $from = $geoFilters['distance_sort']['from'];
            if (is_array($from)) {
                $lat = $from['lat'];
                $lng = $from['lng'];
            } elseif ($from instanceof GeoPoint) {
                $lat = $from->getLatitude();
                $lng = $from->getLongitude();
            } else {
                // Handle other object types that might have lat/lng properties
                $lat = $from->lat ?? $from->getLatitude();
                $lng = $from->lng ?? $from->getLongitude();
            }
            $schema = $this->getSchemaMode($index);
            if ($schema === 'external') {
                $spatialJoin = " LEFT JOIN {$index}_spatial s ON s.id = d.doc_id";
            } else {
                $spatialJoin = " LEFT JOIN {$index}_id_map m ON m.string_id = d.id LEFT JOIN {$index}_spatial s ON s.id = m.numeric_id";
            }
            $distanceSelect = ", " . $this->getDistanceExpression($lat, $lng) . " as distance";
            $centroidSelect = ", ((s.minLat+s.maxLat)/2.0) AS _centroid_lat, ((s.minLng+s.maxLng)/2.0) AS _centroid_lng";

            // Optional max_distance clamp (meters)
            if (isset($geoFilters['max_distance'])) {
                $maxD = (float)$geoFilters['max_distance'];
                $units = $geoFilters['units'] ?? ($this->searchConfig['geo_units'] ?? null);
                if (is_string($units)) {
                    $u = strtolower($units);
                    if ($u === 'km') $maxD *= 1000.0; elseif ($u === 'mi' || $u === 'mile' || $u==='miles') $maxD *= 1609.344;
                }
                $spatialSql .= " AND (" . $this->getDistanceExpression($lat, $lng) . ") <= ?";
                $spatialParams[] = $maxD;
            }
        }
        
        if (isset($geoFilters['near'])) {
            $near = $geoFilters['near'];
            $point = is_array($near['point']) ? new GeoPoint($near['point']['lat'], $near['point']['lng']) : $near['point'];
            $radius = (float)$near['radius'];
            // Units: default meters; support 'km' and 'mi' via per-query or config
            $units = $geoFilters['units'] ?? ($this->searchConfig['geo_units'] ?? null);
            if (is_string($units)) {
                $u = strtolower($units);
                if ($u === 'km') {
                    $radius *= 1000.0;
                } elseif ($u === 'mi' || $u === 'mile' || $u === 'miles') {
                    $radius *= 1609.344;
                }
            }
            
            // Calculate bounding box for initial R-tree filtering
            $bounds = $point->getBoundingBox($radius);
            
            $schema = $this->getSchemaMode($index);
            if ($schema === 'external') {
                $spatialJoin = " INNER JOIN {$index}_spatial s ON s.id = d.doc_id";
            } else {
                $spatialJoin = " INNER JOIN {$index}_id_map m ON m.string_id = d.id INNER JOIN {$index}_spatial s ON s.id = m.numeric_id";
            }
            
            // R-tree bounding box filter with dateline handling
            $north = $bounds->getNorth();
            $south = $bounds->getSouth();
            $east = $bounds->getEast();
            $west = $bounds->getWest();

            if ($west > $east) {
                // Crosses the antimeridian: split into two longitude ranges
                $spatialSql = " AND s.minLat <= ? AND s.maxLat >= ? AND ((s.minLng <= ? AND s.maxLng >= -180) OR (s.minLng <= 180 AND s.maxLng >= ?))";
                $spatialParams = [$north, $south, $east, $west];
            } else {
                $spatialSql = " AND s.minLat <= ? AND s.maxLat >= ? AND s.minLng <= ? AND s.maxLng >= ?";
                $spatialParams = [$north, $south, $east, $west];
            }
            
            // Add distance calculation for post-filtering and sorting
            $lat = $point->getLatitude();
            $lng = $point->getLongitude();
            $distanceExpr = $this->getDistanceExpression($lat, $lng);
            $distanceSelect = ", " . $distanceExpr . " as distance";
            $centroidSelect = ", ((s.minLat+s.maxLat)/2.0) AS _centroid_lat, ((s.minLng+s.maxLng)/2.0) AS _centroid_lng";
            // Also filter by radius in SQL
            $spatialSql .= " AND (" . $distanceExpr . ") <= ?";
            $spatialParams[] = (float)$radius; // radius expected in meters
        }
        elseif (isset($geoFilters['within'])) {
            $boundsData = $geoFilters['within']['bounds'];
            $bounds = is_array($boundsData) ? GeoBounds::fromArray($boundsData) : $boundsData;
            
            $schema = $this->getSchemaMode($index);
            if ($schema === 'external') {
                $spatialJoin = " INNER JOIN {$index}_spatial s ON s.id = d.doc_id";
            } else {
                $spatialJoin = " INNER JOIN {$index}_id_map m ON m.string_id = d.id INNER JOIN {$index}_spatial s ON s.id = m.numeric_id";
            }
            $centroidSelect = ", ((s.minLat+s.maxLat)/2.0) AS _centroid_lat, ((s.minLng+s.maxLng)/2.0) AS _centroid_lng";
            
            // R-tree intersection query with dateline handling
            $north = $bounds->getNorth();
            $south = $bounds->getSouth();
            $east = $bounds->getEast();
            $west = $bounds->getWest();
            if ($west > $east) {
                $spatialSql = " AND s.minLat <= ? AND s.maxLat >= ? AND ((s.minLng <= ? AND s.maxLng >= -180) OR (s.minLng <= 180 AND s.maxLng >= ?))";
                $spatialParams = [$north, $south, $east, $west];
            } else {
                $spatialSql = " AND s.minLat <= ? AND s.maxLat >= ? AND s.minLng <= ? AND s.maxLng >= ?";
                $spatialParams = [$north, $south, $east, $west];
            }
        }
        
        return [
            'join' => $spatialJoin,
            'where' => $spatialSql,
            'params' => $spatialParams,
            'select' => $distanceSelect . $centroidSelect
        ];
    }
    
    private function getNumericIdExpression(string $field): string
    {
        // For SQLite, we need a different approach since we can't reliably compute CRC32 in SQL
        // We'll use a simple hash based on the first few characters
        // This is less ideal but works for the join
        return "CAST(
            (unicode(substr({$field}, 1, 1)) * 1000000 + 
             unicode(substr({$field}, 2, 1)) * 10000 + 
             unicode(substr({$field}, 3, 1)) * 100 + 
             unicode(substr({$field}, 4, 1)))
        AS INTEGER)";
    }
    
    private function getDistanceExpression(float $lat, float $lng, bool $useTablePrefix = true): string
    {
        // Prefer accurate Haversine if math functions are available; otherwise fallback to planar approx
        // Column references
        if ($useTablePrefix) {
            $minLat = "s.minLat";
            $maxLat = "s.maxLat";
            $minLng = "s.minLng";
            $maxLng = "s.maxLng";
        } else {
            $minLat = "minLat";
            $maxLat = "maxLat";
            $minLng = "minLng";
            $maxLng = "maxLng";
        }

        if ($this->hasMathFunctions) {
            // Haversine on centroid of bbox; returns meters
            $r1 = "(" . $lat . " * (pi()/180.0))";
            $t1 = "(" . $lng . " * (pi()/180.0))";
            $lat2 = "(({$minLat}+{$maxLat})/2.0)";
            $lng2 = "(({$minLng}+{$maxLng})/2.0)";
            $r2 = "(" . $lat2 . " * (pi()/180.0))";
            $t2 = "(" . $lng2 . " * (pi()/180.0))";
            $a = "(pow(sin((" . $r2 . "-" . $r1 . ")/2.0),2) + cos(" . $r1 . ")*cos(" . $r2 . ")*pow(sin((" . $t2 . "-" . $t1 . ")/2.0),2))";
            $distanceKm = "(2.0*6371.0*asin(min(1, sqrt(" . $a . "))))";
            return "(" . $distanceKm . " * 1000.0)";
        }

        // Fallback: planar approx in meters
        $degToKm = 111.12; // km per degree
        return "\n            SQRT(\n                POWER(({$lat} - (({$minLat} + {$maxLat})/2.0)) * {$degToKm}, 2) +\n                POWER(({$lng} - (({$minLng} + {$maxLng})/2.0)) * {$degToKm} * COS((({$minLat} + {$maxLat})/2.0) * 0.0174533), 2)\n            ) * 1000.0\n        ";
    }

    // JSON/column-based distance expression (meters) for fallback without R-tree
    private function getDistanceExpressionForLatLngColumns(float $lat, float $lng, string $latCol, string $lngCol): string
    {
        if ($this->hasMathFunctions) {
            $r1 = "(" . $lat . " * (pi()/180.0))";
            $t1 = "(" . $lng . " * (pi()/180.0))";
            $r2 = "(" . $latCol . " * (pi()/180.0))";
            $t2 = "(" . $lngCol . " * (pi()/180.0))";
            $a  = "(pow(sin((".$r2."-".$r1.")/2.0),2) + cos(".$r1.")*cos(".$r2.")*pow(sin((".$t2."-".$t1.")/2.0),2))";
            $distanceKm = "(2.0*6371.0*asin(min(1, sqrt(".$a."))))";
            return "(".$distanceKm." * 1000.0)";
        }
        // Planar fallback
        $degToKm = 111.12;
        return "\n            SQRT(\n                POWER(({$lat} - ({$latCol})) * {$degToKm}, 2) +\n                POWER(({$lng} - ({$lngCol})) * {$degToKm} * COS(({$latCol}) * 0.0174533), 2)\n            ) * 1000.0\n        ";
    }
    
    public function ensureSpatialTableExists(string $name): void
    {
        $this->ensureConnected();
        
        // Skip if R-tree support is not available
        if (!$this->hasRTreeSupport()) {
            return;
        }
        
        try {
            // Check if spatial table exists
            $stmt = $this->connection->prepare(
                "SELECT name FROM sqlite_master WHERE type='table' AND name=?"
            );
            $stmt->execute(["{$name}_spatial"]);
            
            if ($stmt->fetch() === false) {
                // Create R-tree spatial index if it doesn't exist
                $spatialSql = "
                    CREATE VIRTUAL TABLE IF NOT EXISTS {$name}_spatial USING rtree(
                        id,              -- Document ID (integer required by R-tree)
                        minLat, maxLat,  -- Latitude bounds
                        minLng, maxLng   -- Longitude bounds
                    )
                ";
                $this->connection->exec($spatialSql);
            }
            
            // Also ensure ID mapping table exists (legacy mode only)
            if ($this->getSchemaMode($name) !== 'external') {
                $stmt->execute(["{$name}_id_map"]);
                if ($stmt->fetch() === false) {
                    $mappingSql = "
                        CREATE TABLE IF NOT EXISTS {$name}_id_map (
                            string_id TEXT PRIMARY KEY,
                            numeric_id INTEGER
                        )
                    ";
                    $this->connection->exec($mappingSql);
                    $this->connection->exec("CREATE INDEX IF NOT EXISTS idx_{$name}_id_map_numeric ON {$name}_id_map(numeric_id)");
                }
            }
        } catch (\PDOException $e) {
            throw new StorageException("Failed to ensure spatial table exists for '{$name}': " . $e->getMessage());
        }
    }
    
    private function hasRTreeSupport(): bool
    {
        if ($this->rtreeSupport !== null) {
            return $this->rtreeSupport;
        }
        
        // Ensure we have a connection
        $this->ensureConnected();
        
        try {
            // Try to create a temporary 2D R-tree table to test support
            // A valid R-tree needs id plus min/max for each dimension (5 columns for 2D)
            $this->connection->exec(
                "CREATE VIRTUAL TABLE IF NOT EXISTS test_rtree_support USING rtree(id, minX, maxX, minY, maxY)"
            );
            $this->connection->exec("DROP TABLE IF EXISTS test_rtree_support");
            $this->rtreeSupport = true;
        } catch (\PDOException $e) {
            $this->rtreeSupport = false;
        }
        
        return $this->rtreeSupport;
    }
    
    /**
     * Get all index names from the database
     * 
     * @return array Array of index names
     */
    private function getIndexNames(): array
    {
        $stmt = $this->connection->query("
            SELECT DISTINCT name 
            FROM sqlite_master 
            WHERE type = 'table' 
            AND name NOT LIKE '%_fts' 
            AND name NOT LIKE '%_terms'
            AND name NOT LIKE '%_vocab'
            AND name NOT LIKE '%_spatial'
            AND name NOT LIKE '%_id_map'
            AND name NOT LIKE 'sqlite_%'
            AND name != 'yetisearch_metadata'
        ");
        
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }
    
    /**
     * Get unique indexed terms from the database
     * 
     * @param string|null $indexName Specific index to query, or null for all
     * @param int $minFrequency Minimum frequency threshold
     * @param int $limit Maximum number of terms to return
     * @return array Array of indexed terms
     */
    public function getIndexedTerms(?string $indexName = null, int $minFrequency = 2, int $limit = 10000): array
    {
        $this->ensureConnected();
        
        try {
            if ($indexName && !$this->indexExists($indexName)) {
                return [];
            }
            
            $tables = $indexName ? [$indexName] : $this->getIndexNames();
            $allTerms = [];
            
            foreach ($tables as $table) {
                $termsTable = "{$table}_terms";
                
                // Check if terms table exists
                $stmt = $this->connection->prepare("
                    SELECT name FROM sqlite_master 
                    WHERE type='table' AND name=:table
                ");
                $stmt->execute([':table' => $termsTable]);
                
                if ($stmt->fetch()) {
                    // Use existing terms table (Levenshtein mode)
                    $sql = "
                        SELECT term, COUNT(*) as frequency
                        FROM {$termsTable}
                        GROUP BY term
                        HAVING frequency >= :min_freq
                        ORDER BY frequency DESC
                        LIMIT :limit
                    ";
                    
                    $stmt = $this->connection->prepare($sql);
                    $stmt->execute([
                        ':min_freq' => $minFrequency,
                        ':limit' => $limit
                    ]);
                    
                    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                        $allTerms[$row['term']] = ($allTerms[$row['term']] ?? 0) + $row['frequency'];
                    }
                } else {
                    // No terms table - try FTS5 vocabulary for other fuzzy algorithms
                    $vocabTable = "{$table}_fts_vocab";
                    
                    // Check if vocab table exists
                    $stmt = $this->connection->prepare("
                        SELECT name FROM sqlite_master 
                        WHERE type='table' AND name=:table
                    ");
                    $stmt->execute([':table' => $vocabTable]);
                    
                    if (!$stmt->fetch()) {
                        // Create vocab table if it doesn't exist
                        try {
                            $this->connection->exec("CREATE VIRTUAL TABLE {$vocabTable} USING fts5vocab('{$table}_fts', 'row')");
                        } catch (\PDOException $e) {
                            // Skip if can't create vocab table
                            continue;
                        }
                    }
                    
                    // Query vocab table
                    $sql = "
                        SELECT term, doc as frequency
                        FROM {$vocabTable}
                        WHERE doc >= ?
                        ORDER BY doc DESC
                        LIMIT ?
                    ";
                    
                    $stmt = $this->connection->prepare($sql);
                    $stmt->bindValue(1, $minFrequency, \PDO::PARAM_INT);
                    $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
                    $stmt->execute();
                    
                    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                        $allTerms[$row['term']] = ($allTerms[$row['term']] ?? 0) + $row['frequency'];
                    }
                }
            }
            
            // Sort by frequency and return terms with their frequencies
            arsort($allTerms);
            return array_slice($allTerms, 0, $limit, true);
            
        } catch (\PDOException $e) {
            throw new StorageException("Failed to get indexed terms: " . $e->getMessage());
        }
    }
    
    /**
     * Calculate field-weighted score based on which fields contain the search terms
     */
    private function calculateFieldWeightedScore(string $searchQuery, array $content, array $fieldWeights, float $baseScore): float
    {
        // Handle complex queries with phrases and OR operators
        // Example: ("star wars" OR star OR wars) -> extract both phrase and individual terms
        $searchTerms = [];
        $exactPhrases = [];
        
        // Extract quoted phrases first
        if (preg_match_all('/"([^"]+)"/', $searchQuery, $matches)) {
            foreach ($matches[1] as $phrase) {
                $exactPhrases[] = strtolower($phrase);
            }
        }
        
        // Clean up query to extract base terms (remove NEAR, OR, parentheses, etc.)
        $cleanQuery = preg_replace('/NEAR\([^)]+\)/', '', $searchQuery);
        $cleanQuery = preg_replace('/["\(\)]/', ' ', $cleanQuery);
        $allTerms = array_map('trim', explode(' ', strtolower($cleanQuery)));
        
        // Filter out operators, wildcards and empty terms
        $searchTerms = array_filter($allTerms, function($term) {
            return $term !== 'or' && $term !== 'and' && !empty($term) && strpos($term, '*') === false;
        });
        
        // Remove duplicates
        $searchTerms = array_unique(array_values($searchTerms));
        
        // If no exact phrases were found but we have multiple terms, check for them as phrase
        if (empty($exactPhrases) && count($searchTerms) > 1) {
            $exactPhrases[] = implode(' ', $searchTerms);
        }
        
        $bestFieldScore = 0.0;
        $fieldScores = [];
        
        // Check each field for search terms
        foreach ($fieldWeights as $field => $weight) {
            // Check both top-level and nested content fields
            $fieldValue = null;
            
            // First check if it's a direct field
            if (isset($content[$field]) && is_string($content[$field])) {
                $fieldValue = $content[$field];
            }
            // Then check if it's nested under 'content' (for chunked documents)
            elseif (isset($content['content'][$field]) && is_string($content['content'][$field])) {
                $fieldValue = $content['content'][$field];
            }
            // Also check if the entire content is a nested object with the field
            elseif (isset($content['content']) && is_array($content['content'])) {
                // For nested fields, also check h1, h2, h3 etc
                if (isset($content['content'][$field]) && is_string($content['content'][$field])) {
                    $fieldValue = $content['content'][$field];
                }
            }
            
            if ($fieldValue === null) {
                continue;
            }
            
            $fieldText = strtolower(trim($fieldValue));
            if (empty($fieldText)) {
                continue;
            }
            
            $fieldScore = 0.0;
            $matchType = 'none';
            
            // Check for exact full field match (highest priority)
            $cleanFieldText = trim(preg_replace('/[^\w\s]/', '', $fieldText));
            foreach ($exactPhrases as $phrase) {
                $cleanPhrase = trim(preg_replace('/[^\w\s]/', '', $phrase));
                if ($cleanFieldText === $cleanPhrase) {
                    // Perfect match - massive boost
                    $fieldScore = 100.0;
                    $matchType = 'exact_field';
                    break;
                }
            }
            
            // Check for exact phrase match within field
            if ($matchType === 'none') {
                foreach ($exactPhrases as $phrase) {
                    if (strpos($fieldText, $phrase) !== false) {
                        // Exact phrase found - high boost
                        $fieldScore = 50.0;
                        $matchType = 'exact_phrase';
                        
                        // Additional boost for shorter fields (more relevant)
                        $phraseRatio = strlen($phrase) / strlen($fieldText);
                        if ($phraseRatio > 0.8) {
                            $fieldScore += 20.0; // Phrase is most of the field
                        } elseif ($phraseRatio > 0.5) {
                            $fieldScore += 10.0; // Phrase is significant part of field
                        }
                        break;
                    }
                }
            }
            
            // Check for all individual terms present
            if ($matchType === 'none' && !empty($searchTerms)) {
                $termMatches = 0;
                $termPositions = [];
                
                foreach ($searchTerms as $term) {
                    $pos = strpos($fieldText, $term);
                    if ($pos !== false) {
                        $termMatches++;
                        $termPositions[] = $pos;
                    }
                }
                
                if ($termMatches === count($searchTerms)) {
                    // All terms present
                    $fieldScore = 20.0;
                    $matchType = 'all_terms';
                    
                    // Boost if terms are close together (proximity bonus)
                    if (count($termPositions) > 1) {
                        sort($termPositions);
                        $maxGap = 0;
                        for ($i = 1; $i < count($termPositions); $i++) {
                            $gap = $termPositions[$i] - $termPositions[$i-1];
                            $maxGap = max($maxGap, $gap);
                        }
                        // If all terms within 50 characters, add proximity bonus
                        if ($maxGap < 50) {
                            $fieldScore += 10.0 * (1.0 - $maxGap / 50.0);
                        }
                    }
                    
                    // Check if field is just the terms (nothing else)
                    $termsOnly = implode(' ', $searchTerms);
                    if ($cleanFieldText === $termsOnly) {
                        $fieldScore += 30.0;
                    }
                } elseif ($termMatches > 0) {
                    // Partial term matches
                    $fieldScore = 5.0 * ($termMatches / count($searchTerms));
                    $matchType = 'partial_terms';
                }
            }
            
            // Apply field weight and store
            if ($fieldScore > 0) {
                // Primary fields (title, h1, name, etc.) get extra weight multiplier
                $isPrimaryField = in_array($field, ['title', 'h1', 'name', 'label']) || $weight >= 5.0;
                $primaryMultiplier = $isPrimaryField ? 2.0 : 1.0;
                
                $weightedScore = $fieldScore * $weight * $primaryMultiplier;
                
                $fieldScores[$field] = [
                    'score' => $weightedScore,
                    'match_type' => $matchType,
                    'weight' => $weight
                ];
                
                if ($weightedScore > $bestFieldScore) {
                    $bestFieldScore = $weightedScore;
                }
            }
        }
        
        // Calculate final score
        if ($bestFieldScore > 0) {
            // Use exponential scaling for field scores to make differences more pronounced
            // This ensures high-scoring field matches significantly outrank lower ones
            $scaledFieldScore = pow($bestFieldScore / 10.0, 1.5);
            
            // Combine with base BM25 score, but give much more weight to field scores
            // when we have strong field matches
            if ($bestFieldScore >= 100.0) {
                // Exact field match - field score dominates
                return $baseScore * (1.0 + $scaledFieldScore * 10.0);
            } elseif ($bestFieldScore >= 50.0) {
                // Exact phrase match - strong field score influence
                return $baseScore * (1.0 + $scaledFieldScore * 5.0);
            } else {
                // Partial matches - moderate field score influence
                return $baseScore * (1.0 + $scaledFieldScore * 2.0);
            }
        }
        
        // Fallback to base score if no matches in weighted fields
        return $baseScore;
    }
}
