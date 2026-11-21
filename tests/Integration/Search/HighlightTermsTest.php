<?php

namespace YetiSearch\Tests\Integration\Search;

use YetiSearch\Tests\TestCase;
use YetiSearch\YetiSearch;

/**
 * Highlights mark every query term once, in one pass over the snippet.
 */
class HighlightTermsTest extends TestCase
{
    private const INDEX = 'highlight_terms';

    private function searchWith(array $document): YetiSearch
    {
        $search = $this->createSearchInstance(['search' => ['cache_ttl' => 0]]);
        $this->createTestIndex(self::INDEX);
        $search->index(self::INDEX, ['id' => 'd1', 'content' => $document]);

        return $search;
    }

    private function highlights(YetiSearch $search, string $query, array $options = []): array
    {
        $results = $search->search(self::INDEX, $query, $options + ['highlight' => true, 'limit' => 1]);
        $this->assertNotEmpty($results['results'], "'$query' finds the document");

        return $results['results'][0]['highlights'];
    }

    public function test_a_term_named_like_the_tag_is_not_marked_inside_the_tags(): void
    {
        $search = $this->searchWith(['title' => 'Mark Twain wrote about the river', 'content' => 'Mark my words.']);

        $highlights = $this->highlights($search, 'mark twain');

        $this->assertSame('<mark>Mark</mark> <mark>Twain</mark> wrote about the river', $highlights['title']);
        $this->assertSame('<mark>Mark</mark> my words.', $highlights['content']);
    }

    public function test_words_that_start_or_end_with_a_letter_outside_ascii_are_marked(): void
    {
        $search = $this->searchWith(['title' => 'Über alles', 'content' => 'Kein Ärger mit dem Café am Fluss.']);

        $this->assertSame('<mark>Über</mark> alles', $this->highlights($search, 'über')['title']);
        $this->assertSame('Kein <mark>Ärger</mark> mit dem Café am Fluss.', $this->highlights($search, 'ärger')['content']);
        $this->assertSame('Kein Ärger mit dem <mark>Café</mark> am Fluss.', $this->highlights($search, 'café')['content']);
    }

    public function test_a_custom_tag_is_inserted_as_it_is(): void
    {
        $search = $this->createSearchInstance(['search' => [
            'cache_ttl' => 0,
            'highlight_tag' => '<b class="$1 \\1">',
            'highlight_tag_close' => '</b>',
        ]]);
        $this->createTestIndex(self::INDEX);
        $search->index(self::INDEX, ['id' => 'd1', 'content' => ['title' => 'Rocket launch', 'content' => 'x']]);

        $this->assertSame('<b class="$1 \\1">Rocket</b> launch', $this->highlights($search, 'rocket')['title']);
    }

    public function test_a_snippet_length_that_three_does_not_divide_cuts_the_snippet_without_a_deprecation(): void
    {
        $search = $this->searchWith([
            'title' => 'Notes',
            'content' => 'Somewhere far down this rather long line of text the keyword rocket appears once.',
        ]);

        // PHPUnit turns the implicit float-to-int deprecation into an exception
        $query = $search->query(self::INDEX, ['highlight' => true, 'snippet_length' => 20, 'limit' => 1]);
        $results = $search->execute($query->setQuery('rocket'), self::INDEX);

        $this->assertStringContainsString('<mark>rocket</mark>', $results['results'][0]['highlights']['content']);
    }
}
