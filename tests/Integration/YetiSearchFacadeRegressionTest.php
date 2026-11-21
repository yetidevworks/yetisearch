<?php

namespace YetiSearch\Tests\Integration;

use YetiSearch\Geo\GeoBounds;
use YetiSearch\Geo\GeoPoint;
use YetiSearch\Tests\TestCase;
use YetiSearch\YetiSearch;

class YetiSearchFacadeRegressionTest extends TestCase
{
    private function ids(array $results): array
    {
        $ids = array_column($results['results'], 'id');
        sort($ids);

        return $ids;
    }

    private function places(): YetiSearch
    {
        $search = $this->createSearchInstance();
        $this->createTestIndex('places');
        $search->indexBatch('places', [
            ['id' => 'sf', 'content' => ['title' => 'cafe sf'], 'geo' => ['lat' => 37.7749, 'lng' => -122.4194]],
            ['id' => 'oak', 'content' => ['title' => 'cafe oakland'], 'geo' => ['lat' => 37.8044, 'lng' => -122.2712]],
            ['id' => 'ny', 'content' => ['title' => 'cafe ny'], 'geo' => ['lat' => 40.7128, 'lng' => -74.0060]],
        ]);

        return $search;
    }

    public function testSearchTakesGeoPointAndGeoBoundsObjects(): void
    {
        $search = $this->places();
        $point = new GeoPoint(37.77, -122.41);
        $bounds = new GeoBounds(38, 37, -122, -123);

        $near = $search->search('places', 'cafe', ['geoFilters' => ['near' => ['point' => $point, 'radius' => 20000]]]);
        $this->assertSame(['oak', 'sf'], $this->ids($near));

        foreach ([$bounds, ['bounds' => $bounds]] as $within) {
            $this->assertSame(['oak', 'sf'], $this->ids($search->search('places', 'cafe', ['geoFilters' => ['within' => $within]])));
        }

        $sorted = $search->search('places', 'cafe', ['geoFilters' => ['distance_sort' => ['from' => new GeoPoint(40.7, -74.0)]]]);
        $this->assertSame('ny', $sorted['results'][0]['id']);
    }

    public function testQueryBoundsAreMinAndMax(): void
    {
        $search = $this->places();
        $query = $search->query('places', ['geo_filters' => ['bounds' => [
            'min_lat' => 37.0, 'min_lng' => -123.0, 'max_lat' => 38.0, 'max_lng' => -122.0,
        ]]])->setQuery('cafe');

        $this->assertSame(
            ['north' => 38.0, 'south' => 37.0, 'east' => -122.0, 'west' => -123.0],
            $query->getGeoFilters()['within']['bounds']->toArray()
        );
        $this->assertSame(['oak', 'sf'], $this->ids($search->execute($query, 'places')));
    }

    public function testExecuteUsesTheEngineSettingsQueryPutOnTheQuery(): void
    {
        $search = $this->createSearchInstance();
        $this->createTestIndex('words');
        $search->indexBatch('words', [
            ['id' => 'a', 'content' => ['title' => 'alphabet soup']],
            ['id' => 'b', 'content' => ['title' => 'beta']],
        ]);

        $query = $search->query('words', ['fuzzy' => ['enabled' => false, 'prefix_last_token' => true]])->setQuery('alph');

        $this->assertSame(['a'], $this->ids($search->execute($query, 'words')));
    }

    public function testBoostIsAnotherNameForFieldWeights(): void
    {
        $search = $this->createSearchInstance();
        $this->createTestIndex('zebras');
        $search->indexBatch('zebras', [
            ['id' => 't1', 'content' => ['title' => 'zebra', 'content' => 'plain text']],
            ['id' => 't2', 'content' => ['title' => 'plain', 'content' => 'zebra zebra zebra']],
        ]);

        foreach (['boost', 'field_weights'] as $option) {
            $title = $search->search('zebras', 'zebra', [$option => ['title' => 10, 'content' => 0.1]]);
            $body = $search->search('zebras', 'zebra', [$option => ['title' => 0.1, 'content' => 10]]);
            $this->assertSame('t1', $title['results'][0]['id'], $option);
            $this->assertSame('t2', $body['results'][0]['id'], $option);

            $query = $search->query('zebras', [$option => ['title' => 10, 'content' => 0.1]])->setQuery('zebra');
            $this->assertSame('t1', $search->execute($query, 'zebras')['results'][0]['id'], "query() $option");
        }
    }

    public function testSnippetLengthOptionsSetTheHighlightLength(): void
    {
        $search = $this->createSearchInstance();
        $this->createTestIndex('long');
        $filler = str_repeat('filler words here ', 30);
        $search->indexBatch('long', [['id' => 'a', 'content' => ['title' => 'other', 'content' => $filler . ' needle ' . $filler]]]);

        $length = function (array $results): int {
            return mb_strlen(strip_tags($results['results'][0]['highlights']['content']));
        };

        $this->assertGreaterThan(100, $length($search->search('long', 'needle')));
        foreach (['snippet_length', 'highlight_length'] as $option) {
            $this->assertLessThan(40, $length($search->search('long', 'needle', [$option => 20, 'highlight' => true])), $option);
        }
    }

    public function testIndexDocumentWithStringContentIsSearchable(): void
    {
        $search = $this->createSearchInstance();
        $this->createTestIndex('plain');

        $search->indexDocument('plain', 'x', 'alpha text only');

        $this->assertSame(['x'], $this->ids($search->search('plain', 'alpha')));
    }

    public function testCacheMethodsReachTheStorageQueryCache(): void
    {
        $path = $this->getTestDbPath();
        $search = $this->createSearchInstance(['storage' => ['path' => $path], 'cache' => ['enabled' => true]]);
        $this->createTestIndex('cached');
        $search->indexBatch('cached', [['id' => 'a', 'content' => ['title' => 'alpha one']]]);

        $pdo = new \PDO('sqlite:' . $path);
        $rows = function () use ($pdo): int {
            return (int)$pdo->query("SELECT COUNT(*) FROM _query_cache WHERE index_name = 'cached'")->fetchColumn();
        };

        $search->search('cached', 'alpha');
        $this->assertSame(1, $rows());
        $this->assertNotNull($search->getCacheStats()['query_cache']);
        $this->assertTrue($search->getCacheInfo()['query_cache']['enabled']);

        $search->clearCache();
        $this->assertSame(0, $rows());

        $warmed = $search->warmUpCache('cached', ['alpha', ['query' => 'one', 'limit' => 5]]);
        $this->assertSame(2, $warmed['queries_warmed']);
        $this->assertSame(2, $rows());

        $search->dropIndex('cached');
        $this->assertSame(0, $rows());
    }
}
