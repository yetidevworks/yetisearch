<?php

namespace YetiSearch\Tests\Integration\Semantic;

use YetiSearch\Semantic\CalibrationStore;
use YetiSearch\Semantic\NoiseCalibration;
use YetiSearch\Semantic\VectorMath;
use YetiSearch\Tests\Fixtures\NoisyEmbeddingProvider;
use YetiSearch\Tests\TestCase;
use YetiSearch\YetiSearch;

class CalibrationTest extends TestCase
{
    private const INDEX = 'calibration_test';

    /** Twelve short pages over five topics. */
    private const PAGES = [
        ['Choosing your first car', 'A sedan or truck: what to look for when you buy a vehicle.'],
        ['Weeknight pizza', 'A quick recipe for dinner with pasta.'],
        ['Locked out', 'Reset your password to get back into your account.'],
        ['Reading a forecast', 'Rain or a storm is coming.'],
        ['Truck maintenance', 'Keep your vehicle running.'],
        ['Guitar lessons', 'Learn chords and melody for any song.'],
        ['Sunny weekend', 'The weather forecast says sunny.'],
        ['Family dinner', 'A meal and food for everyone, cooking tips.'],
        ['Signin help', 'Credentials and login problems.'],
        ['Music night', 'A song and some music.'],
        ['Used automobiles', 'Buying cars second hand.'],
        ['Pasta basics', 'Cooking pasta for a meal.'],
    ];

    /**
     * An index whose configured min_margin (0.9) turns every query away, so
     * whether a search finds anything says which margin the gate used.
     */
    private function build(array $semantic = [], ?NoisyEmbeddingProvider $provider = null, ?string $path = null): YetiSearch
    {
        $config = [
            'search' => ['enable_fuzzy' => false, 'enable_suggestions' => false],
            'semantic' => array_merge(['min_margin' => 0.9], $semantic),
        ];
        if ($path !== null) {
            $config['storage'] = ['path' => $path];
        }
        $search = $this->createSearchInstance($config);
        $this->createTestIndex(self::INDEX);
        foreach (self::PAGES as $i => [$title, $content]) {
            $search->index(self::INDEX, ['id' => "p{$i}", 'content' => ['title' => $title, 'content' => $content]]);
        }
        $search->setEmbeddingProvider($provider ?? new NoisyEmbeddingProvider());

        return $search;
    }

    private function ids(array $response): array
    {
        return array_map(function ($r) {
            return $r['id'];
        }, $response['results']);
    }

    public function testEmbedPendingCalibratesWhenItFinishes(): void
    {
        $search = $this->build();

        $partial = $search->embedPending(self::INDEX, 5);
        $this->assertFalse($partial['calibrated']);
        $this->assertNull($search->calibration(self::INDEX));

        $done = $search->embedPending(self::INDEX);
        $this->assertTrue($done['calibrated']);
        $this->assertNull($done['calibration_error']);

        $calibration = $search->calibration(self::INDEX);
        $this->assertInstanceOf(NoiseCalibration::class, $calibration);
        $this->assertSame('fake-noisy', $calibration->model());
        $this->assertSame(12, $calibration->documents());
        $this->assertSame(88, $calibration->stats()['count']);
        $this->assertSame(22, $calibration->dimensions());
        $stats = $calibration->stats();
        $this->assertEqualsWithDelta(
            $stats['mean'] + 2 * $stats['sd'],
            $calibration->minMargin(),
            0.0002
        );

        // Nothing changed: the next run leaves the calibration alone.
        $this->assertFalse($search->embedPending(self::INDEX)['calibrated']);
    }

