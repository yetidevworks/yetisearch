<?php

namespace YetiSearch\Stemmer\Languages;

use YetiSearch\Stemmer\BaseStemmer;

/**
 * Spanish Stemmer
 * 
 * A lightweight implementation of the Spanish stemming algorithm
 * Based on: https://snowballstem.org/algorithms/spanish/stemmer.html
 */
class SpanishStemmer extends BaseStemmer
{
    private array $vowels = ['a', 'e', 'i', 'o', 'u', 'á', 'é', 'í', 'ó', 'ú', 'ü'];
    
    public function stem(string $word): string
    {
        $word = $this->preprocess($word);
        
        if (strlen($word) <= 2) {
            return $word;
        }
        
        $this->word = $word;
        
        // Mark regions
        $rv = $this->getRVPosition();
        $r1 = $this->getR1Position();
        $r2 = $this->getR2Position($r1);
        
        // Step 0: Remove attached pronouns
        $this->step0($rv);
        
        // Step 1: Standard suffix removal
        $this->step1($r1, $r2, $rv);
        
        // Step 2: Verb suffixes
        $this->step2($rv);
        
        // Step 3: Residual suffix
        $this->step3($rv);
        
        // Remove accents
        $this->removeAccents();
        
        return $this->word;
    }
    
    private function step0($rv): void
    {
        // Remove attached pronouns
        $pronouns = [
            'selas', 'selos', 'sela', 'selo', 'las', 'les', 'los', 'nos',
            'me', 'se', 'la', 'le', 'lo'
        ];
        
        foreach ($pronouns as $pronoun) {
            if ($this->endsWith($pronoun)) {
                $pos = strlen($this->word) - strlen($pronoun);
                
                // Check for infinitive, gerund, or imperative
                $stem = substr($this->word, 0, $pos);
                if ($this->isValidVerbForm($stem, $rv)) {
                    $this->removeSuffix($pronoun);
                    
                    // Handle accent modifications
                    if ($this->endsWith('ár') || $this->endsWith('ér') || $this->endsWith('ír')) {
                        $this->word = substr($this->word, 0, -2) . substr($this->word, -1);
                    }
                    break;
                }
            }
        }
    }
    
    private function step1($r1, $r2, $rv): void
    {
        // Standard suffix removal
        $suffixes = [
            // Group 1
            'amientos' => ['region' => $r2, 'replacement' => ''],
            'imientos' => ['region' => $r2, 'replacement' => ''],
            'amiento' => ['region' => $r2, 'replacement' => ''],
            'imiento' => ['region' => $r2, 'replacement' => ''],
            'anzas' => ['region' => $r2, 'replacement' => ''],
            'ismos' => ['region' => $r2, 'replacement' => ''],
            'ables' => ['region' => $r2, 'replacement' => ''],
            'ibles' => ['region' => $r2, 'replacement' => ''],
            'istas' => ['region' => $r2, 'replacement' => ''],
            'anza' => ['region' => $r2, 'replacement' => ''],
            'ismo' => ['region' => $r2, 'replacement' => ''],
            'able' => ['region' => $r2, 'replacement' => ''],
            'ible' => ['region' => $r2, 'replacement' => ''],
            'ista' => ['region' => $r2, 'replacement' => ''],
            'osos' => ['region' => $r2, 'replacement' => ''],
            'osas' => ['region' => $r2, 'replacement' => ''],
            'oso' => ['region' => $r2, 'replacement' => ''],
            'osa' => ['region' => $r2, 'replacement' => ''],
            
            // Group 2 - preceded by ic
            'aciones' => ['region' => $r2, 'replacement' => ''],
            'ación' => ['region' => $r2, 'replacement' => ''],
            
            // Group 3
            'logías' => ['region' => $r2, 'replacement' => 'log'],
            'logía' => ['region' => $r2, 'replacement' => 'log'],
            
            // Group 4
            'uciones' => ['region' => $r2, 'replacement' => 'u'],
            'ución' => ['region' => $r2, 'replacement' => 'u'],
            
            // Group 5
            'encias' => ['region' => $r2, 'replacement' => 'ente'],
            'encia' => ['region' => $r2, 'replacement' => 'ente'],
            
            // Group 6
            'amente' => ['region' => $r1, 'replacement' => ''],
            
            // Group 7
            'mente' => ['region' => $r2, 'replacement' => ''],
            
            // Group 8
            'idades' => ['region' => $r2, 'replacement' => ''],
            'idad' => ['region' => $r2, 'replacement' => ''],
            
            // Group 9
            'ivas' => ['region' => $r2, 'replacement' => ''],
            'ivos' => ['region' => $r2, 'replacement' => ''],
            'iva' => ['region' => $r2, 'replacement' => ''],
            'ivo' => ['region' => $r2, 'replacement' => ''],
        ];
        
        foreach ($suffixes as $suffix => $data) {
            if ($this->endsWith($suffix)) {
                $pos = strlen($this->word) - strlen($suffix);
                if ($pos >= $data['region']) {
                    if ($data['replacement']) {
                        $this->replaceSuffix($suffix, $data['replacement']);
                    } else {
                        $this->removeSuffix($suffix);
                    }
                    break;
                }
            }
        }
    }
    
