<?php

namespace YetiSearch\Stemmer\Languages;

use YetiSearch\Stemmer\BaseStemmer;

/**
 * English Porter2 Stemmer
 * 
 * A lightweight implementation of the Porter2 (English) stemming algorithm
 * Based on: https://snowballstem.org/algorithms/porter/stemmer.html
 */
class EnglishStemmer extends BaseStemmer
{
    private array $exceptions = [
        // Special cases that should not be stemmed
        'skis' => 'ski',
        'skies' => 'sky',
        'dying' => 'die',
        'lying' => 'lie',
        'tying' => 'tie',
        'idly' => 'idl',
        'gently' => 'gentl',
        'ugly' => 'ugli',
        'early' => 'earli',
        'only' => 'onli',
        'singly' => 'singl',
        'sky' => 'sky',
        'news' => 'news',
        'howe' => 'howe',
        'atlas' => 'atlas',
        'cosmos' => 'cosmos',
        'bias' => 'bias',
        'andes' => 'andes',
    ];
    
    public function stem(string $word): string
    {
        $word = $this->preprocess($word);
        
        // Handle short words
        if (strlen($word) <= 2) {
            return $word;
        }
        
        // Check exceptions
        if (isset($this->exceptions[$word])) {
            return $this->exceptions[$word];
        }
        
        $this->word = $word;
        
        // Remove initial apostrophe
        if (strpos($this->word, "'") === 0) {
            $this->word = substr($this->word, 1);
        }
        
        // Step 1a: Remove plural suffixes
        $this->step1a();
        
        // Step 1b: Remove verbal suffixes
        $this->step1b();
        
        // Step 1c: Replace y with i
        $this->step1c();
        
        // Step 2: Remove other suffixes
        $this->step2();
        
        // Step 3: Remove derivational suffixes
        $this->step3();
        
        // Step 4: Remove residual suffixes
        $this->step4();
        
        // Step 5: Remove final e or l
        $this->step5();
        
        return $this->word;
    }
    
    private function step1a(): void
    {
        // Handle sses, ies, ss, s
        if ($this->replaceSuffix('sses', 'ss')) return;
        if ($this->replaceSuffix('ies', 'i')) return;
        if ($this->endsWith('ss')) return;
        if ($this->endsWith('us')) return; // Don't remove s from words like 'us'
        if ($this->endsWith('is')) return; // Don't remove s from words like 'this', 'is'
        if ($this->removeSuffix('s')) return;
    }
    
    private function step1b(): void
    {
        $modified = false;
        
        // Handle eed, eedly
        if ($this->endsWith('eedly') || $this->endsWith('eed')) {
            $base = substr($this->word, 0, -strlen($this->endsWith('eedly') ? 'eedly' : 'eed'));
            if ($this->measureGreaterThan($base, 0)) {
                $this->replaceSuffix('eedly', 'ee');
                $this->replaceSuffix('eed', 'ee');
            }
            return;
        }
        
        // Handle ed, edly, ing, ingly
        $suffixes = ['edly', 'ed', 'ingly', 'ing'];
        foreach ($suffixes as $suffix) {
            if ($this->endsWith($suffix)) {
                $base = substr($this->word, 0, -strlen($suffix));
                if ($this->containsVowelInStem($base)) {
                    $this->removeSuffix($suffix);
                    $modified = true;
                    break;
                }
            }
        }
        
        if ($modified) {
            // Handle at, bl, iz
            if ($this->replaceSuffix('at', 'ate')) return;
            if ($this->replaceSuffix('bl', 'ble')) return;
            if ($this->replaceSuffix('iz', 'ize')) return;
            
            // Handle double consonants
            if ($this->hasDoubleConsonant() && !$this->endsWith('ll') && !$this->endsWith('ss') && !$this->endsWith('zz')) {
                $this->word = substr($this->word, 0, -1);
            }
            // Handle short words
            elseif ($this->isShortWord()) {
                $this->word .= 'e';
            }
        }
    }
    
    private function step1c(): void
    {
        // Replace y with i if preceded by consonant
        if (strlen($this->word) > 2 && 
            ($this->endsWith('y') || $this->endsWith('Y')) && 
            !$this->isVowel(strlen($this->word) - 2)) {
            $this->word = substr($this->word, 0, -1) . 'i';
        }
    }
    