    public function testAutoUsesTheCalibratedMarginAndOffTheConfiguredOne(): void
    {
        $search = $this->build();
        $search->embedPending(self::INDEX);

        $gate = $search->semanticGate(self::INDEX);
        $this->assertSame('calibrated', $gate['source']);
        $this->assertSame(0.9, $gate['configured']);
        $this->assertLessThan(0.5, $gate['min_margin']);

        // A real query clears the calibrated margin; nonsense does not.
        $this->assertContains('p0', $this->ids($search->search(self::INDEX, 'automobile')));
        $this->assertSame([], $this->ids($search->search(self::INDEX, 'asdf')));
        $this->assertSame([], $this->ids($search->search(self::INDEX, 'qwerty')));

        // Off: the configured 0.9 again, which turns "automobile" away too.
        $off = $this->build(['calibration' => 'off'], null, $this->getTestDbPathOf($search));
        $this->assertNotNull($off->calibration(self::INDEX));
        $this->assertSame('configured', $off->semanticGate(self::INDEX)['source']);
        $this->assertSame([], $this->ids($off->search(self::INDEX, 'automobile')));
        // And off never calibrates on its own.
        $off->clearEmbeddings(self::INDEX);
        $this->assertFalse($off->embedPending(self::INDEX)['calibrated']);
        $this->assertNull($off->calibration(self::INDEX));
    }

    public function testWithoutACalibrationTheConfiguredMarginApplies(): void
    {
        $search = $this->build(['calibration' => 'off', 'min_margin' => 0.15]);
        $search->embedPending(self::INDEX);

        $this->assertNull($search->calibration(self::INDEX));
        $this->assertSame('configured', $search->semanticGate(self::INDEX)['source']);
        $this->assertContains('p0', $this->ids($search->search(self::INDEX, 'automobile')));
    }

    public function testAutoWithNoRecordSearchesExactlyAsOff(): void
    {
        $auto = $this->build(['min_margin' => 0.15]);
        $auto->embedPending(self::INDEX);
        $auto->getSearchEngine(self::INDEX)->getSemanticSearch()->getCalibrationStore()->deleteCalibration(self::INDEX);
        $this->assertNull($auto->calibration(self::INDEX));
        $off = $this->build(['min_margin' => 0.15, 'calibration' => 'off'], null, $this->getTestDbPathOf($auto));

        foreach (['automobile', 'pizza recipe', 'song', 'asdf', 'lorem ipsum', 'zzyzx', 'car'] as $query) {
            $this->assertSame(
                $off->search(self::INDEX, $query)['results'],
                $auto->search(self::INDEX, $query)['results'],
                "Results differ for '{$query}'"
            );
        }
        $off->close();
    }

    public function testASecondCalibrationCostsNoProviderCall(): void
    {
        $provider = new NoisyEmbeddingProvider();
        $search = $this->build([], $provider);
        $search->embedPending(self::INDEX);
        $calls = $provider->queryCalls();
        $this->assertSame(1, $calls, 'The 88 probes go to the provider in one call');

        $again = $search->calibrate(self::INDEX);

        $this->assertNotNull($again);
        $this->assertSame($calls, $provider->queryCalls());
        $this->assertSame($search->calibration(self::INDEX)->minMargin(), $again->minMargin());
    }

    public function testProbesSurviveTheQueryCacheAndDropIndex(): void
    {
        $provider = new NoisyEmbeddingProvider();
        $search = $this->build(['query_cache_size' => 1], $provider);
        $search->embedPending(self::INDEX);
        // Two searches fill and evict the one-entry query cache.
        $search->search(self::INDEX, 'automobile');
        $search->search(self::INDEX, 'pizza');

        $search->dropIndex(self::INDEX);
        $this->assertNull($search->calibration(self::INDEX));

        $calls = $provider->queryCalls();
        $rebuilt = $this->build(['query_cache_size' => 1], $provider, $this->getTestDbPathOf($search));
        $this->assertTrue($rebuilt->embedPending(self::INDEX)['calibrated']);
        $this->assertSame($calls, $provider->queryCalls());
    }

