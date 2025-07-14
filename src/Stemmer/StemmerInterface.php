<?php

namespace YetiSearch\Stemmer;

/**
 * Interface for language-specific stemmers
 */
interface StemmerInterface
{
    /**
     * Stem a word to its root form
     *
     * @param string $word The word to stem
     * @return string The stemmed word
     */
    public function stem(string $word): string;
    
    /**
     * Get the language code this stemmer supports
     *
     * @return string Language code (e.g., 'en', 'fr', 'de', 'es')
     */
    public function getLanguage(): string;
}