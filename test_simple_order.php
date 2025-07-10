<?php

// Direct SQLite test to isolate the ORDER BY issue with FTS5

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Create tables
$db->exec("
    CREATE TABLE docs (
        id TEXT PRIMARY KEY,
        title TEXT,
        body TEXT
    )
");

$db->exec("
    CREATE VIRTUAL TABLE docs_fts USING fts5(
        id UNINDEXED,
        content
    )
");

// Insert test data
$data = [
    ['id' => '1', 'title' => 'Coffee A', 'body' => 'First coffee shop', 'distance' => 100],
    ['id' => '2', 'title' => 'Coffee B', 'body' => 'Second coffee shop', 'distance' => 50],
    ['id' => '3', 'title' => 'Coffee C', 'body' => 'Third coffee shop', 'distance' => 200],
    ['id' => '4', 'title' => 'Coffee D', 'body' => 'Fourth coffee shop', 'distance' => 25],
];

foreach ($data as $row) {
    $db->prepare("INSERT INTO docs (id, title, body) VALUES (?, ?, ?)")
       ->execute([$row['id'], $row['title'], $row['body']]);
    
    $db->prepare("INSERT INTO docs_fts (id, content) VALUES (?, ?)")
       ->execute([$row['id'], $row['title'] . ' ' . $row['body']]);
}

// Test 1: Simple ORDER BY with calculated distance
echo "Test 1: Direct ORDER BY with calculated field\n";
echo "=============================================\n";

$sql1 = "
    SELECT 
        d.id,
        d.title,
        bm25(docs_fts) as rank,
        CASE d.id 
            WHEN '1' THEN 100
            WHEN '2' THEN 50
            WHEN '3' THEN 200
            WHEN '4' THEN 25
        END as distance
    FROM docs d
    INNER JOIN docs_fts f ON d.id = f.id
    WHERE docs_fts MATCH 'coffee'
    ORDER BY distance ASC
";

$stmt1 = $db->query($sql1);
while ($row = $stmt1->fetch(PDO::FETCH_ASSOC)) {
    echo "ID: {$row['id']}, Title: {$row['title']}, Distance: {$row['distance']}\n";
}

// Test 2: Using subquery
echo "\n\nTest 2: Using subquery\n";
echo "======================\n";

$sql2 = "
    SELECT * FROM (
        SELECT 
            d.id,
            d.title,
            bm25(docs_fts) as rank,
            CASE d.id 
                WHEN '1' THEN 100
                WHEN '2' THEN 50
                WHEN '3' THEN 200
                WHEN '4' THEN 25
            END as distance
        FROM docs d
        INNER JOIN docs_fts f ON d.id = f.id
        WHERE docs_fts MATCH 'coffee'
    ) AS results
    ORDER BY distance ASC
";

$stmt2 = $db->query($sql2);
while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
    echo "ID: {$row['id']}, Title: {$row['title']}, Distance: {$row['distance']}\n";
}

// Test 3: Using DISTINCT to force materialization
echo "\n\nTest 3: Using GROUP BY to force materialization\n";
echo "===========================================\n";

$sql3 = "
    SELECT 
        d.id,
        d.title,
        MAX(bm25(docs_fts)) as rank,
        CASE d.id 
            WHEN '1' THEN 100
            WHEN '2' THEN 50
            WHEN '3' THEN 200
            WHEN '4' THEN 25
        END as distance
    FROM docs d
    INNER JOIN docs_fts f ON d.id = f.id
    WHERE docs_fts MATCH 'coffee'
    GROUP BY d.id, d.title, distance
    ORDER BY distance ASC
";

$stmt3 = $db->query($sql3);
while ($row = $stmt3->fetch(PDO::FETCH_ASSOC)) {
    echo "ID: {$row['id']}, Title: {$row['title']}, Distance: {$row['distance']}\n";
}