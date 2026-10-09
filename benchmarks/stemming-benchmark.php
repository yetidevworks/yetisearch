<?php
/**
 * YetiSearch stemming benchmark
 *
 * Times the same work with stemming off and on over benchmarks/movies.json:
 * indexing, a fixed set of searches, updating documents, and (on an index
 * that does not stem) switching it to stemming with rebuildFts().
 *
 * Each run is a PHP process of its own, so memory figures are not mixed
 * between runs. With --runs=N the script starts N of them and prints the
 * median of each figure.
 *
 * Usage:
 *   php stemming-benchmark.php --stemming=off --runs=3
 *   php stemming-benchmark.php --stemming=on --language=english --runs=3
 *   php stemming-benchmark.php --stemming=on --external=0     # own-content FTS table
 *   php stemming-benchmark.php --phases=index,search          # leave out update and rebuild
 *   php stemming-benchmark.php --lib=/path/to/other/checkout  # another version, e.g. a 2.5.6 export
 *
 * Options:
 *   --stemming=on|off   Create the index with stemming (default off)
 *   --language=NAME     The index's stemming language (default english)
 *   --external=1|0      External-content FTS table (default 1) or own-content
 *   --phases=LIST       index,search,update,rebuild-plain,rebuild (default all five). rebuild-plain
 *                       rebuilds the index as it is; rebuild switches an index that does not stem to
 *                       stemming (or, on one that stems, makes its stems again)
 *   --runs=N            Number of runs, each in its own process (default 3)
 *   --reps=N            Timed repeats of each query per run (default 5)
 *   --update-docs=N     Documents updated one by one, and then in batches (default 1000)
 *   --limit=N           Index only the first N movies (default all)
 *   --db=FILE           Database file (default: a file in the system temp directory, removed at the end)
 *   --movies=FILE       The movies (default benchmarks/movies.json)
 *   --lib=DIR           A checkout to benchmark instead of this one (needs vendor/autoload.php)
 *   --json=FILE         Also write the medians and every run as JSON
 */

const BATCH_SIZE = 250;
const INDEX_NAME = 'stemming_benchmark';

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $options[$m[1]] = $m[2] ?? '1';
    }
}

$cfg = [
    'stemming' => ($options['stemming'] ?? 'off') === 'on',
    'language' => $options['language'] ?? 'english',
    'external' => ($options['external'] ?? '1') !== '0',
    'phases' => explode(',', $options['phases'] ?? 'index,search,update,rebuild-plain,rebuild'),
    'runs' => max(1, (int)($options['runs'] ?? 3)),
    'reps' => max(1, (int)($options['reps'] ?? 5)),
    'update_docs' => max(1, (int)($options['update-docs'] ?? 1000)),
    'limit' => (int)($options['limit'] ?? 0),
    'movies' => $options['movies'] ?? __DIR__ . '/movies.json',
    'lib' => $options['lib'] ?? dirname(__DIR__),
];

if (isset($options['worker'])) {
    exit(runWorker($cfg, $options));
}

exit(runDriver($cfg, $options));

// ---------------------------------------------------------------------------
// Driver: starts the runs and reports medians
// ---------------------------------------------------------------------------