    private function step2(): void
    {
        $suffixes = [
            'ational' => 'ate',
            'tional' => 'tion',
            'enci' => 'ence',
            'anci' => 'ance',
            'izer' => 'ize',
            'abli' => 'able',
            'alli' => 'al',
            'entli' => 'ent',
            'eli' => 'e',
            'ousli' => 'ous',
            'ization' => 'ize',
            'ation' => 'ate',
            'ator' => 'ate',
            'alism' => 'al',
            'iveness' => 'ive',
            'fulness' => 'ful',
            'ousness' => 'ous',
            'aliti' => 'al',
            'iviti' => 'ive',
            'biliti' => 'ble',
        ];
        
        foreach ($suffixes as $suffix => $replacement) {
            if ($this->endsWith($suffix)) {
                $base = substr($this->word, 0, -strlen($suffix));
                if ($this->measureGreaterThan($base, 0)) {
                    $this->replaceSuffix($suffix, $replacement);
                    break;
                }
            }
        }
    }
    
    private function step3(): void
    {
        $suffixes = [
            'icate' => 'ic',
            'ative' => '',
            'alize' => 'al',
            'iciti' => 'ic',
            'ical' => 'ic',
            'ful' => '',
            'ness' => '',
        ];
        
        foreach ($suffixes as $suffix => $replacement) {
            if ($this->endsWith($suffix)) {
                $base = substr($this->word, 0, -strlen($suffix));
                if ($this->measureGreaterThan($base, 0)) {
                    $this->replaceSuffix($suffix, $replacement);
                    break;
                }
            }
        }
    }
    
    private function step4(): void
    {
        $suffixes = ['al', 'ance', 'ence', 'er', 'ic', 'able', 'ible', 'ant', 
                     'ement', 'ment', 'ent', 'ism', 'ate', 'iti', 'ous', 
                     'ive', 'ize'];
        
        foreach ($suffixes as $suffix) {
            if ($this->endsWith($suffix)) {
                $base = substr($this->word, 0, -strlen($suffix));
                if ($this->measureGreaterThan($base, 1)) {
                    $this->removeSuffix($suffix);
                    break;
                }
            }
        }
        
        // Special case for 'ion'
        if ($this->endsWith('ion')) {
            $base = substr($this->word, 0, -3);
            if ($this->measureGreaterThan($base, 1) && 
                (substr($base, -1) === 's' || substr($base, -1) === 't')) {
                $this->removeSuffix('ion');
            }
        }
    }
    
    private function step5(): void
    {
        // Remove final 'e'
        if ($this->endsWith('e')) {
            $base = substr($this->word, 0, -1);
            if ($this->measureGreaterThan($base, 1) || 
                ($this->measureGreaterThan($base, 0) && !$this->endsWithCVC($base))) {
                $this->removeSuffix('e');
            }
        }
        
        // Remove double 'l'
        if ($this->endsWith('ll') && $this->measureGreaterThan(substr($this->word, 0, -1), 1)) {
            $this->word = substr($this->word, 0, -1);
        }
    }
    
    private function measureGreaterThan(string $stem, int $min): bool
    {
        $measure = 0;
        $vowels = 'aeiouy';
        $previousWasVowel = false;
        
        for ($i = 0; $i < strlen($stem); $i++) {
            $isVowel = strpos($vowels, $stem[$i]) !== false;
            if (!$isVowel && $previousWasVowel) {
                $measure++;
            }
            $previousWasVowel = $isVowel;
        }
        
        return $measure > $min;
    }
    
    private function containsVowelInStem(string $stem): bool
    {
        return preg_match('/[aeiouy]/', $stem) === 1;
    }
    
    private function isVowel(int $position): bool
    {
        if ($position < 0 || $position >= strlen($this->word)) {
            return false;
        }
        return strpos('aeiouy', $this->word[$position]) !== false;
    }
    
    private function hasDoubleConsonant(): bool
    {
        $length = strlen($this->word);
        if ($length < 2) return false;
        
        $last = $this->word[$length - 1];
        $secondLast = $this->word[$length - 2];
        
        return $last === $secondLast && strpos('aeiouy', $last) === false;
    }
    
    private function isShortWord(): bool
    {
        return strlen($this->word) <= 3 && $this->endsWithCVC($this->word);
    }
    
    private function endsWithCVC(string $word): bool
    {
        $length = strlen($word);
        if ($length < 3) return false;
        
        $c1 = strpos('aeiouy', $word[$length - 3]) === false;
        $v = strpos('aeiouy', $word[$length - 2]) !== false;
        $c2 = strpos('aeiouy', $word[$length - 1]) === false;
        $notWXY = strpos('wxy', $word[$length - 1]) === false;
        
        return $c1 && $v && $c2 && $notWXY;
    }
    
    public function getLanguage(): string
    {
        return 'english';
    }
}