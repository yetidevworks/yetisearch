<?php

namespace YetiSearch\Stemmer;

/**
 * Base stemmer class with common functionality
 */
abstract class BaseStemmer implements StemmerInterface
{
    protected string $word = '';
    
    /**
     * Check if a string ends with a suffix
     */
    protected function endsWith(string $suffix): bool
    {
        $length = strlen($suffix);
        if ($length === 0) {
            return true;
        }
        return substr($this->word, -$length) === $suffix;
    }
    
    /**
     * Replace suffix if it exists
     */
    protected function replaceSuffix(string $suffix, string $replacement): bool
    {
        if ($this->endsWith($suffix)) {
            $this->word = substr($this->word, 0, -strlen($suffix)) . $replacement;
            return true;
        }
        return false;
    }
    
    /**
     * Remove suffix if it exists
     */
    protected function removeSuffix(string $suffix): bool
    {
        return $this->replaceSuffix($suffix, '');
    }
    
    /**
     * Get the measure (consonant-vowel sequences) of the word
     * Used in Porter algorithm
     */
    protected function getMeasure(): int
    {
        $word = $this->word;
        $vowels = 'aeiou';
        $measure = 0;
        $previousWasVowel = false;
        
        for ($i = 0; $i < strlen($word); $i++) {
            $isVowel = strpos($vowels, $word[$i]) !== false;
            if (!$isVowel && $previousWasVowel) {
                $measure++;
            }
            $previousWasVowel = $isVowel;
        }
        
        return $measure;
    }
    
    /**
     * Check if word contains a vowel
     */
    protected function containsVowel(): bool
    {
        return preg_match('/[aeiou]/i', $this->word) === 1;
    }
    
    /**
     * Common preprocessing
     */
    protected function preprocess(string $word): string
    {
        // Convert to lowercase and trim
        return mb_strtolower(trim($word), 'UTF-8');
    }
}