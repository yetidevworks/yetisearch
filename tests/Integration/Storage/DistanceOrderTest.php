<?php

namespace YetiSearch\Tests\Integration\Storage;

use YetiSearch\Geo\GeoPoint;
use YetiSearch\Storage\SqliteStorage;
use YetiSearch\Tests\TestCase;

/**
 * A distance sort orders every match by distance, however many there are and wherever they rank by
 * text, and the nearest documents are those of the language asked for.
 */
class DistanceOrderTest extends TestCase
{
    private function storage(bool $external): SqliteStorage
    {
        $storage = new SqliteStorage();
        $storage->connect(['path' => ':memory:', 'external_content' => $external]);
        $storage->createIndex('g');

        return $storage;
    }

    private function hasMathFunctions(SqliteStorage $storage): bool
    {
        $property = new \ReflectionProperty(SqliteStorage::class, 'hasMathFunctions');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }

        return (bool)$property->getValue($storage);
    }

    /** @return string[] */
    private function ids(array $results): array
    {
        return array_column($results, 'id');
    }

    public function schemaModes(): array
    {
        return ['external' => [true], 'legacy' => [false]];
    }

    /**
     * 600 shops: shop{i} is (600 - i) / 1000 degrees north of the center, so the last ones written
     * (which rank last by text, all being equal) are the nearest.
     *
     * @dataProvider schemaModes
     */
    public function test_the_nearest_of_many_matches_come_first(bool $external): void
    {
        $storage = $this->storage($external);
        if (!$this->hasMathFunctions($storage)) {
            $this->markTestSkipped('Without SQL math functions a distance sort orders a capped set of candidates in PHP');
        }
        $documents = [];
        for ($i = 0; $i < 600; $i++) {
            $documents[] = [
                'id' => "shop$i",
                'content' => ['content' => 'coffee'],
                'language' => 'en',
                'geo' => ['lat' => 37.0 + (600 - $i) * 0.001, 'lng' => -122.0],
            ];
        }
        foreach (array_chunk($documents, 200) as $chunk) {
            $storage->insertBatch('g', $chunk);
        }
        $center = new GeoPoint(37.0, -122.0);
        $sorted = ['distance_sort' => ['from' => $center, 'direction' => 'ASC']];
        $near = ['near' => ['point' => $center, 'radius' => 100000]] + $sorted;

        foreach (['coffee', ''] as $text) {
            foreach (['distance sort' => $sorted, 'near and distance sort' => $near] as $label => $geo) {
                $query = ['query' => $text, 'geoFilters' => $geo, 'limit' => 3];
                $this->assertSame(['shop599', 'shop598', 'shop597'], $this->ids($storage->search('g', $query)), "$label, text '$text'");
                $this->assertSame(['shop596', 'shop595', 'shop594'], $this->ids($storage->search('g', $query + ['offset' => 3])), "$label, text '$text', second page");
            }
            $farthest = ['query' => $text, 'geoFilters' => ['distance_sort' => ['from' => $center, 'direction' => 'DESC']], 'limit' => 3];
            $this->assertSame(['shop0', 'shop1', 'shop2'], $this->ids($storage->search('g', $farthest)), "farthest first, text '$text'");
            $bySort = ['query' => $text, 'geoFilters' => $near, 'sort' => ['distance' => 'asc'], 'limit' => 2];
            unset($bySort['geoFilters']['distance_sort']);
            $this->assertSame(['shop599', 'shop598'], $this->ids($storage->search('g', $bySort)), "sort by distance, text '$text'");
        }
    }

    /** @dataProvider schemaModes */
    public function test_the_nearest_documents_are_of_the_language_asked_for(bool $external): void
    {
        $storage = $this->storage($external);
        $documents = [];
        for ($i = 0; $i < 10; $i++) {
            $documents[] = [
                'id' => "sf$i",
                'content' => ['content' => 'coffee shop'],
                'language' => $i < 5 ? 'en' : 'fr',
                'geo' => ['lat' => 37.7749, 'lng' => -122.4194 + $i * 0.01],
            ];
        }
        $storage->insertBatch('g', $documents);
        $nearest = ['geoFilters' => ['nearest' => ['k' => 4, 'from' => new GeoPoint(37.7749, -122.4194)]]];

        $this->assertSame(['sf0', 'sf1', 'sf2', 'sf3'], $this->ids($storage->search('g', $nearest)));
        $this->assertSame(['sf5', 'sf6', 'sf7', 'sf8'], $this->ids($storage->search('g', $nearest + ['language' => 'fr'])));
        foreach ($storage->search('g', $nearest) as $result) {
            $this->assertSame(0, $result['score'], 'Nothing is matched, so nothing is scored');
        }
    }
}