function runDriver(array $cfg, array $options): int
{
    if (!file_exists($cfg['movies'])) {
        fwrite(STDERR, "Movies file not found: {$cfg['movies']}\n");
        return 1;
    }

    $runs = [];
    for ($i = 1; $i <= $cfg['runs']; $i++) {
        $out = tempnam(sys_get_temp_dir(), 'stembench');
        $args = [];
        foreach ($options as $name => $value) {
            if (!in_array($name, ['runs', 'json', 'worker', 'out', 'db'], true)) {
                $args[] = escapeshellarg("--$name=$value");
            }
        }
        $db = isset($options['db']) ? $options['db'] : sys_get_temp_dir() . '/stembench-' . getmypid() . '.db';
        $command = escapeshellarg(PHP_BINARY) . ' -d memory_limit=2G ' . escapeshellarg(__FILE__)
            . ' --worker --out=' . escapeshellarg($out) . ' --db=' . escapeshellarg($db) . ' ' . implode(' ', $args);

        fwrite(STDERR, "run $i/{$cfg['runs']} ...\n");
        passthru($command, $status);
        if ($status !== 0) {
            fwrite(STDERR, "A run failed (exit $status)\n");
            return 1;
        }
        $runs[] = json_decode(file_get_contents($out), true);
        unlink($out);
    }

    $median = function (array $values): float {
        sort($values);
        $n = count($values);

        return $n % 2 ? (float)$values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    };
    $medianOf = function (callable $pick) use ($runs, $median) {
        $values = array_filter(array_map($pick, $runs), function ($v) {
            return $v !== null;
        });

        return $values ? $median($values) : null;
    };

    $summary = ['config' => $cfg + ['php' => PHP_VERSION], 'runs' => count($runs)];
    foreach (['index_seconds', 'index_docs_per_second', 'peak_memory_mb', 'db_size_mb'] as $key) {
        $summary['index'][$key] = $medianOf(function ($r) use ($key) {
            return $r['index'][$key] ?? null;
        });
    }
    foreach (['search_median_ms', 'search_p95_ms'] as $key) {
        $summary['search'][$key] = $medianOf(function ($r) use ($key) {
            return $r['search'][$key] ?? null;
        });
    }
    foreach (array_keys($runs[0]['search']['categories'] ?? []) as $category) {
        foreach (['median_ms', 'p95_ms'] as $key) {
            $summary['search']['categories'][$category][$key] = $medianOf(function ($r) use ($category, $key) {
                return $r['search']['categories'][$category][$key] ?? null;
            });
        }
    }
    foreach (($runs[0]['search']['queries'] ?? []) as $i => $q) {
        $summary['search']['queries'][$i] = [
            'category' => $q['category'],
            'query' => $q['query'],
            'total' => $q['total'],
            'median_ms' => $medianOf(function ($r) use ($i) {
                return $r['search']['queries'][$i]['median_ms'] ?? null;
            }),
        ];
    }
    foreach (['update_single_seconds', 'update_single_docs_per_second', 'update_batch_seconds', 'update_batch_docs_per_second'] as $key) {
        $summary['update'][$key] = $medianOf(function ($r) use ($key) {
            return $r['update'][$key] ?? null;
        });
    }
    $summary['rebuild_plain']['rebuild_seconds'] = $medianOf(function ($r) {
        return $r['rebuild_plain']['rebuild_seconds'] ?? null;
    });
    $summary['rebuild']['rebuild_seconds'] = $medianOf(function ($r) {
        return $r['rebuild']['rebuild_seconds'] ?? null;
    });
    $summary['rebuild']['action'] = $runs[0]['rebuild']['action'] ?? null;

    printSummary($summary, $runs);

    if (isset($options['json'])) {
        file_put_contents($options['json'], json_encode(['summary' => $summary, 'runs' => $runs], JSON_PRETTY_PRINT));
    }

    return 0;
}

function printSummary(array $s, array $runs): void
{
    $c = $s['config'];
    printf(
        "\nStemming benchmark | PHP %s | stemming %s%s | %s | median of %d run(s)\n",
        $c['php'],
        $c['stemming'] ? 'on' : 'off',
        $c['stemming'] ? " ({$c['language']})" : '',
        $c['external'] ? 'external content' : 'own-content',
        $s['runs']
    );
    echo str_repeat('-', 78) . "\n";

    if (!empty($s['index']['index_seconds'])) {
        printf("Indexing         %.2f s  %s docs/s  peak %.1f MB  database %.1f MB\n",
            $s['index']['index_seconds'], number_format($s['index']['index_docs_per_second']),
            $s['index']['peak_memory_mb'], $s['index']['db_size_mb']);
    }
    if (!empty($s['search']['search_median_ms'])) {
        printf("Search           median %.2f ms  p95 %.2f ms  (%d queries x %d reps)\n",
            $s['search']['search_median_ms'], $s['search']['search_p95_ms'], count($s['search']['queries']), $c['reps']);
        foreach ($s['search']['categories'] as $name => $v) {
            printf("  %-14s median %7.2f ms  p95 %7.2f ms\n", $name, $v['median_ms'], $v['p95_ms']);
        }
    }
    if (!empty($s['update']['update_single_seconds'])) {
        printf("Update one by one  %.2f s  %s docs/s\n", $s['update']['update_single_seconds'], number_format($s['update']['update_single_docs_per_second']));
        printf("Update in batches  %.2f s  %s docs/s\n", $s['update']['update_batch_seconds'], number_format($s['update']['update_batch_docs_per_second']));
    }
    if (!empty($s['rebuild_plain']['rebuild_seconds'])) {
        printf("rebuildFts (as is)  %.2f s\n", $s['rebuild_plain']['rebuild_seconds']);
    }
    if (!empty($s['rebuild']['rebuild_seconds'])) {
        printf("rebuildFts (%s)  %.2f s\n", $s['rebuild']['action'], $s['rebuild']['rebuild_seconds']);
    }
    echo "\nIndexing seconds per run: " . implode(', ', array_map(function ($r) {
        return isset($r['index']['index_seconds']) ? sprintf('%.2f', $r['index']['index_seconds']) : '-';
    }, $runs)) . "\n";
    if (!empty($s['search']['search_median_ms'])) {
        echo "Search median ms per run: " . implode(', ', array_map(function ($r) {
            return sprintf('%.2f', $r['search']['search_median_ms']);
        }, $runs)) . "\n";
    }
}

