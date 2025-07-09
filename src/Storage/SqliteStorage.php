<?php

namespace YetiSearch\Storage;

use YetiSearch\Contracts\StorageInterface;
use YetiSearch\Exceptions\StorageException;

class SqliteStorage implements StorageInterface
{
    private ?\PDO $connection = null;
    private array $config = [];
    private array $preparedStatements = [];
    
    public function connect(array $config): void
    {
        $this->config = $config;
        $dbPath = $config['path'] ?? ':memory:';
        
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
        
        $sql = "
            SELECT 
                d.*,
                bm25({$index}_fts) as rank
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
        
        if (empty($sort)) {
            $sql .= " ORDER BY rank DESC";
        } else {
            $orderClauses = [];
            foreach ($sort as $field => $direction) {
                $dir = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
                if ($field === '_score') {
                    $orderClauses[] = "rank {$dir}";
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
            while ($row = $stmt->fetch()) {
                $results[] = [
                    'id' => $row['id'],
                    'score' => abs($row['rank']),
                    'document' => json_decode($row['content'], true),
                    'metadata' => json_decode($row['metadata'], true),
                    'language' => $row['language'],
                    'type' => $row['type'],
                    'timestamp' => $row['timestamp']
                ];
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
}