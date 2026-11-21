<?php

namespace YetiSearch\Tests\Integration\Search;

use YetiSearch\Search\SearchEngine;
use YetiSearch\Tests\TestCase;

/**
 * How a fuzzy search reads a typo: as the likeliest slip of a word in the index, rare words
 * included; with the words around it; and a last word cut short as the beginning of words.
 * The lexicon holds only the commonest terms here, as a large index's does.
 */
class TypoCorrectionTest extends TestCase
{
    private const INDEX = 'typo_idx';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSearchInstance([
            'storage' => ['external_content' => true],
            'search' => ['enable_fuzzy' => true, 'cache_ttl' => 0, 'min_score' => 0.0, 'max_indexed_terms' => 40],
        ]);
        $this->createTestIndex(self::INDEX);

        $documents = [
            ['forrest', 'Forrest Gump', 'A man with a low IQ runs through history'],
            ['diehard', 'Die Hard', 'A cop fights terrorists in a tower'],
            ['herd', 'The Last Herd', 'A herd of elephants crosses the plain'],
            ['panther', 'Black Panther', 'The king of Wakanda returns home'],
            ['bleak', 'Bleak House', 'A long case in a bleak court'],
            ['supermen', 'Supermen of the Ring', 'Wrestlers who call themselves supermen'],
            ['aquamarine', 'Aquamarine', 'A mermaid washes up at a beach club'],
            ['terminator', 'The Terminator', 'A cyborg is sent back in time'],
            ['terminal', 'The Terminal', 'A traveller is stuck in an airport terminal'],
        ];
        // Common words, so that they make the lexicon and the rare ones above do not
        for ($i = 0; $i < 24; $i++) {
            $documents[] = ['common' . $i, 'Common story ' . $i, 'A night of hard black gum and a superman at night, the ninth'];
        }
        $this->search->indexBatch(self::INDEX, array_map(static function (array $d): array {
            return ['id' => $d[0], 'content' => ['title' => $d[1], 'content' => $d[2]]];
        }, $documents));
    }

    private function ids(string $query): array
    {
        return array_column($this->search->search(self::INDEX, $query, ['fuzzy' => true, 'limit' => 20])['results'], 'id');
    }

    public function test_a_rare_word_of_the_index_is_not_corrected_to_a_common_one(): void
    {
        // 'gump' is in one document and 'gum' in 24
        $this->assertSame('forrest', $this->ids('gump')[0]);
    }

    public function test_a_rare_word_is_searched_beside_a_much_commoner_reading(): void
    {
        $ids = $this->ids('supermen');

        $this->assertSame('supermen', $ids[0]);
        $this->assertContains('common0', $ids); // superman
    }

    public function test_a_typo_is_corrected_to_a_rare_word_of_the_index(): void
    {
        $this->assertSame('aquamarine', $this->ids('aquamaine')[0]);
    }

    public function test_words_never_near_each_other_are_read_as_words_that_are(): void
    {
        $this->assertSame('diehard', $this->ids('die herd')[0]);
        $this->assertSame('panther', $this->ids('blek panther')[0]);
        $this->assertSame('forrest', $this->ids('froest gump')[0]);
    }

    public function test_a_word_alone_is_kept_when_it_is_in_the_index(): void
    {
        // 'herd' is no slip of 'her', a stop word, nor of 'hard'
        $this->assertSame('herd', $this->ids('herd')[0]);
    }

    public function test_a_last_word_cut_short_finds_every_word_it_begins(): void
    {
        $ids = $this->ids('termin');

        $this->assertContains('terminator', $ids);
        $this->assertContains('terminal', $ids);
    }

    /**
     * @dataProvider slips
     */
    public function test_how_likely_a_slip_is(string $typed, string $meant, float $likelihood): void
    {
        $method = new \ReflectionMethod(SearchEngine::class, 'editLikelihood');
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        $this->assertSame($likelihood, $method->invoke(null, $typed, $meant));
    }

    public function slips(): array
    {
        return [
            'two letters swapped' => ['nigth', 'night', 1.0],
            'a letter left out' => ['batmn', 'batman', 1.0],
            'a letter typed twice' => ['matrixx', 'matrix', 1.0],
            'a key beside its neighbour' => ['gsale', 'gale', 1.0],
            'a key beside the one meant' => ['butlet', 'butler', 1.0],
            'another vowel' => ['iren', 'iron', 1.0],
            'any other letter' => ['butlet', 'bullet', 0.3],
            'any other letter too many' => ['gsale', 'sale', 0.3],
            'two edits' => ['froest', 'forrest', 0.0],
            'the same word' => ['night', 'night', 0.0],
            'characters, not bytes' => ['naïev', 'naïve', 1.0],
        ];
    }
}