// ---------------------------------------------------------------------------
// Worker: one run, in its own process
// ---------------------------------------------------------------------------

/** @return array<int, array{0: string, 1: string, 2: array}> category, query, search options */
function queries(): array
{
    $plain = ['fuzzy' => false];
    $list = [];
    $add = function (string $category, array $queries, array $options) use (&$list) {
        foreach ($queries as $query) {
            $list[] = [$category, $query, $options];
        }
    };

    $add('single word', ['star', 'war', 'family', 'murder', 'detective', 'robot', 'ocean'], $plain);
    $add('inflection', ['running', 'movies', 'loved', 'killing', 'children', 'dancing', 'fights', 'travelled', 'secrets'], $plain);
    $add('two words', ['star wars', 'love story', 'running away', 'secret agent', 'young girls', 'haunted houses'], $plain);
    $add('three words', ['man running away', 'love and war', 'boys playing games', 'space battles against aliens'], $plain);
    $add('stop word', ['the lord of the rings', 'a man and his dog', 'what is love'], $plain);
    $add('prefix', ['tera', 'run', 'star w', 'dance mo'], $plain + ['prefix_last_token' => true]);
    $add('fuzzy', ['running', 'love stories', 'dancing queens'], ['fuzzy' => true]);
    $add('typo', ['matrx', 'terminater', 'runnig away', 'lov story'], ['fuzzy' => true]);
    $add('highlight', ['running', 'movies', 'love story', 'star wars', 'children playing'], $plain + ['highlight' => true]);

    return $list;
}

function percentile(array $values, float $p): float
{
    sort($values);
    $index = (int)ceil($p / 100 * count($values)) - 1;

    return (float)$values[max(0, min(count($values) - 1, $index))];
}

