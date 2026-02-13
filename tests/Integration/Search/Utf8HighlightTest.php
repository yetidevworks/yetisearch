<?php

namespace YetiSearch\Tests\Integration\Search;

use YetiSearch\Tests\TestCase;

class Utf8HighlightTest extends TestCase
{
    public function test_utf8_highlight_and_snippet_generation(): void
    {
        $search = $this->createSearchInstance([
            'indexer' => [
                'fields' => [
                    'title' => ['boost' => 3.0, 'store' => true],
                    'content' => ['boost' => 1.0, 'store' => true],
                ],
            ],
        ]);

        $index = 'utf8_highlight_idx';
        $this->createTestIndex($index);

        $search->index($index, [
            'id' => 'd1',
            'content' => [
                'title' => 'Crème brûlée guide',
                'content' => 'Recette de crème brûlée et café torréfié.',
            ],
        ]);

        $res = $search->search($index, 'brûlée', ['highlight' => true, 'limit' => 5]);
        $this->assertNotEmpty($res['results']);
        $this->assertArrayHasKey('highlights', $res['results'][0]);

        $highlights = $res['results'][0]['highlights'];
        $flat = json_encode($highlights, JSON_UNESCAPED_UNICODE);
        $this->assertIsString($flat);
        $this->assertStringContainsString('<mark>', $flat);
        $this->assertStringContainsString('brûlée', mb_strtolower($flat, 'UTF-8'));
    }
}
