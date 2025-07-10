<?php

namespace YetiSearch\Storage;

use YetiSearch\Contracts\StorageInterface;
use YetiSearch\Exceptions\StorageException;
use YetiSearch\Models\Document;
use YetiSearch\Geo\GeoPoint;
use YetiSearch\Geo\GeoBounds;

class SqliteStorage implements StorageInterface
{
    private ?\PDO $connection = null;
    private array $config = [];
    private array $preparedStatements = [];
    
    public function connect(array $config): void
    {
        $this->config = $config;
        $dbPath = $config['path'] ?? ':memory:';
        
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
            $this->connection->exec('PRAGMA synchronous = NORMAL');
            $this->connection->exec('PRAGMA temp_store = MEMORY');
            $this->connection->exec('PRAGMA cache_size = -20000');
            
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
                    tokenize = 'porter unicode61'
                )
            ";
            $this->connection->exec($sql);
            
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
            
            // Create R-tree spatial index
            $spatialSql = "
                CREATE VIRTUAL TABLE IF NOT EXISTS {$name}_spatial USING rtree(
                    id,              -- Document ID (integer required by R-tree)
                    minLat, maxLat,  -- Latitude bounds
                    minLng, maxLng   -- Longitude bounds
                )
            ";
            $this->connection->exec($spatialSql);
            
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
            
            $this->indexTerms($index, $id, $document['content']);
            
            // Handle spatial indexing
            $this->indexSpatialData($index, $id, $document);
            
            $this->connection->commit();
        } catch (\PDOException $e) {
            $this->connection->rollBack();
            throw new StorageException("Failed to insert document: " . $e->getMessage());
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
            $this->connection->prepare("DELETE FROM {$index}_terms WHERE document_id = ?")->execute([$id]);
            
            // Delete from spatial index (use hash of string ID for R-tree integer ID)
            $spatialId = $this->getNumericId($id);
            $this->connection->prepare("DELETE FROM {$index}_spatial WHERE id = ?")->execute([$spatialId]);
            
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
        
        // Build spatial query components
        $spatial = $this->buildSpatialQuery($index, $geoFilters);
        
        $sql = "
            SELECT 
                d.*,
                bm25({$index}_fts) as rank" . $spatial['select'] . "
            FROM {$index} d
            INNER JOIN {$index}_fts f ON d.id = f.id" . $spatial['join'] . "
            WHERE {$index}_fts MATCH ?" . $spatial['where'] . "
        ";
        
        $params = array_merge([$searchQuery], $spatial['params']);
        
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
        
        // Handle distance sorting from geo filters
        if (isset($geoFilters['distance_sort'])) {
            $dir = strtoupper($geoFilters['distance_sort']['direction']) === 'DESC' ? 'DESC' : 'ASC';
            $sql .= " ORDER BY distance {$dir}";
        } elseif (empty($sort)) {
            $sql .= " ORDER BY rank DESC";
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
        $params[] = $limit;
        $params[] = $offset;
        
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            
            $results = [];
            $radiusFilter = null;
            
            // Check if we need to do radius post-filtering
            if (isset($geoFilters['near'])) {
                $radiusFilter = $geoFilters['near']['radius'];
            }
            
            while ($row = $stmt->fetch()) {
                $result = [
                    'id' => $row['id'],
                    'score' => abs($row['rank']),
                    'document' => json_decode($row['content'], true),
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
        
        $sql = "
            SELECT COUNT(*) as total
            FROM {$index} d
            INNER JOIN {$index}_fts f ON d.id = f.id
            WHERE {$index}_fts MATCH ?
        ";
        
        $params = [$searchQuery];
        
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
        
        if (isset($geoFilters['near'])) {
            $near = $geoFilters['near'];
            $point = is_array($near['point']) ? new GeoPoint($near['point']['lat'], $near['point']['lng']) : $near['point'];
            $radius = $near['radius'];
            
            // Calculate bounding box for initial R-tree filtering
            $bounds = $point->getBoundingBox($radius);
            
            $spatialJoin = " INNER JOIN {$index}_spatial s ON s.id = " . $this->getNumericIdExpression('d.id');
            
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
            
            $spatialJoin = " INNER JOIN {$index}_spatial s ON s.id = " . $this->getNumericIdExpression('d.id');
            
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
        // Use CRC32 equivalent in SQLite
        // This creates a stable numeric hash from the string ID
        return "ABS(
            (
                (CAST(unicode(substr({$field}, 1, 1)) AS INTEGER) * 16777619) ^
                (CAST(unicode(substr({$field}, 2, 1)) AS INTEGER) * 16777619) ^
                (CAST(unicode(substr({$field}, 3, 1)) AS INTEGER) * 16777619) ^
                (CAST(unicode(substr({$field}, 4, 1)) AS INTEGER) * 16777619)
            ) % 2147483647
        )";
    }
    
    private function getDistanceExpression(float $lat, float $lng): string
    {
        // Simplified distance calculation for SQLite
        // Using degrees directly with approximate conversion
        $degToKm = 111.12; // Approximate km per degree at equator
        
        // Simple Euclidean distance with latitude correction
        // This is less accurate than Haversine but sufficient for sorting/filtering
        return "
            SQRT(
                POWER(({$lat} - (s.minLat + s.maxLat) / 2) * {$degToKm}, 2) +
                POWER(({$lng} - (s.minLng + s.maxLng) / 2) * {$degToKm} * COS((s.minLat + s.maxLat) / 2 * 0.0174533), 2)
            ) * 1000
        ";
    }
    
    public function ensureSpatialTableExists(string $name): void
    {
        $this->ensureConnected();
        
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
        } catch (\PDOException $e) {
            throw new StorageException("Failed to ensure spatial table exists for '{$name}': " . $e->getMessage());
        }
    }
}