function runWorker(array $cfg, array $options): int
{
    require $cfg['lib'] . '/vendor/autoload.php';

    $dbFile = $options['db'];
    $removeDb = function () use ($dbFile) {
        foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
            if (file_exists($dbFile . $suffix)) {
                unlink($dbFile . $suffix);
            }
        }
    };
    $removeDb();

    $open = function () use ($cfg, $dbFile) {
        return new \YetiSearch\YetiSearch([
            'storage' => ['path' => $dbFile, 'external_content' => $cfg['external']],
            'indexer' => [
                'batch_size' => BATCH_SIZE,
                'fields' => [
                    'title' => ['boost' => 5.0, 'store' => true],
                    'overview' => ['boost' => 1.0, 'store' => true],
                    'genres' => ['boost' => 2.0, 'store' => true],
                ],
            ],
            'search' => [
                'enable_fuzzy' => true,
                'fuzzy_algorithm' => 'trigram',
                'trigram_threshold' => 0.3,
                'cache_ttl' => 0,
            ],
        ]);
    };

    $movies = json_decode(file_get_contents($cfg['movies']), true);
    if ($cfg['limit'] > 0) {
        $movies = array_slice($movies, 0, $cfg['limit']);
    }
    $document = function (array $movie, string $suffix = ''): array {
        return [
            'id' => 'movie_' . $movie['id'],
            'content' => [
                'title' => $movie['title'],
                'overview' => ($movie['overview'] ?? '') . $suffix,
                'genres' => is_array($movie['genres']) ? implode(', ', $movie['genres']) : '',
            ],
            'metadata' => ['original_id' => $movie['id']],
        ];
    };

    $result = ['php' => PHP_VERSION, 'index' => null, 'search' => null, 'update' => null, 'rebuild_plain' => null, 'rebuild' => null];
    $search = $open();
    $indexOptions = $cfg['stemming'] ? ['stemming' => true, 'language' => $cfg['language']] : [];
    $indexer = $search->createIndex(INDEX_NAME, $indexOptions);

    // Indexing
    if (in_array('index', $cfg['phases'], true)) {
        $batch = [];
        $start = hrtime(true);
        foreach ($movies as $i => $movie) {
            $batch[] = $document($movie);
            if (count($batch) >= BATCH_SIZE || $i === count($movies) - 1) {
                $indexer->insert($batch);
                $batch = [];
            }
        }
        $indexer->flush();
        $seconds = (hrtime(true) - $start) / 1e9;
        $peak = memory_get_peak_usage();
        // Closing writes the WAL back, so the size is that of the database
        $search->close();
        clearstatcache();
        $size = filesize($dbFile) + (file_exists($dbFile . '-wal') ? filesize($dbFile . '-wal') : 0);
        $result['index'] = [
            'documents' => count($movies),
            'index_seconds' => $seconds,
            'index_docs_per_second' => count($movies) / $seconds,
            'peak_memory_mb' => $peak / 1048576,
            'db_size_mb' => $size / 1048576,
        ];
        $search = $open();
        $indexer = $search->getIndex(INDEX_NAME);
    }

    // Search
    if (in_array('search', $cfg['phases'], true)) {
        $set = queries();
        $run = function (array $q) use ($search) {
            $t = hrtime(true);
            $res = $search->search(INDEX_NAME, $q[1], $q[2] + ['limit' => 10, 'bypass_cache' => true]);

            return [(hrtime(true) - $t) / 1e6, $res['total']];
        };
        foreach ($set as $q) {
            $run($q); // warm up the page cache and the prepared statements
        }
        $samples = [];
        $perQuery = [];
        $perCategory = [];
        for ($rep = 0; $rep < $cfg['reps']; $rep++) {
            foreach ($set as $i => $q) {
                [$ms, $total] = $run($q);
                $samples[] = $ms;
                $perQuery[$i]['times'][] = $ms;
                $perQuery[$i]['total'] = $total;
                $perCategory[$q[0]][] = $ms;
            }
        }
        $result['search'] = [
            'search_median_ms' => percentile($samples, 50),
            'search_p95_ms' => percentile($samples, 95),
            'categories' => [],
            'queries' => [],
        ];
        foreach ($perCategory as $name => $times) {
            $result['search']['categories'][$name] = ['median_ms' => percentile($times, 50), 'p95_ms' => percentile($times, 95)];
        }
        foreach ($set as $i => $q) {
            $result['search']['queries'][$i] = [
                'category' => $q[0],
                'query' => $q[1],
                'total' => $perQuery[$i]['total'],
                'median_ms' => percentile($perQuery[$i]['times'], 50),
            ];
        }
    }

    // Updates: existing documents, changed, one by one and then in batches
    if (in_array('update', $cfg['phases'], true) && $indexer !== null) {
        $n = min($cfg['update_docs'], intdiv(count($movies), 2));
        $one = array_slice($movies, 0, $n);
        $many = array_slice($movies, $n, $n);

        $start = hrtime(true);
        foreach ($one as $movie) {
            $indexer->update($document($movie, ' Updated one by one.'));
        }
        $single = (hrtime(true) - $start) / 1e9;

        $start = hrtime(true);
        foreach (array_chunk($many, BATCH_SIZE) as $chunk) {
            $indexer->insert(array_map(function ($movie) use ($document) {
                return $document($movie, ' Updated in a batch.');
            }, $chunk));
        }
        $indexer->flush();
        $batched = (hrtime(true) - $start) / 1e9;

        $result['update'] = [
            'documents' => $n,
            'update_single_seconds' => $single,
            'update_single_docs_per_second' => $n / $single,
            'update_batch_seconds' => $batched,
            'update_batch_docs_per_second' => $n / $batched,
        ];
    }

    // Rebuild the index as it is
    if (in_array('rebuild-plain', $cfg['phases'], true)) {
        $start = hrtime(true);
        $search->rebuildFts(INDEX_NAME);
        $result['rebuild_plain'] = ['rebuild_seconds' => (hrtime(true) - $start) / 1e9];
    }

    // Switch the index to stemming (or, on an index that stems, make its stems again)
    if (in_array('rebuild', $cfg['phases'], true)) {
        $method = new \ReflectionMethod($search, 'rebuildFts');
        $canSwitch = $method->getNumberOfParameters() >= 2;
        $rebuildOptions = [];
        $action = 'rebuild';
        if ($canSwitch && !$cfg['stemming']) {
            $rebuildOptions = ['stemming' => true, 'language' => $cfg['language']];
            $action = 'switch to stemming';
        }
        $start = hrtime(true);
        $canSwitch ? $search->rebuildFts(INDEX_NAME, $rebuildOptions) : $search->rebuildFts(INDEX_NAME);
        $result['rebuild'] = ['action' => $action, 'rebuild_seconds' => (hrtime(true) - $start) / 1e9];
    }

    $search->close();
    if (!isset($options['keep-db'])) {
        $removeDb();
    }
    file_put_contents($options['out'], json_encode($result));

    return 0;
}
