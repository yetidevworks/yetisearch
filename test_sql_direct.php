<?php

// Test SQLite FTS5 with subquery directly

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Create tables
$db->exec("
    CREATE TABLE items (
        id TEXT PRIMARY KEY,
        content TEXT
    )
");

$db->exec("
    CREATE VIRTUAL TABLE items_fts USING fts5(
        id UNINDEXED,
        content
    )
");

$db->exec("
    CREATE TABLE items_spatial (
        id INTEGER PRIMARY KEY,
        lat REAL,
        lng REAL
    )
");

// Insert test data
$data = [
    ['id' => 'A', 'content' => 'Coffee shop one', 'lat' => 45.5152, 'lng' => -122.6734],
    ['id' => 'B', 'content' => 'Coffee shop two', 'lat' => 45.5220, 'lng' => -122.6845],
    ['id' => 'C', 'content' => 'Coffee shop three', 'lat' => 47.6145, 'lng' => -122.3278],
    ['id' => 'D', 'content' => 'Coffee shop four', 'lat' => 49.2835, 'lng' => -123.1089],
];

foreach ($data as $row) {
    $db->prepare("INSERT INTO items (id, content) VALUES (?, ?)")
       ->execute([$row['id'], json_encode(['content' => $row['content']])]);
    
    $db->prepare("INSERT INTO items_fts (id, content) VALUES (?, ?)")
       ->execute([$row['id'], $row['content']]);
    
    $numId = abs(crc32($row['id']));
    $db->prepare("INSERT INTO items_spatial (id, lat, lng) VALUES (?, ?, ?)")
       ->execute([$numId, $row['lat'], $row['lng']]);
}

// Create ID mapping for testing
$db->exec("
    CREATE TABLE items_id_map (
        string_id TEXT PRIMARY KEY,
        numeric_id INTEGER
    )
");

foreach ($data as $row) {
    $numId = abs(crc32($row['id']));
    $db->prepare("INSERT INTO items_id_map (string_id, numeric_id) VALUES (?, ?)")
       ->execute([$row['id'], $numId]);
}

$centerLat = 45.5152;
$centerLng = -122.6784;

// Test 1: Direct query without subquery
echo "Test 1: Direct query (current approach)\n";
echo "======================================\n";
$sql1 = "
    SELECT 
        d.*,
        bm25(items_fts) as rank,
        SQRT(
            POWER(({$centerLat} - s.lat) * 111.12, 2) +
            POWER(({$centerLng} - s.lng) * 111.12, 2)
        ) * 1000 as distance
    FROM items d
    INNER JOIN items_fts f ON d.id = f.id
    LEFT JOIN items_id_map m ON m.string_id = d.id
    LEFT JOIN items_spatial s ON s.id = m.numeric_id
    WHERE items_fts MATCH 'coffee'
    ORDER BY distance ASC
";

$stmt1 = $db->query($sql1);
$results1 = $stmt1->fetchAll(PDO::FETCH_ASSOC);
foreach ($results1 as $row) {
    $content = json_decode($row['content'], true);
    echo "ID: {$row['id']}, Content: {$content['content']}, Distance: " . round($row['distance'], 2) . "\n";
}

// Test 2: Using subquery
echo "\nTest 2: Using subquery\n";
echo "=====================\n";
$sql2 = "
    SELECT * FROM (
        SELECT 
            d.*,
            bm25(items_fts) as rank,
            SQRT(
                POWER(({$centerLat} - s.lat) * 111.12, 2) +
                POWER(({$centerLng} - s.lng) * 111.12, 2)
            ) * 1000 as distance
        FROM items d
        INNER JOIN items_fts f ON d.id = f.id
        LEFT JOIN items_id_map m ON m.string_id = d.id
        LEFT JOIN items_spatial s ON s.id = m.numeric_id
        WHERE items_fts MATCH 'coffee'
    ) AS subquery
    ORDER BY distance ASC
";

$stmt2 = $db->query($sql2);
$results2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);
foreach ($results2 as $row) {
    $content = json_decode($row['content'], true);
    echo "ID: {$row['id']}, Content: {$content['content']}, Distance: " . round($row['distance'], 2) . "\n";
}

// Test 3: Force materialization with GROUP BY
echo "\nTest 3: Force materialization with GROUP BY\n";
echo "==========================================\n";
$sql3 = "
    SELECT * FROM (
        SELECT 
            d.id,
            MAX(d.content) as content,
            MAX(bm25(items_fts)) as rank,
            MAX(SQRT(
                POWER(({$centerLat} - s.lat) * 111.12, 2) +
                POWER(({$centerLng} - s.lng) * 111.12, 2)
            ) * 1000) as distance
        FROM items d
        INNER JOIN items_fts f ON d.id = f.id
        LEFT JOIN items_id_map m ON m.string_id = d.id
        LEFT JOIN items_spatial s ON s.id = m.numeric_id
        WHERE items_fts MATCH 'coffee'
        GROUP BY d.id
    ) AS subquery
    ORDER BY distance ASC
";

$stmt3 = $db->query($sql3);
$results3 = $stmt3->fetchAll(PDO::FETCH_ASSOC);
foreach ($results3 as $row) {
    $content = json_decode($row['content'], true);
    echo "ID: {$row['id']}, Content: {$content['content']}, Distance: " . round($row['distance'], 2) . "\n";
}