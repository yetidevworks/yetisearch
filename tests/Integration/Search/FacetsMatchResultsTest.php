<?php

namespace YetiSearch\Tests\Integration\Search;

use YetiSearch\Tests\TestCase;
use YetiSearch\YetiSearch;

/**
 * Facets count the documents the results come from: the query as it was searched (corrected,
 * escaped), with its filters and its location.
 */
class FacetsMatchResultsTest extends TestCase
{
    private const INDEX = 'facets_match_idx';

    private function stories(bool $externalContent): YetiSearch
    {
        $this->createSearchInstance([
            'storage' => ['external_content' => $externalContent],
            'search' => ['enable_fuzzy' => true, 'cache_ttl' => 0, 'min_score' => 0.0],
        ]);
        $this->createTestIndex(self::INDEX);

        $places = [[48.8566, 2.3522], [45.7640, 4.8357], [52.5200, 13.4050]];
        $documents = [];
        for ($i = 0; $i < 30; $i++) {
            $documents[] = [
                'id' => "story{$i}",
                'content' => ['title' => "Drama story number {$i}"],
                'metadata' => ['genre' => ['drama', 'comedy', 'thriller'][$i % 3]],
                'geo' => ['lat' => $places[$i % 3][0], 'lng' => $places[$i % 3][1]],
            ];
        }
        $this->search->indexBatch(self::INDEX, $documents);

        return $this->search;
    }

    private static function counts(array $results): array
    {
        return array_column($results['facets']['genre'] ?? [], 'count', 'value');
    }

    public function schemaProvider(): array
    {
        return ['legacy schema' => [false], 'external content schema' => [true]];
    }

    /**
     * @dataProvider schemaProvider
     */
    public function test_a_corrected_typo_is_counted_as_it_was_searched(bool $externalContent): void
    {
        $search = $this->stories($externalContent);

        $results = $search->search(self::INDEX, 'dramma', ['fuzzy' => true, 'facets' => ['genre']]);

        $this->assertSame(30, $results['total']);
        $this->assertSame(['drama' => 10, 'comedy' => 10, 'thriller' => 10], self::counts($results));
    }

    /**
     * @dataProvider schemaProvider
     */
    public function test_a_location_filter_limits_the_counts(bool $externalContent): void
    {
        $search = $this->stories($externalContent);

        $results = $search->search(self::INDEX, 'drama', [
            'facets' => ['genre'],
            'geoFilters' => ['near' => ['point' => ['lat' => 48.8566, 'lng' => 2.3522], 'radius' => 50000]],
        ]);

        $this->assertSame(10, $results['total']);
        $this->assertSame(['drama' => 10], self::counts($results));
    }

    /**
     * @dataProvider schemaProvider
     */
    public function test_a_query_with_fts_syntax_in_it_is_counted(bool $externalContent): void
    {
        $search = $this->stories($externalContent);

        $results = $search->search(self::INDEX, '"drama', ['facets' => ['genre']]);

        $this->assertSame(30, $results['total']);
        $this->assertSame(['drama' => 10, 'comedy' => 10, 'thriller' => 10], self::counts($results));
    }

    /**
     * @dataProvider schemaProvider
     */
    public function test_a_distance_facet_buckets_only_the_documents_near_keeps(bool $externalContent): void
    {
        $search = $this->stories($externalContent);
        $paris = ['lat' => 48.8566, 'lng' => 2.3522];

        $results = $search->search(self::INDEX, 'drama', [
            'facets' => ['distance' => ['from' => $paris, 'ranges' => [1, 500], 'units' => 'km']],
            'geoFilters' => ['near' => ['point' => $paris, 'radius' => 500000]],
        ]);

        // Paris (10) and Lyon (10, about 390 km away) are within the radius; Berlin is not
        $this->assertSame(20, $results['total']);
        $this->assertSame(
            [['value' => '<= 1 km', 'count' => 10], ['value' => '<= 500 km', 'count' => 10]],
            $results['facets']['distance']
        );
    }
}