    private function step2($rv): void
    {
        // Verb suffixes
        $suffixes = [
            // Group 1
            'aríamos', 'eríamos', 'iríamos', 'iéramos', 'iésemos',
            'aríais', 'eríais', 'iríais', 'ierais', 'ieseis', 'asteis', 'isteis',
            'ábamos', 'aremos', 'eremos', 'iremos', 'áramos', 'éramos',
            'ásemos', 'arían', 'erían', 'irían', 'ieran', 'iesen', 'ieron',
            'iendo', 'ando', 'aban', 'aran', 'eron', 'arán', 'erán', 'irán',
            'arás', 'erás', 'irás', 'aría', 'ería', 'iría', 'iera', 'iese',
            'aste', 'iste', 'aba', 'ada', 'ida', 'ara', 'ase', 'ían',
            'ado', 'ido', 'ando', 'iendo', 'ar', 'er', 'ir', 'as',
            'ías', 'aba', 'ada', 'ía', 'ara', 'ase', 'en', 'es', 'éis', 'emos', 'an'
        ];
        
        // Sort by length (longest first) to match greedily
        usort($suffixes, function($a, $b) {
            return strlen($b) - strlen($a);
        });
        
        foreach ($suffixes as $suffix) {
            if ($this->endsWith($suffix)) {
                $pos = strlen($this->word) - strlen($suffix);
                if ($pos >= $rv) {
                    $this->removeSuffix($suffix);
                    break;
                }
            }
        }
    }
    
    private function step3($rv): void
    {
        // Residual suffixes
        $suffixes = ['os', 'a', 'o', 'á', 'í', 'ó', 'e', 'é'];
        
        foreach ($suffixes as $suffix) {
            if ($this->endsWith($suffix)) {
                $pos = strlen($this->word) - strlen($suffix);
                if ($pos >= $rv) {
                    $this->removeSuffix($suffix);
                    break;
                }
            }
        }
    }
    
    private function removeAccents(): void
    {
        $accents = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ñ' => 'n', 'ü' => 'u'
        ];
        
        $this->word = strtr($this->word, $accents);
    }
    
    private function getRVPosition(): int
    {
        $length = strlen($this->word);
        
        // If second letter is consonant, RV is after next vowel
        if ($length >= 2 && !$this->isVowelAt(1)) {
            for ($i = 2; $i < $length; $i++) {
                if ($this->isVowelAt($i)) {
                    return $i + 1;
                }
            }
            return $length;
        }
        
        // If first two letters are vowels, RV is after next consonant
        if ($length >= 2 && $this->isVowelAt(0) && $this->isVowelAt(1)) {
            for ($i = 2; $i < $length; $i++) {
                if (!$this->isVowelAt($i)) {
                    return $i + 1;
                }
            }
            return $length;
        }
        
        // Otherwise RV is after 3rd letter
        return min(3, $length);
    }
    
    private function getR1Position(): int
    {
        $length = strlen($this->word);
        
        // R1 is after first non-vowel followed by vowel
        for ($i = 0; $i < $length - 1; $i++) {
            if (!$this->isVowelAt($i) && $this->isVowelAt($i + 1)) {
                return $i + 2;
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
    
    private function isValidVerbForm($stem, $rv): bool
    {
        // Check if stem ends with valid verb endings
        $validEndings = ['ar', 'er', 'ir', 'ando', 'iendo', 'ado', 'ido'];
        foreach ($validEndings as $ending) {
            if (substr($stem, -strlen($ending)) === $ending) {
                return strlen($stem) >= $rv;
            }
        }
        return false;
    }
    
    public function getLanguage(): string
    {
        return 'spanish';
    }
}