    public function testBelowTenDocumentsThereIsNothingToCalibrate(): void
    {
        $provider = new NoisyEmbeddingProvider();
        $search = $this->createSearchInstance();
        $this->createTestIndex(self::INDEX);
        $search->setEmbeddingProvider($provider);
        for ($i = 0; $i < 9; $i++) {
            $search->index(self::INDEX, ['id' => "d{$i}", 'content' => ['title' => "Car {$i}", 'content' => 'A vehicle.']]);
        }

        $this->assertFalse($search->embedPending(self::INDEX)['calibrated']);
        $this->assertNull($search->calibrate(self::INDEX));
        $this->assertSame(0, $provider->queryCalls());
    }

    public function testAModelChangeInvalidatesTheCalibration(): void
    {
        $search = $this->build();
        $search->embedPending(self::INDEX);

        $search->setEmbeddingProvider(new NoisyEmbeddingProvider('fake-noisy-v2'));
        $this->assertSame('configured', $search->semanticGate(self::INDEX)['source']);

        $run = $search->embedPending(self::INDEX);
        $this->assertTrue($run['calibrated']);
        $this->assertSame('fake-noisy-v2', $search->calibration(self::INDEX)->model());
        $this->assertSame('calibrated', $search->semanticGate(self::INDEX)['source']);
    }

    public function testClearEmbeddingsForgetsTheCalibration(): void
    {
        $search = $this->build();
        $search->embedPending(self::INDEX);
        $this->assertNotNull($search->calibration(self::INDEX));

        $search->clearEmbeddings(self::INDEX);

        $this->assertNull($search->calibration(self::INDEX));
    }

    public function testDocumentDriftStopsTheCalibrationApplyingUntilTheNextRun(): void
    {
        $search = $this->build();
        $search->embedPending(self::INDEX);

        for ($i = 0; $i < 5; $i++) {
            $search->index(self::INDEX, ['id' => "extra{$i}", 'content' => ['title' => "Storm {$i}", 'content' => 'Rain.']]);
        }
        // Three more vectors is a quarter of the twelve measured: still applies.
        $search->embedPending(self::INDEX, 3);
        $this->assertSame('calibrated', $search->semanticGate(self::INDEX)['source']);
        // A fourth is past it, and one page is still pending, so no run recalibrated.
        $this->assertFalse($search->embedPending(self::INDEX, 1)['calibrated']);
        $this->assertSame('configured', $search->semanticGate(self::INDEX)['source']);

        $run = $search->embedPending(self::INDEX);
        $this->assertTrue($run['calibrated']);
        $this->assertSame(17, $search->calibration(self::INDEX)->documents());
        $this->assertSame('calibrated', $search->semanticGate(self::INDEX)['source']);
    }

    public function testQueryFrameIsAppliedToQueriesAndProbesAndKeysTheCalibration(): void
    {
        $provider = new NoisyEmbeddingProvider();
        $search = $this->build(['query_frame' => 'a {query}'], $provider);
        $search->embedPending(self::INDEX);
        $search->search(self::INDEX, 'automobile');

        $queryTexts = [];
        foreach ($provider->calls as $call) {
            if ($call['purpose'] === 'query') {
                $queryTexts = array_merge($queryTexts, $call['texts']);
            }
        }
        $this->assertContains('a vbrtk', $queryTexts);
        $this->assertContains('a automobile', $queryTexts);
        $this->assertNotContains('vbrtk', $queryTexts);
        // Documents are never framed.
        foreach ($provider->calls as $call) {
            if ($call['purpose'] === 'document') {
                $this->assertStringStartsNotWith('a ', $call['texts'][0]);
            }
        }
        $this->assertSame('a {query}', $search->calibration(self::INDEX)->frame());
        $this->assertSame('calibrated', $search->semanticGate(self::INDEX)['source']);

        // Another frame: the stored calibration no longer applies, and the
        // document vectors are still good (nothing pending).
        $search->setEmbeddingProvider($provider, ['query_frame' => 'the {query}']);
        $this->assertSame('configured', $search->semanticGate(self::INDEX)['source']);
        $this->assertSame(0, $search->embeddingStats(self::INDEX)['pending']);
        $this->assertTrue($search->embedPending(self::INDEX)['calibrated']);
    }

