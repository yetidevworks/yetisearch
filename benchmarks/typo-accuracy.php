<?php

/**
 * Typo correction accuracy on benchmarks/movies.json
 *
 * Three sets of typos, each made the same way on every run (fixed seeds), so two versions can be
 * compared on the same queries:
 *
 *   keys    Movie titles with one typo in one word of four letters or more: a letter left out,
 *           a neighbouring key added or put in its place, or two letters swapped. A hit is the
 *           movie among the first five results of a fuzzy search.
 *   random  The same on other titles, with random letters instead of neighbouring keys and
 *           vowels replaced by vowels.
 *   words   Single words of the index with one random typo that makes them no word of the index,
 *           corrected on their own: right (the word it came from), left alone, or wrong. This set
 *           reads SearchEngine's private findBestCorrection().
 *
 * For each title set the titles typed right are searched too ("as typed"), which shows how often
 * fuzzy search finds a title without a typo, and that a change does not correct right words away.
 *
 * Usage:
 *   php typo-accuracy.php                    # this checkout
 *   php typo-accuracy.php --lib=/path/to/other/checkout
 *   php typo-accuracy.php --titles=300 --words=400 --rebuild --misses
 *
 * Options:
 *   --lib=DIR      A checkout to measure instead of this one (needs vendor/autoload.php)
 *   --db=FILE      Database (default benchmarks/typo-accuracy.db, indexed on first use)
 *   --rebuild      Index the movies again
 *   --titles=N     Titles per title set (default 300)
 *   --words=N      Words per seed of the word set, two seeds (default 400)
 *   --misses       List the titles not found
 */

$options = getopt('', ['lib:', 'db:', 'rebuild', 'titles:', 'words:', 'misses']);
$lib = rtrim($options['lib'] ?? dirname(__DIR__), '/');
$dbFile = $options['db'] ?? __DIR__ . '/typo-accuracy.db';
$titleCount = (int)($options['titles'] ?? 300);
$wordCount = (int)($options['words'] ?? 400);
$moviesFile = __DIR__ . '/movies.json';
const INDEX = 'movies';

require $lib . '/vendor/autoload.php';

if (!file_exists($moviesFile)) {
    $data = @file_get_contents('https://www.meilisearch.com/movies.json');
    if ($data === false) {
        fwrite(STDERR, "benchmarks/movies.json is missing and could not be downloaded\n");
        exit(1);
    }
    file_put_contents($moviesFile, $data);
}
$movies = json_decode(file_get_contents($moviesFile), true);

$config = [
    'storage' => ['path' => $dbFile, 'external_content' => true],
    'indexer' => [
        'batch_size' => 250,
        'fields' => [
            'title' => ['boost' => 5.0, 'store' => true],
            'overview' => ['boost' => 1.0, 'store' => true],
            'genres' => ['boost' => 2.0, 'store' => true],
        ],
    ],
    'search' => ['enable_fuzzy' => true, 'cache_ttl' => 0],
];

if (isset($options['rebuild']) || !file_exists($dbFile)) {
    foreach (['', '-wal', '-shm'] as $suffix) {
        @unlink($dbFile . $suffix);
    }
    echo "Indexing " . count($movies) . " movies... ";
    $search = new YetiSearch\YetiSearch($config);
    $indexer = $search->createIndex(INDEX);
    $batch = [];
    foreach ($movies as $movie) {
        $batch[] = [
            'id' => 'movie_' . $movie['id'],
            'content' => [
                'title' => $movie['title'],
                'overview' => $movie['overview'] ?? '',
                'genres' => is_array($movie['genres'] ?? null) ? implode(', ', $movie['genres']) : '',
            ],
        ];
        if (count($batch) === 250) {
            $indexer->insert($batch);
            $batch = [];
        }
    }
    $indexer->insert($batch);
    $indexer->flush();
    echo "done\n";
}

$search = new YetiSearch\YetiSearch($config);

// Keys beside each key in its row, for slips of a neighbouring key
$neighbours = [];
foreach (['qwertyuiop', 'asdfghjkl', 'zxcvbnm'] as $row) {
    for ($i = 0, $n = strlen($row); $i < $n; $i++) {
        $neighbours[$row[$i]] = ($i > 0 ? $row[$i - 1] : '') . ($i < $n - 1 ? $row[$i + 1] : '');
    }
}

/**
 * The word with one typo at position $p (1 or more): 0 a letter left out, 1 a letter added,
 * 2 a letter replaced, 3 two letters swapped; added and replacing letters are neighbouring keys
 * in the keys set, random letters (vowels for vowels) otherwise
 */
$typo = function (string $word, int $p, int $kind, bool $keys) use ($neighbours): string {
    $length = strlen($word);
    $near = $neighbours[$word[$p]] ?? 'e';
    $random = chr(mt_rand(97, 122));
    switch ($kind) {
        case 0:
            return substr($word, 0, $p) . substr($word, $p + 1);
        case 1:
            return substr($word, 0, $p) . ($keys ? $near[mt_rand(0, strlen($near) - 1)] : $random) . substr($word, $p);
        case 2:
            $vowels = 'aeiou';
            $letter = $keys
                ? $near[mt_rand(0, strlen($near) - 1)]
                : (strpos($vowels, $word[$p]) !== false ? $vowels[mt_rand(0, 4)] : $random);

            return substr($word, 0, $p) . $letter . substr($word, $p + 1);
        default:
            $p = min($p, $length - 2);

            return substr($word, 0, $p) . $word[$p + 1] . $word[$p] . substr($word, $p + 2);
    }
};

