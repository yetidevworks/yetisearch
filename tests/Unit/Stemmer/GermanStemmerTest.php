<?php

namespace YetiSearch\Tests\Unit\Stemmer;

use PHPUnit\Framework\TestCase;
use YetiSearch\Stemmer\Languages\GermanStemmer;

class GermanStemmerTest extends TestCase
{
    private GermanStemmer $stemmer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stemmer = new GermanStemmer();
    }

    public function test_plural_and_umlaut_replacement(): void
    {
        // Plurals: Häuser -> haus (ä -> a; er removed)
        $this->assertSame('haus', $this->stemmer->stem('Häuser'));
        // en/es/e plural endings
        $this->assertSame('freund', $this->stemmer->stem('Freunden'));
        $this->assertSame('kind', $this->stemmer->stem('Kindes'));
        $this->assertSame('kind', $this->stemmer->stem('Kinder'));
    }

    public function test_verb_and_derivational_suffixes(): void
    {
        // Verb suffixes
        $this->assertSame('mach', $this->stemmer->stem('machest'));
        $this->assertSame('spiel', $this->stemmer->stem('spielst'));

        // Derivational endings
        $this->assertSame('notwendig', $this->stemmer->stem('notwendigkeit'));
        $this->assertSame('naturlich', $this->stemmer->stem('natürlich'));
        $this->assertSame('wissenschaft', $this->stemmer->stem('wissenschaftlich'));
    }
}