    public function testTheGateMeasuresItsSubsetInTheSamePass(): void
    {
        $storage = $this->createStorageInstance();
        $storage->createIndex('gate');
        $rows = [];
        // Ten cards spread around the circle, and three prose documents close
        // to the query direction.
        for ($i = 0; $i < 10; $i++) {
            $angle = $i * M_PI / 5;
            $storage->insert('gate', ['id' => "card{$i}", 'type' => 'card', 'content' => ['content' => "card {$i}"]]);
            $rows[] = ['id' => "card{$i}", 'hash' => 'h', 'model' => 'm', 'vector' => [cos($angle), sin($angle)]];
        }
        foreach ([0.95, 0.9, 0.85] as $i => $x) {
            $storage->insert('gate', ['id' => "prose{$i}", 'type' => 'page', 'content' => ['content' => "prose {$i}"]]);
            $rows[] = ['id' => "prose{$i}", 'hash' => 'h', 'model' => 'm', 'vector' => VectorMath::normalize([$x, sqrt(1 - $x * $x)])];
        }
        $storage->upsertVectors('gate', $rows);
        $cards = [['field' => 'type', 'value' => 'card']];

        $all = $storage->nearestVectors('gate', [0.0, 1.0], 'm', [], null, 100, -1.0, $allStats);
        $subset = $storage->nearestVectors('gate', [0.0, 1.0], 'm', [], null, 100, -1.0, $cardStats, $cards);

        // Every document is ranked either way.
        $this->assertSame(array_keys($all), array_keys($subset));
        $this->assertCount(13, $subset);
        $this->assertSame(13, $allStats['total']);
        $this->assertSame(13, $cardStats['total']);
        $this->assertSame(13, $allStats['count']);
        $this->assertSame(10, $cardStats['count']);

        // The cards' own numbers: best at 90 degrees (card 2 or 3), median of their sines.
        $sines = [];
        for ($i = 0; $i < 10; $i++) {
            $sines[] = sin($i * M_PI / 5);
        }
        sort($sines);
        $this->assertEqualsWithDelta($sines[5], $cardStats['median'], 1e-6);
        $this->assertEqualsWithDelta(max($sines), $cardStats['best'], 1e-6);
        $this->assertNotEqualsWithDelta($allStats['median'], $cardStats['median'], 1e-3);

        // A subset nothing belongs to leaves the gate with no numbers.
        $storage->nearestVectors('gate', [0.0, 1.0], 'm', [], null, 100, -1.0, $none, [['field' => 'type', 'value' => 'nope']]);
        $this->assertSame(0, $none['count']);
        $this->assertNull($none['best']);
        $this->assertSame(13, $none['total']);

        $this->assertSame(10, $storage->countVectors('gate', 'm', $cards));
        $this->assertCount(10, iterator_to_array($storage->iterateVectors('gate', 'm', $cards), false));
        $storage->dropIndex('gate');
    }

