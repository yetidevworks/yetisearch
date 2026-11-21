<?php

namespace YetiSearch\Tests\Unit\Analyzers;

use PHPUnit\Framework\TestCase;
use YetiSearch\Analyzers\StandardAnalyzer;

/**
 * How the analyzer reads text: contractions, apostrophes, HTML, accents and invalid UTF-8.
 */
class StandardAnalyzerTextHandlingTest extends TestCase
{
    private function tokens(string $text, array $config = []): array
    {
        $analyzer = new StandardAnalyzer($config + ['disable_stop_words' => true]);

        return $analyzer->tokenize($analyzer->normalize($text));
    }

    /** @return array<string, array{string, string[]}> */
    public function names(): array
    {
        return [
            "O'Reilly" => ["O'Reilly wrote", ["o'reilly", 'wrote']],
            "O'Donnell" => ["O'Donnell", ["o'donnell"]],
            "O'Malley and D'Angelo" => ["O'Malley and D'Angelo", ["o'malley", 'and', "d'angelo"]],
            'quoted dogs' => ["the 'dogs' ran", ['the', "'dogs'", 'ran']],
            'quoted redo' => ["'redo' it", ["'redo'", 'it']],
            'curly quoted' => ["\u{2018}mother\u{2019} and \u{2018}redo\u{2019}", ["'mother'", 'and', "'redo'"]],
        ];
    }

    /** @dataProvider names */
    public function testApostrophesInNamesAndQuotesAreNotContractions(string $text, array $expected): void
    {
        $this->assertSame($expected, $this->tokens($text));
    }

    /** @return array<string, array{string, string[]}> */
    public function contractions(): array
    {
        return [
            "I'd" => ["I'd go", ['i', 'would', 'go']],
            "we're" => ["we're here", ['we', 'are', 'here']],
            "I'm" => ["I'm here", ['i', 'am', 'here']],
            "they'll" => ["they'll go", ['they', 'will', 'go']],
            "we've" => ["we've gone", ['we', 'have', 'gone']],
            "don't" => ["don't go", ['do', 'not', 'go']],
            "can't" => ["can't go", ['cannot', 'go']],
            "won't" => ["won't go", ['will', 'not', 'go']],
            "it's" => ["it's here", ['it', 'is', 'here']],
            "Grav's" => ["Grav's theme", ["grav's", 'theme']],
            'curly I\'d' => ["I\u{2019}d go", ['i', 'would', 'go']],
        ];
    }

    /** @dataProvider contractions */
    public function testRealContractionsAreStillExpanded(string $text, array $expected): void
    {
        $this->assertSame($expected, $this->tokens($text));
    }

    public function testInvalidUtf8NeverThrows(): void
    {
        $analyzer = new StandardAnalyzer(['disable_stop_words' => true]);

        $this->assertSame(['dogs'], $analyzer->tokenize("dogs \xE9"));
        $this->assertSame(['dogs'], $analyzer->tokenize("dogs \xC3"));
        $this->assertSame(['caf', 'au', 'lait'], $analyzer->analyze("caf\xE9 au lait")['tokens']);
        $this->assertSame([], $analyzer->tokenize("\xFF\xFE"));
    }

    public function testDecomposedAccentsStayInTheirWord(): void
    {
        $decomposed = "re\u{0301}sume\u{0301}";

        $this->assertSame(["r\u{00E9}sum\u{00E9}"], $this->tokens('r' . "\u{00E9}" . 'sum' . "\u{00E9}"));
        $this->assertSame([$decomposed], $this->tokens($decomposed));
    }

    /** @return array<string, array{string, string[]}> */
    public function html(): array
    {
        return [
            'less-than before a digit' => ['I <3 PHP', ['i', '3', 'php']],
            'comparison' => ['3<5 and 7>2', ['3', '5', 'and', '7', '2']],
            'lone angle brackets' => ['a < b and c > d', ['a', 'b', 'and', 'c', 'd']],
            'unclosed' => ['foo<bar baz qux', ['foo', 'bar', 'baz', 'qux']],
            'script' => ['<script>var secret=1;</script>hello', ['hello']],
            'multiline script with attributes' => ["<script type=\"x\">\nvar a = 1;\n</script>hello", ['hello']],
            'style' => ['<style>.foo{color:red}</style>hello', ['hello']],
            'comment' => ['a<!-- hidden words -->b', ['ab']],
            'tags' => ['<p class="x">Hello <b>big</b> world</p>', ['hello', 'big', 'world']],
            'ampersand' => ['Fish &amp; Chips', ['fish', 'chips']],
            'named accent' => ['caf&eacute;', ['caf' . "\u{00E9}"]],
            'non-breaking space' => ['one&nbsp;two', ['one', 'two']],
            'quote' => ['it&#39;s &quot;fine&quot;', ['it', 'is', 'fine']],
        ];
    }

    /** @dataProvider html */
    public function testHtmlStrippingKeepsTextThatIsNotMarkup(string $text, array $expected): void
    {
        $analyzer = new StandardAnalyzer(['disable_stop_words' => true]);

        $this->assertSame($expected, $analyzer->tokenize($text));
    }

    public function testAKeywordThatIsNotInTheTextGetsNoEarlyBonus(): void
    {
        // The stem of "happy" is "happi", which is not in the text: stripos() is false there
        $analyzer = new StandardAnalyzer();
        $keywords = $analyzer->extractKeywords(str_repeat('filler ', 30) . 'happy', 5);
        $scores = array_column($keywords, 'score', 'word');

        $this->assertEqualsWithDelta(1.0, $scores['happi'], 0.0001);
    }

    public function testAKeywordNearTheStartGetsTheEarlyBonus(): void
    {
        $analyzer = new StandardAnalyzer();
        $scores = array_column($analyzer->extractKeywords('happiness ' . str_repeat('filler ', 30), 5), 'score', 'word');

        $this->assertEqualsWithDelta(1.5, $scores['happi'], 0.0001);
    }
}
