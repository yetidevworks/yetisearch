<?php

namespace YetiSearch\Tests\Unit\Stemmer;

use PHPUnit\Framework\TestCase;
use YetiSearch\Stemmer\BaseStemmer;

class BaseStemmerTest extends TestCase
{
    private function makeStub(string $word): object
    {
        return new class($word) extends BaseStemmer {
            public function __construct(string $w) { $this->word = $w; }
            public function exposeEndsWith(string $s): bool { return $this->endsWith($s); }
            public function exposeReplaceSuffix(string $s, string $r): bool { return $this->replaceSuffix($s, $r); }
            public function exposeRemoveSuffix(string $s): bool { return $this->removeSuffix($s); }
            public function exposeMeasure(): int { return $this->getMeasure(); }
            public function exposeContainsVowel(): bool { return $this->containsVowel(); }
            public function exposePreprocess(string $w): string { return $this->preprocess($w); }
            public function stem(string $word): string { $this->word = $word; return $word; }
            public function getLanguage(): string { return 'stub'; }
        };
    }

    public function test_suffix_helpers_and_measure(): void
    {
        $s = $this->makeStub('testing');
        $this->assertTrue($s->exposeEndsWith('ing'));
        $this->assertTrue($s->exposeReplaceSuffix('ing', '')); // test -> test
        $this->assertTrue($s->exposeContainsVowel());

        $s2 = $this->makeStub('sky'); // no vowels
        $this->assertFalse($s2->exposeContainsVowel());
        $this->assertSame(1, $this->makeStub('trouble')->exposeMeasure());
    }

    public function test_preprocess_lowercase_and_trim(): void
    {
        $s = $this->makeStub('');
        $this->assertSame('hello', $s->exposePreprocess('  HeLLo  '));
    }
}