    public function testGateFiltersDecideOnTheSubsetAndRankEverything(): void
    {
        // Twelve cards that each say what one thing is, and twenty digests
        // that are mostly about vehicles, so over every document the median
        // sits right under the best match.
        $search = $this->createSearchInstance([
            'search' => ['enable_fuzzy' => false, 'enable_suggestions' => false],
            'semantic' => [
                'calibration' => 'off',
                'min_margin' => 0.3,
                'relative_similarity' => 0.0,
                'gate_filters' => [['field' => 'type', 'value' => 'card']],
            ],
        ]);
        $this->createTestIndex(self::INDEX);
        $things = ['car', 'pizza', 'password', 'rain', 'guitar', 'truck', 'pasta', 'login', 'storm', 'song', 'sedan', 'meal'];
        foreach ($things as $i => $thing) {
            $search->index(self::INDEX, ['id' => "card{$i}", 'type' => 'card', 'content' => ['title' => $thing, 'content' => $thing]]);
        }
        for ($i = 0; $i < 20; $i++) {
            $search->index(self::INDEX, ['id' => "page{$i}", 'type' => 'page', 'content' => [
                'title' => "Digest {$i}",
                'content' => 'car truck vehicle pizza',
            ]]);
        }
        $search->setEmbeddingProvider(new \YetiSearch\Tests\Fixtures\FakeEmbeddingProvider());
        $search->embedPending(self::INDEX);

        // Over the cards, "automobile" stands out (car, truck, sedan), so
        // meaning takes part, and the digests are ranked too.
        $ids = $this->ids($search->search(self::INDEX, 'automobile', ['limit' => 50]));
        $this->assertContains('card0', $ids);
        $this->assertContains('page0', $ids);

        // Without the subset the digests are the median, too close to the
        // best card for the same margin, and meaning adds nothing.
        $plain = $this->createSearchInstance([
            'storage' => ['path' => $this->getTestDbPathOf($search)],
            'search' => ['enable_fuzzy' => false, 'enable_suggestions' => false],
            'semantic' => ['calibration' => 'off', 'min_margin' => 0.3, 'relative_similarity' => 0.0],
        ]);
        $plain->setEmbeddingProvider(new \YetiSearch\Tests\Fixtures\FakeEmbeddingProvider());
        $this->assertSame([], $this->ids($plain->search(self::INDEX, 'automobile')));
        $plain->close();
    }

    public function testACustomCalibrationStore(): void
    {
        $store = new class implements CalibrationStore {
            public array $calibrations = [];
            public array $probes = [];

            public function loadCalibration(string $index): ?array
            {
                return $this->calibrations[$index] ?? null;
            }

            public function saveCalibration(string $index, array $record): void
            {
                $this->calibrations[$index] = $record;
            }

            public function deleteCalibration(string $index): void
            {
                unset($this->calibrations[$index]);
            }

            public function loadProbeVectors(string $model, string $frame = ''): array
            {
                return $this->probes[$model . '|' . $frame] ?? [];
            }

            public function saveProbeVectors(string $model, string $frame, array $vectors): void
            {
                $this->probes[$model . '|' . $frame] = $vectors + ($this->probes[$model . '|' . $frame] ?? []);
            }
        };

        $search = $this->build(['calibration_store' => $store]);
        $search->embedPending(self::INDEX);

        $this->assertArrayHasKey(self::INDEX, $store->calibrations);
        $this->assertCount(88, $store->probes['fake-noisy|']);
        $this->assertSame('calibrated', $search->semanticGate(self::INDEX)['source']);

        $search->clearEmbeddings(self::INDEX);
        $this->assertSame([], $store->calibrations);
    }

    public function testTheStorageRoundTrip(): void
    {
        $storage = $this->createStorageInstance();

        $this->assertNull($storage->loadCalibration('things'));
        $this->assertSame([], $storage->loadProbeVectors('m'));
        $storage->deleteCalibration('things');

        $record = NoiseCalibration::fromMeasurement('m', 2, 10, '', [], ['vbrtk' => ['best' => 0.4, 'median' => 0.1, 'margin' => 0.3]], 5)->toArray();
        $storage->saveCalibration('things', $record);
        $this->assertSame($record, NoiseCalibration::fromArray($storage->loadCalibration('things'))->toArray());

        $storage->saveProbeVectors('m', '', ['vbrtk' => [0.6, 0.8]]);
        $storage->saveProbeVectors('m', 'a {query}', ['a vbrtk' => [1.0, 0.0]]);
        $this->assertEqualsWithDelta([0.6, 0.8], $storage->loadProbeVectors('m')['vbrtk'], 1e-6);
        $this->assertSame(['a vbrtk'], array_keys($storage->loadProbeVectors('m', 'a {query}')));
        $this->assertSame([], $storage->loadProbeVectors('other'));

        $storage->deleteCalibration('things');
        $this->assertNull($storage->loadCalibration('things'));
        $storage->disconnect();
    }

    private function getTestDbPathOf(YetiSearch $search): string
    {
        $config = (new \ReflectionClass($search))->getProperty('config');
        $config->setAccessible(true);

        return $config->getValue($search)['storage']['path'];
    }
}