$titles = array_values(array_filter($movies, function (array $movie): bool {
    return preg_match('/^[A-Za-z0-9 :\'-]+$/', $movie['title']) && preg_match('/[A-Za-z]{5,}/', $movie['title']);
}));

$titleSet = function (string $set) use ($titles, $titleCount, $typo, $search, $options): array {
    mt_srand($set === 'keys' ? 42 : 4242);
    $cases = [];
    for ($k = 0; count($cases) < $titleCount && $k < 20000; $k++) {
        $movie = $titles[mt_rand(0, count($titles) - 1)];
        $words = explode(' ', $movie['title']);
        $long = array_keys(array_filter($words, function (string $word): bool {
            return strlen($word) >= 4 && ctype_alpha($word);
        }));
        if (!$long) {
            continue;
        }
        $at = $long[mt_rand(0, count($long) - 1)];
        $word = strtolower($words[$at]);
        $p = mt_rand(1, strlen($word) - 1);
        $kind = mt_rand(0, 3);
        $typed = $typo($word, $p, $kind, $set === 'keys');
        if ($typed === $word) {
            continue;
        }
        $words[$at] = $typed;
        $cases[] = ['title' => $movie['title'], 'query' => implode(' ', $words)];
    }

    $found = ['typo' => 0, 'typed right' => 0];
    $time = 0.0;
    $misses = [];
    foreach ($cases as $case) {
        foreach (['typo' => $case['query'], 'typed right' => $case['title']] as $which => $query) {
            $start = microtime(true);
            $results = $search->search(INDEX, $query, ['limit' => 5, 'fuzzy' => true]);
            if ($which === 'typo') {
                $time += microtime(true) - $start;
            }
            $top = array_map(function (array $result): string {
                return strtolower($result['document']['title'] ?? '');
            }, $results['results']);
            if (in_array(strtolower($case['title']), $top, true)) {
                $found[$which]++;
            } elseif (isset($options['misses'])) {
                $misses[] = sprintf('  %-11s %-40s %s', $which, $query, $which === 'typo' ? "({$case['title']})" : '');
            }
        }
    }

    return [count($cases), $found, $time * 1000 / max(1, count($cases)), $misses];
};

$wordSet = function (int $seed) use ($search, $dbFile, $wordCount, $typo): array {
    $engine = $search->getSearchEngine(INDEX);
    $correct = new ReflectionMethod($engine, 'findBestCorrection');
    if (PHP_VERSION_ID < 80100) {
        $correct->setAccessible(true);
    }
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS typo_accuracy_vocab USING fts5vocab('" . INDEX . "_fts', 'row')");
    $vocabulary = array_flip($pdo->query('SELECT term FROM typo_accuracy_vocab')->fetchAll(PDO::FETCH_COLUMN));
    $words = $pdo->query(
        "SELECT term FROM typo_accuracy_vocab WHERE length(term) >= 4 AND term NOT GLOB '*[^a-z]*' ORDER BY doc DESC, term LIMIT 30000"
    )->fetchAll(PDO::FETCH_COLUMN);

    mt_srand($seed);
    $counts = ['right' => 0, 'left alone' => 0, 'wrong' => 0];
    $cases = 0;
    while ($cases < $wordCount) {
        $word = (string)$words[mt_rand(0, count($words) - 1)];
        $typed = $typo($word, mt_rand(0, strlen($word) - 1), mt_rand(0, 3), false);
        if ($typed === $word || isset($vocabulary[$typed])) {
            continue;
        }
        $cases++;
        $correction = $correct->invoke($engine, $typed);
        $counts[$correction === $word ? 'right' : ($correction === $typed ? 'left alone' : 'wrong')]++;
    }

    return [$cases, $counts];
};

printf("Typo accuracy | %s | PHP %s\n%s\n", $lib, PHP_VERSION, str_repeat('-', 78));
foreach (['keys', 'random'] as $set) {
    [$n, $found, $ms, $misses] = $titleSet($set);
    printf(
        "%-7s %d titles   with a typo %5.1f%% found   typed right %5.1f%%   %.1f ms a search\n",
        $set,
        $n,
        100 * $found['typo'] / $n,
        100 * $found['typed right'] / $n,
        $ms
    );
    if ($misses) {
        echo implode("\n", $misses), "\n";
    }
}
foreach ([7, 99] as $seed) {
    [$n, $counts] = $wordSet($seed);
    printf(
        "words   %d (seed %d)   right %5.1f%%   left alone %5.1f%%   wrong %5.1f%%\n",
        $n,
        $seed,
        100 * $counts['right'] / $n,
        100 * $counts['left alone'] / $n,
        100 * $counts['wrong'] / $n
    );
}
