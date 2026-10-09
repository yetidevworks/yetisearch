<?php

namespace YetiSearch\Stemmer;

use YetiSearch\Stemmer\Languages\EnglishStemmer;
use YetiSearch\Stemmer\Languages\FrenchStemmer;
use YetiSearch\Stemmer\Languages\GermanStemmer;
use YetiSearch\Stemmer\Languages\SpanishStemmer;

/**
 * Factory class for creating language-specific stemmers
 *
 * The four built-in stemmers can be replaced, and stemmers for other
 * languages added, with register().
 */
class StemmerFactory
{
    private const BUILT_IN_ALIASES = [
        'english' => 'english',
        'en' => 'english',
        'eng' => 'english',

        'french' => 'french',
        'fr' => 'french',
        'fra' => 'french',
        'francais' => 'french',

        'german' => 'german',
        'de' => 'german',
        'deu' => 'german',
        'deutsch' => 'german',

        'spanish' => 'spanish',
        'es' => 'spanish',
        'spa' => 'spanish',
        'espanol' => 'spanish',
    ];

    private const BUILT_IN_STEMMERS = [
        'english' => EnglishStemmer::class,
        'french' => FrenchStemmer::class,
        'german' => GermanStemmer::class,
        'spanish' => SpanishStemmer::class,
    ];

    /**
     * What each language name or alias stands for, once registrations are applied.
     *
     * @var array<string, string> alias => canonical name
     */
    private static array $languageMap = self::BUILT_IN_ALIASES;

    /**
     * How each language's stemmer is made: a class name, an instance, or a callable that returns one.
     *
     * @var array<string, string|StemmerInterface|callable>
     */
    private static array $implementations = self::BUILT_IN_STEMMERS;

    /** @var array<string, StemmerInterface> Stemmers made so far, by canonical name */
    private static array $stemmers = [];

    /**
     * What canonical() found for the names it was asked about, null included.
     * Bounded, as a name can come from a search request.
     *
     * @var array<string, ?string>
     */
    private static array $canonicalCache = [];

    private const CANONICAL_CACHE_LIMIT = 256;

    /** @var int Moves on whenever a registration or a stemmer instance changes */
    private static int $generation = 0;

    /**
     * Register a stemmer for a language, or replace the built-in one.
     *
     * $stemmer is the name of a class implementing StemmerInterface, an
     * instance of one, or a callable that returns one. A callable is called
     * once, the first time the language is needed.
     *
     * Registering the name of a built-in language (english, french, german,
     * spanish) replaces its stemmer; its aliases keep pointing at it. An alias
     * that already belongs to another language moves to this one.
     *
     * @param string $language Canonical name of the language, e.g. 'italian'. It is also an alias of itself.
     * @param string|StemmerInterface|callable $stemmer
     * @param string[] $aliases Other names for the language, e.g. ['it', 'ita', 'italiano']
     * @throws \InvalidArgumentException If the name is empty or the stemmer is not usable
     */
    public static function register(string $language, $stemmer, array $aliases = []): void
    {
        $canonical = self::normalize($language);
        if ($canonical === '') {
            throw new \InvalidArgumentException('A stemmer needs a language name to be registered under');
        }

        if ($stemmer instanceof StemmerInterface) {
            $definition = $stemmer;
        } elseif (is_string($stemmer) && class_exists($stemmer)) {
            if (!is_a($stemmer, StemmerInterface::class, true)) {
                throw new \InvalidArgumentException("Stemmer class $stemmer must implement " . StemmerInterface::class);
            }
            if (!(new \ReflectionClass($stemmer))->isInstantiable()) {
                throw new \InvalidArgumentException("Stemmer class $stemmer cannot be instantiated");
            }
            $definition = $stemmer;
        } elseif (is_callable($stemmer)) {
            $definition = $stemmer;
        } else {
            throw new \InvalidArgumentException(
                'A stemmer must be a class name or an instance of ' . StemmerInterface::class . ', or a callable that returns one'
            );
        }

        $names = [$canonical];
        foreach ($aliases as $alias) {
            if (!is_string($alias)) {
                throw new \InvalidArgumentException('Stemmer aliases must be strings');
            }
            $alias = self::normalize($alias);
            if ($alias !== '') {
                $names[] = $alias;
            }
        }

        foreach ($names as $name) {
            self::$languageMap[$name] = $canonical;
        }
        self::$implementations[$canonical] = $definition;
        unset(self::$stemmers[$canonical]);
        self::changed();
    }

