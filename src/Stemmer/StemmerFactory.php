<?php

namespace YetiSearch\Stemmer;

use YetiSearch\Stemmer\Languages\EnglishStemmer;
use YetiSearch\Stemmer\Languages\FrenchStemmer;
use YetiSearch\Stemmer\Languages\GermanStemmer;
use YetiSearch\Stemmer\Languages\SpanishStemmer;

/**
 * Factory class for creating language-specific stemmers
 */
class StemmerFactory
{
    private static array $stemmers = [];
    
    /**
     * Supported languages and their aliases
     */
    private static array $languageMap = [
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
    
    /**
     * Create or get a stemmer for the specified language
     *
     * @param string $language Language code or name
     * @return StemmerInterface
     * @throws \InvalidArgumentException If language is not supported
     */
    public static function create(string $language): StemmerInterface
    {
        $language = strtolower(trim($language));
        
        // Map language alias to canonical name
        if (!isset(self::$languageMap[$language])) {
            throw new \InvalidArgumentException("Unsupported language: $language");
        }
        
        $canonicalLanguage = self::$languageMap[$language];
        
        // Return cached instance if available
        if (isset(self::$stemmers[$canonicalLanguage])) {
            return self::$stemmers[$canonicalLanguage];
        }
        
        // Create new instance
        switch ($canonicalLanguage) {
            case 'english':
                self::$stemmers[$canonicalLanguage] = new EnglishStemmer();
                break;
            case 'french':
                self::$stemmers[$canonicalLanguage] = new FrenchStemmer();
                break;
            case 'german':
                self::$stemmers[$canonicalLanguage] = new GermanStemmer();
                break;
            case 'spanish':
                self::$stemmers[$canonicalLanguage] = new SpanishStemmer();
                break;
        }
        
        return self::$stemmers[$canonicalLanguage];
    }
    
    /**
     * Get list of supported languages
     *
     * @return array
     */
    public static function getSupportedLanguages(): array
    {
        return array_unique(array_values(self::$languageMap));
    }
    
    /**
     * Check if a language is supported
     *
     * @param string $language
     * @return bool
     */
    public static function isSupported(string $language): bool
    {
        return isset(self::$languageMap[strtolower(trim($language))]);
    }
    
    /**
     * Clear the stemmer cache
     */
    public static function clearCache(): void
    {
        self::$stemmers = [];
    }
}