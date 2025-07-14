<?php

namespace YetiSearch\Stemmer\Languages;

use YetiSearch\Stemmer\BaseStemmer;

/**
 * German Stemmer
 * 
 * A lightweight implementation of the German stemming algorithm
 * Based on: https://snowballstem.org/algorithms/german/stemmer.html
 */
class GermanStemmer extends BaseStemmer
{
    private array $vowels = ['a', 'e', 'i', 'o', 'u', 'y', 'ä', 'ö', 'ü'];
    private array $sEndings = ['b', 'd', 'f', 'g', 'h', 'k', 'l', 'm', 'n', 'r', 't'];
    
    public function stem(string $word): string
    {
        $word = $this->preprocess($word);
        
        if (strlen($word) <= 2) {
            return $word;
        }
        
        $this->word = $word;
        
        // Replace ß with ss
        $this->word = str_replace('ß', 'ss', $this->word);
        
        // Mark regions
        $r1 = $this->getR1Position();
        $r2 = $this->getR2Position($r1);
        
        // Step 1: Remove standard suffixes
        $this->step1($r1, $r2);
        
        // Step 2: Remove verb suffixes
        $this->step2($r1);
        
        // Step 3: Derivational suffixes
        $this->step3($r1, $r2);
        
        // Final: Replace umlauts
        $this->replaceUmlauts();
        
        return $this->word;
    }
    
    private function step1($r1, $r2): void
    {
        // Remove plurals and other common suffixes
        $suffixes = [
            // em, ern, er
            'ern' => ['region' => $r1, 'replacement' => ''],
            'em' => ['region' => $r1, 'replacement' => ''],
            'er' => ['region' => $r1, 'replacement' => ''],
            
            // e, en, es
            'en' => ['region' => $r1, 'replacement' => ''],
            'es' => ['region' => $r1, 'replacement' => ''],
            'e' => ['region' => $r1, 'replacement' => ''],
            
            // s (preceded by valid ending)
            's' => ['region' => $r1, 'replacement' => '', 'special' => true],
        ];
        
        foreach ($suffixes as $suffix => $data) {
            if ($this->endsWith($suffix)) {
                $pos = strlen($this->word) - strlen($suffix);
                
                // Special handling for 's'
                if ($suffix === 's' && $data['special']) {
                    if ($pos > 0 && in_array($this->word[$pos - 1], $this->sEndings)) {
                        continue; // Don't remove s after certain letters
                    }
                }
                
                if ($pos >= $data['region']) {
                    $this->removeSuffix($suffix);
                    break;
                }
            }
        }
    }
    
    private function step2($r1): void
    {
        // Remove verb suffixes
        $suffixes = ['est', 'en', 'st', 'er', 'et'];
        
        foreach ($suffixes as $suffix) {
            if ($this->endsWith($suffix)) {
                $pos = strlen($this->word) - strlen($suffix);
                if ($pos >= $r1) {
                    // Don't remove if preceded by certain patterns
                    if ($suffix === 'st' && $pos >= 3) {
                        $preceding = substr($this->word, $pos - 3, 3);
                        if (strlen($preceding) >= 3 && $preceding[2] === $preceding[1]) {
                            continue;
                        }
                    }
                    $this->removeSuffix($suffix);
                    break;
                }
            }
        }
    }
    
    private function step3($r1, $r2): void
    {
        // Remove derivational suffixes
        $suffixes = [
            // Group 1: end, ung
            'end' => ['region' => $r2, 'replacement' => ''],
            'ung' => ['region' => $r2, 'replacement' => ''],
            
            // Group 2: ig, ik, isch
            'isch' => ['region' => $r2, 'replacement' => '', 'notAfter' => ['e']],
            'ig' => ['region' => $r2, 'replacement' => '', 'notAfter' => ['e']],
            'ik' => ['region' => $r2, 'replacement' => '', 'notAfter' => ['e']],
            
            // Group 3: lich, heit
            'lich' => ['region' => $r2, 'replacement' => ''],
            'heit' => ['region' => $r2, 'replacement' => ''],
            
            // Group 4: keit
            'keit' => ['region' => $r2, 'replacement' => ''],
        ];
        
        foreach ($suffixes as $suffix => $data) {
            if ($this->endsWith($suffix)) {
                $pos = strlen($this->word) - strlen($suffix);
                
                if ($pos >= $data['region']) {
                    // Check for notAfter conditions
                    if (isset($data['notAfter']) && $pos > 0) {
                        $before = $this->word[$pos - 1];
                        if (in_array($before, $data['notAfter'])) {
                            continue;
                        }
                    }
                    
                    $this->removeSuffix($suffix);
                    
                    // Additional removal for lich, ig
                    if (($suffix === 'lich' || $suffix === 'ig') && $this->endsWith('e')) {
                        $newPos = strlen($this->word) - 1;
                        if ($newPos >= $r1) {
                            $this->removeSuffix('e');
                        }
                    }
                    break;
                }
            }
        }
    }
    
    private function replaceUmlauts(): void
    {
        // Replace umlauts with base vowels
        $umlauts = [
            'ä' => 'a',
            'ö' => 'o',
            'ü' => 'u',
        ];
        
        $this->word = strtr($this->word, $umlauts);
    }
    
    private function getR1Position(): int
    {
        $length = strlen($this->word);
        
        // R1 is after first non-vowel followed by vowel
        for ($i = 0; $i < $length - 1; $i++) {
            if (!$this->isVowelAt($i) && $this->isVowelAt($i + 1)) {
                return max(3, $i + 2); // R1 is at least position 3
            }
        }
        
        return $length;
    }
    
    private function getR2Position($r1): int
    {
        $length = strlen($this->word);
        
        // R2 is after R1
        for ($i = $r1; $i < $length - 1; $i++) {
            if (!$this->isVowelAt($i) && $this->isVowelAt($i + 1)) {
                return $i + 2;
            }
        }
        
        return $length;
    }
    
    private function isVowelAt($position): bool
    {
        if ($position < 0 || $position >= strlen($this->word)) {
            return false;
        }
        return in_array($this->word[$position], $this->vowels);
    }
    
    public function getLanguage(): string
    {
        return 'german';
    }
}