    /**
     * A number that changes whenever a stemmer is registered, the cache of
     * instances is cleared, or the factory is reset. Whatever is derived from a
     * stemmer, the stems an analyzer remembers for instance, is valid for as
     * long as the number stays what it was when it was derived.
     */
    public static function generation(): int
    {
        return self::$generation;
    }

    /**
     * Resolve a language name, alias or locale to its canonical name.
     *
     * 'fr', 'FR ', 'francais' and 'fr_CA' all give 'french'. A locale such as
     * 'en_US' or 'pt-BR' that is not registered as it stands is resolved by
     * the part before the underscore or hyphen.
     *
     * @return string|null The canonical name, or null when no stemmer is registered for the language
     */
    public static function canonical(string $language): ?string
    {
        if (array_key_exists($language, self::$canonicalCache)) {
            return self::$canonicalCache[$language];
        }

        $name = self::normalize($language);
        $canonical = self::$languageMap[$name] ?? null;
        if ($canonical === null) {
            $base = preg_split('/[_\-.@]/', $name, 2)[0];
            if ($base !== $name && isset(self::$languageMap[$base])) {
                $canonical = self::$languageMap[$base];
            }
        }

        if (count(self::$canonicalCache) >= self::CANONICAL_CACHE_LIMIT) {
            self::$canonicalCache = [];
        }

        return self::$canonicalCache[$language] = $canonical;
    }

    /**
     * Create or get a stemmer for the specified language
     *
     * @param string $language Language code, name or locale
     * @return StemmerInterface
     * @throws \InvalidArgumentException If language is not supported
     */
    public static function create(string $language): StemmerInterface
    {
        $canonicalLanguage = self::canonical($language);
        if ($canonicalLanguage === null) {
            throw new \InvalidArgumentException('Unsupported language: ' . self::normalize($language));
        }

        // Return cached instance if available
        if (isset(self::$stemmers[$canonicalLanguage])) {
            return self::$stemmers[$canonicalLanguage];
        }

        $definition = self::$implementations[$canonicalLanguage];
        if ($definition instanceof StemmerInterface) {
            $stemmer = $definition;
        } elseif (is_string($definition) && class_exists($definition)) {
            $stemmer = new $definition();
        } else {
            $stemmer = $definition();
            if (!$stemmer instanceof StemmerInterface) {
                throw new \InvalidArgumentException(
                    "The stemmer callable registered for '$canonicalLanguage' must return an instance of " . StemmerInterface::class
                );
            }
        }

        return self::$stemmers[$canonicalLanguage] = $stemmer;
    }

    /**
     * Get list of supported languages
     *
     * @return array Canonical names, built-in and registered
     */
    public static function getSupportedLanguages(): array
    {
        return array_keys(self::$implementations);
    }

    /**
     * Check if a language is supported
     *
     * @param string $language Language code, name or locale
     * @return bool
     */
    public static function isSupported(string $language): bool
    {
        return self::canonical($language) !== null;
    }

    /**
     * Clear the stemmer cache
     */
    public static function clearCache(): void
    {
        self::$stemmers = [];
        self::changed();
    }

    /**
     * Remove every registration and return to the four built-in stemmers and
     * their aliases. Meant for tests, so that what one registers does not
     * reach the next.
     */
    public static function reset(): void
    {
        self::$languageMap = self::BUILT_IN_ALIASES;
        self::$implementations = self::BUILT_IN_STEMMERS;
        self::$stemmers = [];
        self::changed();
    }

    private static function changed(): void
    {
        self::$canonicalCache = [];
        self::$generation++;
    }

    private static function normalize(string $language): string
    {
        return strtolower(trim($language));
    }
}
