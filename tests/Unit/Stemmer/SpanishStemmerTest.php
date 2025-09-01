<?php

namespace YetiSearch\Tests\Unit\Stemmer;

use PHPUnit\Framework\TestCase;
use YetiSearch\Stemmer\Languages\SpanishStemmer;

class SpanishStemmerTest extends TestCase
{
    private SpanishStemmer $stemmer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stemmer = new SpanishStemmer();
    }

    public function test_basic_accent_and_suffix_removal(): void
    {
        // Accents removed
        $this->assertSame('nacion', $this->stemmer->stem('nación'));
        // -logía -> -log (and accents removed)
        $this->assertSame('biolog', $this->stemmer->stem('biología'));
    }

    public function test_verb_suffixes_and_residuals(): void
    {
        // Gerund -ando removed -> habl
        $this->assertSame('habl', $this->stemmer->stem('hablando'));
        // Gerund -iendo removed -> com
        $this->assertSame('com', $this->stemmer->stem('comiendo'));
        // Residual -os + accent handling -> niños => nin
        $this->assertSame('nin', $this->stemmer->stem('niños'));
    }

    public function test_standard_suffix_groups(): void
    {
        // -mente -> residual removal may drop trailing vowel; accents removed
        $this->assertSame('rapid', $this->stemmer->stem('rápidamente'));
        $this->assertSame('trist', $this->stemmer->stem('tristemente'));
    }
}
