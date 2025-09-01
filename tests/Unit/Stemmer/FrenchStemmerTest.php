<?php

namespace YetiSearch\Tests\Unit\Stemmer;

use PHPUnit\Framework\TestCase;
use YetiSearch\Stemmer\Languages\FrenchStemmer;

class FrenchStemmerTest extends TestCase
{
    private FrenchStemmer $stemmer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stemmer = new FrenchStemmer();
    }

    public function test_standard_suffixes_and_verbs(): void
    {
        // R2 handling is conservative; short words may remain unchanged
        $this->assertSame('action', $this->stemmer->stem('action'));
        $this->assertSame('action', $this->stemmer->stem('actions'));
        // -ment in RV reduces to base (no special handling)
        $this->assertSame('douc', $this->stemmer->stem('doucement'));
    }

    public function test_verbs_ir_er_and_residuals(): void
    {
        // -ir verbs
        $this->assertSame('fin', $this->stemmer->stem('finir'));
        $this->assertSame('finiss', $this->stemmer->stem('finissaient'));

        // -er verbs
        $this->assertSame('chant', $this->stemmer->stem('chanter'));
        $this->assertSame('chant', $this->stemmer->stem('chantaient'));

        // Residual
        $this->assertSame('franc', $this->stemmer->stem('français'));
    }
}
