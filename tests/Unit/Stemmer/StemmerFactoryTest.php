<?php

namespace YetiSearch\Tests\Unit\Stemmer;

use PHPUnit\Framework\TestCase;
use YetiSearch\Stemmer\StemmerFactory;
use YetiSearch\Stemmer\StemmerInterface;
use YetiSearch\Stemmer\Languages\EnglishStemmer;
use YetiSearch\Stemmer\Languages\FrenchStemmer;
use YetiSearch\Stemmer\Languages\GermanStemmer;
use YetiSearch\Stemmer\Languages\SpanishStemmer;

class StemmerFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        StemmerFactory::reset();
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

    public function test_register_adds_a_language_with_aliases(): void
    {
        StemmerFactory::register('ewokese', EwokStemmer::class, ['ewok', ' EW ']);

        $this->assertInstanceOf(EwokStemmer::class, StemmerFactory::create('ewok'));
        $this->assertInstanceOf(EwokStemmer::class, StemmerFactory::create('ewokese'));
        $this->assertInstanceOf(EwokStemmer::class, StemmerFactory::create('EW'));
        $this->assertSame(StemmerFactory::create('ewok'), StemmerFactory::create('ewokese'));
        $this->assertTrue(StemmerFactory::isSupported('ewok'));
        $this->assertContains('ewokese', StemmerFactory::getSupportedLanguages());
        $this->assertSame('ewokese', StemmerFactory::canonical('Ewok'));
        $this->assertSame('ewokese', StemmerFactory::canonical(' ewokese '));
    }

    public function test_register_accepts_an_instance_and_returns_it(): void
    {
        $stemmer = new EwokStemmer();
        StemmerFactory::register('ewokese', $stemmer);

        $this->assertSame($stemmer, StemmerFactory::create('ewokese'));
    }

    public function test_register_with_a_callable_calls_it_once_when_first_needed(): void
    {
        $calls = 0;
        StemmerFactory::register('ewokese', function () use (&$calls) {
            $calls++;
            return new EwokStemmer();
        }, ['ewok']);

        $this->assertSame(0, $calls, 'The callable waits until the language is needed');
        $first = StemmerFactory::create('ewok');
        $second = StemmerFactory::create('ewokese');

        $this->assertSame(1, $calls);
        $this->assertSame($first, $second);
    }

    public function test_register_replaces_a_built_in_and_keeps_its_aliases(): void
    {
        $this->assertInstanceOf(EnglishStemmer::class, StemmerFactory::create('en'));

        StemmerFactory::register('english', EwokStemmer::class);

        $this->assertInstanceOf(EwokStemmer::class, StemmerFactory::create('english'));
        $this->assertInstanceOf(EwokStemmer::class, StemmerFactory::create('en'));
        $this->assertInstanceOf(EwokStemmer::class, StemmerFactory::create('eng'));
        $this->assertInstanceOf(FrenchStemmer::class, StemmerFactory::create('fr'));
        $this->assertSame(
            ['english', 'french', 'german', 'spanish'],
            StemmerFactory::getSupportedLanguages()
        );
    }

    public function test_register_drops_the_cached_instance_of_that_language(): void
    {
        $before = StemmerFactory::create('french');
        $other = StemmerFactory::create('german');

        StemmerFactory::register('french', EwokStemmer::class);

        $this->assertNotSame($before, StemmerFactory::create('french'));
        $this->assertInstanceOf(EwokStemmer::class, StemmerFactory::create('fr'));
        $this->assertSame($other, StemmerFactory::create('german'));
    }

    public function test_an_alias_of_another_language_moves(): void
    {
        StemmerFactory::register('catalan', EwokStemmer::class, ['es', 'ca']);

        $this->assertInstanceOf(EwokStemmer::class, StemmerFactory::create('es'));
        $this->assertSame('catalan', StemmerFactory::canonical('es'));
        $this->assertSame('spanish', StemmerFactory::canonical('spanish'));
        $this->assertSame('spanish', StemmerFactory::canonical('spa'));
        $this->assertInstanceOf(SpanishStemmer::class, StemmerFactory::create('spanish'));
    }

    public function test_canonical_resolves_names_aliases_and_locales(): void
    {
        $this->assertSame('english', StemmerFactory::canonical('en'));
        $this->assertSame('english', StemmerFactory::canonical('  ENGLISH '));
        $this->assertSame('english', StemmerFactory::canonical('en_US'));
        $this->assertSame('english', StemmerFactory::canonical('en-GB'));
        $this->assertSame('french', StemmerFactory::canonical('fr-CA'));
        $this->assertSame('french', StemmerFactory::canonical('FR_ca'));
        $this->assertSame('german', StemmerFactory::canonical('de_DE.UTF-8'));
        $this->assertNull(StemmerFactory::canonical('pt_BR'));
        $this->assertNull(StemmerFactory::canonical('xx'));
        $this->assertNull(StemmerFactory::canonical(''));
    }

    public function test_a_registered_locale_wins_over_its_base_language(): void
    {
        StemmerFactory::register('pt', EwokStemmer::class);
        $this->assertSame('pt', StemmerFactory::canonical('pt_BR'));

        StemmerFactory::register('en_gb', EwokStemmer::class);
        $this->assertSame('en_gb', StemmerFactory::canonical('en_GB'));
        $this->assertSame('english', StemmerFactory::canonical('en_US'));
    }

    public function test_locale_forms_create_and_are_supported(): void
    {
        $this->assertTrue(StemmerFactory::isSupported('fr_FR'));
        $this->assertInstanceOf(FrenchStemmer::class, StemmerFactory::create('fr_FR'));
        $this->assertSame(StemmerFactory::create('french'), StemmerFactory::create('fr-CA'));
        $this->assertFalse(StemmerFactory::isSupported('it_IT'));
    }

    public function test_unknown_language_message_names_the_language(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported language: ewokese');
        StemmerFactory::create(' Ewokese ');
    }

    public function test_register_refuses_what_is_not_a_stemmer(): void
    {
        $bad = [
            'a class that does not exist' => 'No\\Such\\StemmerClass',
            'a class that is not a stemmer' => \stdClass::class,
            'an object that is not a stemmer' => new \stdClass(),
            'an abstract class' => AbstractEwokStemmer::class,
            'an integer' => 42,
            'null' => null,
        ];

        foreach ($bad as $label => $stemmer) {
            try {
                StemmerFactory::register('ewokese', $stemmer);
                $this->fail("Registering $label should be refused");
            } catch (\InvalidArgumentException $e) {
                $this->assertFalse(StemmerFactory::isSupported('ewokese'), "$label left a registration behind");
            }
        }
    }

    public function test_register_refuses_an_empty_language_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StemmerFactory::register('  ', EwokStemmer::class);
    }

    public function test_a_failed_register_changes_nothing(): void
    {
        try {
            StemmerFactory::register('french', \stdClass::class, ['fr', 'franc']);
            $this->fail('A class that is not a stemmer should be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertInstanceOf(FrenchStemmer::class, StemmerFactory::create('fr'));
            $this->assertNull(StemmerFactory::canonical('franc'));
        }
    }

    public function test_a_callable_that_returns_something_else_fails_when_it_is_first_used(): void
    {
        StemmerFactory::register('ewokese', function () {
            return new \stdClass();
        });

        $this->assertTrue(StemmerFactory::isSupported('ewokese'));
        $this->expectException(\InvalidArgumentException::class);
        StemmerFactory::create('ewokese');
    }

    public function test_reset_returns_to_the_built_ins(): void
    {
        StemmerFactory::register('ewokese', EwokStemmer::class, ['ewok', 'es']);
        StemmerFactory::register('english', EwokStemmer::class);

        StemmerFactory::reset();

        $this->assertFalse(StemmerFactory::isSupported('ewok'));
        $this->assertSame(['english', 'french', 'german', 'spanish'], StemmerFactory::getSupportedLanguages());
        $this->assertInstanceOf(EnglishStemmer::class, StemmerFactory::create('en'));
        $this->assertInstanceOf(SpanishStemmer::class, StemmerFactory::create('es'));
    }

    public function test_generation_moves_when_registrations_or_instances_change(): void
    {
        $generation = StemmerFactory::generation();
        $this->assertSame($generation, StemmerFactory::generation(), 'Reading does not move it');

        StemmerFactory::create('en');
        StemmerFactory::canonical('fr_CA');
        $this->assertSame($generation, StemmerFactory::generation(), 'Using the stemmers does not move it');

        StemmerFactory::register('ewokese', EwokStemmer::class);
        $this->assertNotSame($generation, StemmerFactory::generation());

        $generation = StemmerFactory::generation();
        StemmerFactory::clearCache();
        $this->assertNotSame($generation, StemmerFactory::generation());

        $generation = StemmerFactory::generation();
        StemmerFactory::reset();
        $this->assertNotSame($generation, StemmerFactory::generation());
    }

    public function test_a_failed_register_leaves_the_generation_alone(): void
    {
        $generation = StemmerFactory::generation();

        try {
            StemmerFactory::register('french', \stdClass::class);
            $this->fail('A class that is not a stemmer should be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame($generation, StemmerFactory::generation());
        }
    }
}

class EwokStemmer implements StemmerInterface
{
    public function stem(string $word): string
    {
        return rtrim($word, 'z');
    }

    public function getLanguage(): string
    {
        return 'ew';
    }
}

abstract class AbstractEwokStemmer implements StemmerInterface
{
}
