<?php

namespace YetiSearch\Tests\Unit\Stemmer;

use PHPUnit\Framework\TestCase;
use YetiSearch\Stemmer\StemmerFactory;
use YetiSearch\Stemmer\Languages\EnglishStemmer;
use YetiSearch\Stemmer\Languages\FrenchStemmer;
use YetiSearch\Stemmer\Languages\GermanStemmer;
use YetiSearch\Stemmer\Languages\SpanishStemmer;

class StemmerFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        StemmerFactory::clearCache();
        parent::tearDown();
    }

    public function test_create_with_aliases_and_caching(): void
    {
        $this->assertInstanceOf(EnglishStemmer::class, StemmerFactory::create('en'));
        $this->assertInstanceOf(FrenchStemmer::class, StemmerFactory::create('fr'));
        $this->assertInstanceOf(GermanStemmer::class, StemmerFactory::create('de'));
        $this->assertInstanceOf(SpanishStemmer::class, StemmerFactory::create('es'));

        // Caching returns same instance
        $en1 = StemmerFactory::create('en');
        $en2 = StemmerFactory::create('english');
        $this->assertSame($en1, $en2);
    }

    public function test_get_supported_and_is_supported(): void
    {
        $langs = StemmerFactory::getSupportedLanguages();
        $this->assertContains('english', $langs);
        $this->assertContains('french', $langs);
        $this->assertContains('german', $langs);
        $this->assertContains('spanish', $langs);

        $this->assertTrue(StemmerFactory::isSupported('en'));
        $this->assertFalse(StemmerFactory::isSupported('xx'));
    }

    public function test_unsupported_language_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StemmerFactory::create('klingon');
    }
}

