<?php

namespace YetiSearch\Tests\Integration\Storage;

use YetiSearch\Tests\TestCase;
use YetiSearch\Models\SearchQuery;

class MetadataFilterTest extends TestCase
{
    public function test_json_metadata_operators(): void
    {
        $search = $this->createSearchInstance();
        $index = 'meta_ops_idx';
        $this->createTestIndex($index);

        $docs = [
            [
                'id' => 'd1',
                'content' => ['title' => 'One'],
                'metadata' => ['author' => 'Alice', 'rating' => 4.2, 'tags' => ['t1','t2'], 'description' => 'Quick brown fox']
            ],
            [
                'id' => 'd2',
                'content' => ['title' => 'Two'],
                'metadata' => ['author' => 'Bob', 'rating' => 3.7, 'tags' => ['t2'], 'published' => true]
            ],
            [
                'id' => 'd3',
                'content' => ['title' => 'Three'],
                'metadata' => ['author' => 'Alice', 'rating' => 5.0, 'tags' => ['t3']]
            ],
        ];
        $search->indexBatch($index, $docs);
        $search->getIndexer($index)->flush();

        $engine = $search->getSearchEngine($index);

        // author = Alice
        $q = (new SearchQuery(''))->filter('metadata.author', 'Alice', '=');
        $this->assertSame(2, $engine->count($q));

        // author != Alice
        $q = (new SearchQuery(''))->filter('metadata.author', 'Alice', '!=');
        $this->assertSame(1, $engine->count($q));

        // rating > 4.0
        $q = (new SearchQuery(''))->filter('metadata.rating', 4.0, '>');
        $this->assertSame(2, $engine->count($q));

        // rating <= 4.2
        $q = (new SearchQuery(''))->filter('metadata.rating', 4.2, '<=');
        $this->assertSame(2, $engine->count($q));

        // IN operator for author
        $q = (new SearchQuery(''))->filter('metadata.author', ['Bob','Charlie'], 'in');
        $this->assertSame(1, $engine->count($q));

        // contains substring in description
        $q = (new SearchQuery(''))->filter('metadata.description', 'brown', 'contains');
        $this->assertSame(1, $engine->count($q));

        // exists published
        $q = (new SearchQuery(''))->filter('metadata.published', null, 'exists');
        $this->assertSame(1, $engine->count($q));
    }
}
