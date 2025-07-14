<?php

namespace YetiSearch\Storage;

use YetiSearch\Contracts\StorageInterface;
use YetiSearch\Exceptions\StorageException;
use YetiSearch\Geo\GeoPoint;
use YetiSearch\Geo\GeoBounds;

class SqliteStorage implements StorageInterface
{
    private ?\PDO $connection = null;
    private array $config = [];
    private array $searchConfig = [];
    private array $preparedStatements = [];
    private ?bool $rtreeSupport = null;
    private bool $useTermsIndex = false;
    
    public function connect(array $config): void
    {
        $this->config = $config;
        $dbPath = $config['path'] ?? ':memory:';
        
        // Extract search config if available
        $this->searchConfig = $config['search'] ?? [];
        
        // Only use terms index if Levenshtein fuzzy search is enabled
        $this->useTermsIndex = ($this->searchConfig['enable_fuzzy'] ?? false) && 
                               ($this->searchConfig['fuzzy_algorithm'] ?? 'basic') === 'levenshtein';
        
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
            
            $this->initializeDatabase();
        } catch (\PDOException $e) {
            throw new StorageException("Failed to connect to SQLite: " . $e->getMessage());
        }
    }
    
    public function disconnect(): void
    {
        $this->preparedStatements = [];
        $this->connection = null;
    }
    
    public function createIndex(string $name, array $options = []): void
    {
        $this->ensureConnected();
        
        try {
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
            
            $this->connection->exec("CREATE INDEX IF NOT EXISTS idx_{$name}_language ON {$name}(language)");
            $this->connection->exec("CREATE INDEX IF NOT EXISTS idx_{$name}_type ON {$name}(type)");
            $this->connection->exec("CREATE INDEX IF NOT EXISTS idx_{$name}_timestamp ON {$name}(timestamp)");
            
            $sql = "
                CREATE VIRTUAL TABLE IF NOT EXISTS {$name}_fts USING fts5(
                    id UNINDEXED,
                    content,
                    tokenize = 'unicode61'
                )
            ";
            $this->connection->exec($sql);
            
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
            
            // Create R-tree spatial index if available
            if ($this->hasRTreeSupport()) {
                $spatialSql = "
                    CREATE VIRTUAL TABLE IF NOT EXISTS {$name}_spatial USING rtree(
                        id,              -- Document ID (integer required by R-tree)
                        minLat, maxLat,  -- Latitude bounds
                        minLng, maxLng   -- Longitude bounds
                    )
                ";
                $this->connection->exec($spatialSql);
                
                // Create ID mapping table
                $mappingSql = "
                    CREATE TABLE IF NOT EXISTS {$name}_id_map (
                        string_id TEXT PRIMARY KEY,
                        numeric_id INTEGER
                    )
                ";
                $this->connection->exec($mappingSql);
                $this->connection->exec("CREATE INDEX IF NOT EXISTS idx_{$name}_id_map_numeric ON {$name}_id_map(numeric_id)");
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
        
        try {
            $this->connection->beginTransaction();
            
            $id = $document['id'];
            $content = json_encode($document['content']);
            $metadata = json_encode($document['metadata'] ?? []);
            $language = $document['language'] ?? null;
            $type = $document['type'] ?? 'default';
            $timestamp = $document['timestamp'] ?? time();
            
            $sql = "
                INSERT OR REPLACE INTO {$index} 
                (id, content, metadata, language, type, timestamp)
                VALUES (?, ?, ?, ?, ?, ?)
            ";
            $stmt = $this->connection->prepare($sql);
            $stmt->execute([$id, $content, $metadata, $language, $type, $timestamp]);
            
            $searchableContent = $this->extractSearchableContent($document['content']);
            $ftsSql = "INSERT OR REPLACE INTO {$index}_fts (id, content) VALUES (?, ?)";
            $ftsStmt = $this->connection->prepare($ftsSql);
            $ftsStmt->execute([$id, $searchableContent]);
            
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
        
        if (empty($documents)) {
            return;
        }
        
        try {
            $this->connection->beginTransaction();
            
            // Prepare statements once
            $docStmt = $this->connection->prepare("
                INSERT OR REPLACE INTO {$index} 
                (id, content, metadata, language, type, timestamp)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $ftsStmt = $this->connection->prepare("
                INSERT OR REPLACE INTO {$index}_fts (id, content) VALUES (?, ?)
            ");
            
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
                $metadata = json_encode($document['metadata'] ?? []);
                $language = $document['language'] ?? null;
                $type = $document['type'] ?? 'default';
                $timestamp = $document['timestamp'] ?? time();
                
                // Insert main document
                $docStmt->execute([$id, $content, $metadata, $language, $type, $timestamp]);
                
                // Insert FTS content
                $searchableContent = $this->extractSearchableContent($document['content']);
                $ftsStmt->execute([$id, $searchableContent]);
                
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
        $document['id'] = $id;
        $this->insert($index, $document);
    }
    
    public function delete(string $index, string $id): void
    {
        $this->ensureConnected();
        
        try {
            $this->connection->beginTransaction();
            
            $this->connection->prepare("DELETE FROM {$index} WHERE id = ?")->execute([$id]);
            $this->connection->prepare("DELETE FROM {$index}_fts WHERE id = ?")->execute([$id]);
            
            // Only delete from terms table if it exists (Levenshtein enabled)
            if ($this->useTermsIndex) {
                $this->connection->prepare("DELETE FROM {$index}_terms WHERE document_id = ?")->execute([$id]);
            }
            
            // Only delete from spatial-related tables if R-tree support is available
            if ($this->hasRTreeSupport()) {
                $this->connection->prepare("DELETE FROM {$index}_id_map WHERE string_id = ?")->execute([$id]);
                
                // Delete from spatial index (use hash of string ID for R-tree integer ID)
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
        $hasSpatialData = !empty($spatial['select']) && strpos($spatial['select'], 'distance') !== false;
        
        if ($hasSearchQuery && $hasSpatialData) {
            // Check if sorting by distance
            if (isset($geoFilters['distance_sort']) || isset($sort['distance'])) {
                $needsPhpSort = true;
            }
        }
        
        // When using field weights, we need to fetch more results to ensure proper scoring
        $effectiveLimit = (!empty($fieldWeights) && $hasSearchQuery) ? min(max($limit * 20, 200), 500) : $limit;
        
        
        if ($hasSearchQuery) {
            // Full text search with optional spatial filters
            $sql = "
                SELECT 
                    d.*,
                    bm25({$index}_fts) as rank" . $spatial['select'] . "
                FROM {$index} d
                INNER JOIN {$index}_fts f ON d.id = f.id" . $spatial['join'] . "
                WHERE {$index}_fts MATCH ?" . $spatial['where'] . "
            ";
            $params = array_merge([$searchQuery], $spatial['params']);
        } else {
            // No text search, only filters and/or spatial search
            $sql = "
                SELECT 
                    d.*,
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
            // Fetch up to 1000 results or 10x the requested limit, whichever is smaller
            $fetchLimit = min(1000, max($limit * 10, 100));
            
            // Apply basic ordering by rank to get most relevant results first
            $sql .= " ORDER BY rank ASC";
            $sql .= " LIMIT ?";
            $params[] = $fetchLimit;
        } else {
            // Normal SQL sorting
            if (isset($geoFilters['distance_sort']) && !empty($spatial['select'])) {
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
                    } else {
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
            
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            
            $results = [];
            $radiusFilter = null;
            
            // Check if we need to do radius post-filtering
            if (isset($geoFilters['near'])) {
                $radiusFilter = $geoFilters['near']['radius'];
            }
            
            $rowCount = 0;
            while ($row = $stmt->fetch()) {
                $rowCount++;
                $content = json_decode($row['content'], true);
                $baseScore = abs($row['rank']);
                
                // Apply field weights if provided
                if (!empty($fieldWeights) && $hasSearchQuery) {
                    $weightedScore = $this->calculateFieldWeightedScore($searchQuery, $content, $fieldWeights, $baseScore);
                    $finalScore = $weightedScore;
                    
                } else {
                    $finalScore = $baseScore;
                }
                
                $result = [
                    'id' => $row['id'],
                    'score' => $finalScore,
                    'document' => $content,
                    'metadata' => json_decode($row['metadata'], true),
                    'language' => $row['language'],
                    'type' => $row['type'],
                    'timestamp' => $row['timestamp']
                ];
                
                // Add distance if available
                if (isset($row['distance'])) {
                    $distance = (float)$row['distance'];
                    
                    // Skip results outside radius for 'near' queries
                    if ($radiusFilter !== null && $distance > $radiusFilter) {
                        continue;
                    }
                    
                    $result['distance'] = $distance;
                }
                
                $results[] = $result;
            }
            
            // Re-sort results if field weights were applied
            if (!empty($fieldWeights) && $hasSearchQuery) {
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
            $sql = "
                SELECT COUNT(*) as total
                FROM {$index} d
                INNER JOIN {$index}_fts f ON d.id = f.id" . $spatial['join'] . "
                WHERE {$index}_fts MATCH ?" . $spatial['where'] . "
            ";
            $params = array_merge([$searchQuery], $spatial['params']);
        } else {
            $sql = "
                SELECT COUNT(*) as total
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
                
                // Verify this is a valid index by checking for corresponding FTS and terms tables
                $ftsExists = $this->connection->query("
                    SELECT COUNT(*) 
                    FROM sqlite_master 
                    WHERE type = 'table' 
                    AND name = '{$tableName}_fts'
                ")->fetchColumn() > 0;
                
                $termsExists = $this->connection->query("
                    SELECT COUNT(*) 
                    FROM sqlite_master 
                    WHERE type = 'table' 
                    AND name = '{$tableName}_terms'
                ")->fetchColumn() > 0;
                
                if ($ftsExists && $termsExists) {
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
        // Skip if R-tree support is not available
        if (!$this->hasRTreeSupport()) {
            return;
        }
        
        // R-tree requires integer IDs, so we create a numeric hash of the string ID
        $spatialId = $this->getNumericId($id);
        
        // First, delete any existing spatial data
        $deleteStmt = $this->connection->prepare("DELETE FROM {$index}_spatial WHERE id = ?");
        $deleteStmt->execute([$spatialId]);
        
        // Check for geo data
        $hasGeo = isset($document['geo']) || isset($document['geo_bounds']);
        if (!$hasGeo) {
            return;
        }
        
        
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
            
            // Store the ID mapping
            $mapStmt = $this->connection->prepare("
                INSERT OR REPLACE INTO {$index}_id_map (string_id, numeric_id)
                VALUES (?, ?)
            ");
            $mapStmt->execute([$id, $spatialId]);
            
            // Insert spatial data
            $spatialStmt = $this->connection->prepare("
                INSERT INTO {$index}_spatial (id, minLat, maxLat, minLng, maxLng)
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
        
        // Return empty spatial query if R-tree is not supported
        if (!$this->hasRTreeSupport()) {
            return [
                'join' => '',
                'where' => '',
                'params' => [],
                'select' => ''
            ];
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
            $spatialJoin = " LEFT JOIN {$index}_id_map m ON m.string_id = d.id " .
                           "LEFT JOIN {$index}_spatial s ON s.id = m.numeric_id";
            $distanceSelect = ", " . $this->getDistanceExpression($lat, $lng) . " as distance";
        }
        
        if (isset($geoFilters['near'])) {
            $near = $geoFilters['near'];
            $point = is_array($near['point']) ? new GeoPoint($near['point']['lat'], $near['point']['lng']) : $near['point'];
            $radius = $near['radius'];
            
            // Calculate bounding box for initial R-tree filtering
            $bounds = $point->getBoundingBox($radius);
            
            $spatialJoin = " INNER JOIN {$index}_id_map m ON m.string_id = d.id " .
                           "INNER JOIN {$index}_spatial s ON s.id = m.numeric_id";
            
            // R-tree bounding box filter
            $spatialSql = " AND s.minLat <= ? AND s.maxLat >= ? AND s.minLng <= ? AND s.maxLng >= ?";
            $spatialParams = [
                $bounds->getNorth(),
                $bounds->getSouth(),
                $bounds->getEast(),
                $bounds->getWest()
            ];
            
            // Add distance calculation for post-filtering and sorting
            $lat = $point->getLatitude();
            $lng = $point->getLongitude();
            $distanceSelect = ", " . $this->getDistanceExpression($lat, $lng) . " as distance";
        }
        elseif (isset($geoFilters['within'])) {
            $boundsData = $geoFilters['within']['bounds'];
            $bounds = is_array($boundsData) ? GeoBounds::fromArray($boundsData) : $boundsData;
            
            $spatialJoin = " INNER JOIN {$index}_id_map m ON m.string_id = d.id " .
                           "INNER JOIN {$index}_spatial s ON s.id = m.numeric_id";
            
            // R-tree intersection query
            $spatialSql = " AND s.minLat <= ? AND s.maxLat >= ? AND s.minLng <= ? AND s.maxLng >= ?";
            $spatialParams = [
                $bounds->getNorth(),
                $bounds->getSouth(), 
                $bounds->getEast(),
                $bounds->getWest()
            ];
        }
        
        return [
            'join' => $spatialJoin,
            'where' => $spatialSql,
            'params' => $spatialParams,
            'select' => $distanceSelect
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
        // Simplified distance calculation for SQLite
        // Using degrees directly with approximate conversion
        $degToKm = 111.12; // Approximate km per degree at equator
        
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
        
        // Simple Euclidean distance with latitude correction
        // This is less accurate than Haversine but sufficient for sorting/filtering
        return "
            SQRT(
                POWER(({$lat} - ({$minLat} + {$maxLat}) / 2) * {$degToKm}, 2) +
                POWER(({$lng} - ({$minLng} + {$maxLng}) / 2) * {$degToKm} * COS(({$minLat} + {$maxLat}) / 2 * 0.0174533), 2)
            ) * 1000
        ";
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
            
            // Also ensure ID mapping table exists
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
            // Try to create a temporary R-tree table to test support
            $this->connection->exec("CREATE VIRTUAL TABLE IF NOT EXISTS test_rtree_support USING rtree(id, minX, maxX)");
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
            
            // Sort by frequency and return just the terms
            arsort($allTerms);
            return array_keys(array_slice($allTerms, 0, $limit, true));
            
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
        
        // Remove quoted phrases and parentheses from query to get individual terms
        $cleanQuery = preg_replace('/["\(\)]/', ' ', $searchQuery);
        $allTerms = array_map('trim', explode(' ', strtolower($cleanQuery)));
        
        // Filter out OR operators and empty terms
        $searchTerms = array_filter($allTerms, function($term) {
            return $term !== 'or' && !empty($term);
        });
        
        // If no exact phrases were found but we have multiple terms, treat them as a phrase too
        if (empty($exactPhrases) && count($searchTerms) > 1) {
            $exactPhrases[] = implode(' ', $searchTerms);
        }
        
        $maxFieldScore = 0.0;
        $totalWeight = 0.0;
        
        // Check each field for search terms
        foreach ($fieldWeights as $field => $weight) {
            if (!isset($content[$field]) || !is_string($content[$field])) {
                continue;
            }
            
            $fieldText = strtolower($content[$field]);
            $fieldScore = 0.0;
            $matchCount = 0;
            $hasExactPhrase = false;
            
            // First check for exact phrase matches (highest boost)
            foreach ($exactPhrases as $phrase) {
                if (strpos($fieldText, $phrase) !== false) {
                    $fieldScore += 15.0; // Massive boost for exact phrase
                    $matchCount = count(explode(' ', $phrase)); // Count words in phrase
                    $hasExactPhrase = true;
                    
                    // Extra boost for high-weight fields (likely primary fields like title, name, etc)
                    // Use boost threshold to determine if this is a "primary" field
                    if ($weight >= 2.5) {
                        // Exact match gets huge bonus
                        if ($fieldText === $phrase) {
                            $fieldScore += 50.0;
                        } 
                        // Near-exact match (just punctuation differences) gets good bonus
                        else if (trim(preg_replace('/[^\w\s]/', '', $fieldText)) === $phrase) {
                            $fieldScore += 30.0;
                        }
                        // For high-boost fields, apply length penalty to prefer shorter exact matches
                        else {
                            $phraseLen = strlen($phrase);
                            $fieldLen = strlen($fieldText);
                            if ($fieldLen > $phraseLen) {
                                // Penalize longer values - the longer, the less score
                                $lengthPenalty = 1.0 - min(0.5, ($fieldLen - $phraseLen) / 100.0);
                                $fieldScore *= $lengthPenalty;
                            }
                        }
                    }
                    break; // One exact phrase match is enough
                }
            }
            
            // If no exact phrase match, check individual terms
            if (!$hasExactPhrase) {
                foreach ($searchTerms as $term) {
                    if (strpos($fieldText, $term) !== false) {
                        $matchCount++;
                        // Give extra weight for exact matches in high-boost fields
                        if ($weight >= 2.5 && $fieldText === $term) {
                            $fieldScore += 2.0;
                        } else {
                            $fieldScore += 1.0;
                        }
                    }
                }
            }
            
            // Apply field weight
            if ($matchCount > 0) {
                // Bonus for matching multiple terms in the same field
                $multiTermBonus = ($matchCount > 1) ? pow($matchCount, 1.5) : 1.0;
                $weightedFieldScore = $fieldScore * $weight * $multiTermBonus;
                $maxFieldScore = max($maxFieldScore, $weightedFieldScore);
                $totalWeight += $weight;
            }
        }
        
        // If we found matches in weighted fields, use the weighted score
        if ($maxFieldScore > 0) {
            // Combine base BM25 score with field-weighted score
            // Give more importance to field weights
            return $baseScore * (0.3 + $maxFieldScore);
        }
        
        // Fallback to base score if no matches in weighted fields
        return $baseScore;
    }
}