<?php

namespace YetiSearch\Tests\Unit\Analyzers;

use YetiSearch\Tests\TestCase;
use YetiSearch\Analyzers\StandardAnalyzer;

class StandardAnalyzerTest extends TestCase
{
    private StandardAnalyzer $analyzer;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new StandardAnalyzer();
    }
    
    public function testAnalyzeBasicText(): void
    {
        $text = "The quick brown fox jumps over the lazy dog";
        $result = $this->analyzer->analyze($text);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('tokens', $result);
        $this->assertArrayHasKey('original', $result);
        $this->assertEquals($text, $result['original']);
        
        // Should remove common stop words like 'the', 'over'
        $this->assertNotContains('the', $result['tokens']);
        $this->assertContains('quick', $result['tokens']);
        $this->assertContains('brown', $result['tokens']);
        $this->assertContains('fox', $result['tokens']);
    }
    
    public function testAnalyzeWithMinWordLength(): void
    {
        $analyzer = new StandardAnalyzer(['min_word_length' => 4]);
        $result = $analyzer->analyze("The cat and dog are big");
        
        // Words shorter than 4 characters should be filtered
        $this->assertNotContains('cat', $result['tokens']);
        $this->assertNotContains('dog', $result['tokens']);
        $this->assertNotContains('are', $result['tokens']);
        $this->assertNotContains('big', $result['tokens']); // 3 chars
    }
    
    public function testAnalyzeWithHtml(): void
    {
        $htmlText = '<p>This is <strong>bold</strong> text with <a href="#">link</a></p>';
        
        // With HTML stripping enabled (default)
        $result = $this->analyzer->analyze($htmlText);
        $this->assertNotContains('<p>', $result['tokens']);
        $this->assertNotContains('<strong>', $result['tokens']);
        $this->assertContains('bold', $result['tokens']);
        $this->assertContains('text', $result['tokens']);
        $this->assertContains('link', $result['tokens']);
        
        // With HTML stripping disabled
        $analyzer = new StandardAnalyzer(['strip_html' => false]);
        $result = $analyzer->analyze($htmlText);
        $this->assertContains('href', $result['tokens']);
    }
    
    public function testAnalyzeWithStopWords(): void
    {
        // With stop words removal enabled (default)
        $result = $this->analyzer->analyze("This is a test of the analyzer");
        $this->assertNotContains('this', $result['tokens']);
        $this->assertNotContains('is', $result['tokens']);
        $this->assertNotContains('a', $result['tokens']);
        $this->assertNotContains('the', $result['tokens']);
        $this->assertNotContains('of', $result['tokens']);
        $this->assertContains('test', $result['tokens']);
        $this->assertContains('analyz', $result['tokens']); // 'analyzer' gets stemmed to 'analyz'
        
        // Test with stop words removal disabled
        $analyzer = new StandardAnalyzer(['disable_stop_words' => true]);
        $result = $analyzer->analyze("This is a test of the analyzer");
        $this->assertContains('this', $result['tokens']);
        $this->assertContains('is', $result['tokens']);
        // 'a' is filtered out due to min_word_length (default 2)
        $this->assertContains('the', $result['tokens']);
        $this->assertContains('of', $result['tokens']);
        $this->assertContains('test', $result['tokens']);
        $this->assertContains('analyz', $result['tokens']);
    }
    
    public function testAnalyzeWithStemming(): void
    {
        // Test English stemming
        $result = $this->analyzer->analyze("running runs", 'en');
        
        // Both should stem to 'run'
        $tokens = array_unique($result['tokens']);
        $this->assertCount(1, $tokens);
        $this->assertContains('run', $tokens);
        
        // Test stemming with different words
        $result = $this->analyzer->analyze("computers computing computed", 'en');
        $tokens = $result['tokens'];
        
        // Should all stem to 'comput'
        foreach ($tokens as $token) {
            $this->assertStringStartsWith('comput', $token);
        }
    }
    
    public function testAnalyzeMultiLanguage(): void
    {
        // Test French
        $analyzer = new StandardAnalyzer();
        $result = $analyzer->analyze("Les ordinateurs sont utiles", 'french');
        $this->assertNotContains('les', $result['tokens']); // French stop word
        $this->assertContains('ordin', $result['tokens']); // 'ordinateurs' gets stemmed
        
        // Test German
        $result = $analyzer->analyze("Die Computer sind nützlich", 'german');
        $this->assertNotContains('die', $result['tokens']); // German stop word
        $this->assertContains('comput', $result['tokens']); // 'computer' gets stemmed
    }
    
    public function testAnalyzeWithContractions(): void
    {
        $text = "I'm won't can't shouldn't they're";
        $result = $this->analyzer->analyze($text);
        
        // Contractions should be expanded - most become stop words
        $this->assertNotContains("i'm", $result['tokens']);
        $this->assertNotContains("won't", $result['tokens']);
        $this->assertContains('cannot', $result['tokens']); // can't expands to 'cannot'
    }
    
    public function testAnalyzeWithNumbers(): void
    {
        $text = "The price is $99.99 or 100 euros";
        $result = $this->analyzer->analyze($text);
        
        $this->assertContains('price', $result['tokens']);
        // Numbers with decimals get split at the period
        $this->assertContains('99', $result['tokens']);
        $this->assertContains('100', $result['tokens']);
        $this->assertContains('euro', $result['tokens']); // 'euros' gets stemmed
    }
    
    public function testAnalyzeWithSpecialCharacters(): void
    {
        $text = "email@example.com and C++ programming!";
        $result = $this->analyzer->analyze($text);
        
        // Special characters are stripped
        $this->assertContains('email', $result['tokens']);
        $this->assertContains('exampl', $result['tokens']); // stemmed
        $this->assertContains('com', $result['tokens']);
        // 'c' is filtered out due to min_word_length (default 2)
        $this->assertContains('program', $result['tokens']); // stemmed
    }
    
    public function testAnalyzeEmptyText(): void
    {
        $result = $this->analyzer->analyze("");
        
        $this->assertIsArray($result);
        $this->assertEmpty($result['tokens']);
        $this->assertEquals("", $result['original']);
    }
    
    public function testAnalyzeUnicode(): void
    {
        $text = "Café naïve résumé 北京 Москва";
        $result = $this->analyzer->analyze($text);
        
        $this->assertContains('café', $result['tokens']);
        $this->assertContains('naïv', $result['tokens']); // stemmed
        $this->assertContains('résumé', $result['tokens']);
        $this->assertContains('北京', $result['tokens']);
        $this->assertContains('москва', $result['tokens']);
    }
    
    public function testGetStopWords(): void
    {
        // Test English stop words
        $stopWords = $this->analyzer->getStopWords('en');
        $this->assertIsArray($stopWords);
        $this->assertNotEmpty($stopWords);
        $this->assertContains('the', $stopWords);
        $this->assertContains('and', $stopWords);
        $this->assertContains('is', $stopWords);
        
        // Test other languages
        $frenchStopWords = $this->analyzer->getStopWords('french');
        $this->assertContains('le', $frenchStopWords);
        $this->assertContains('de', $frenchStopWords);
        
        $germanStopWords = $this->analyzer->getStopWords('german');
        $this->assertContains('der', $germanStopWords);
        $this->assertContains('die', $germanStopWords);
    }
    
    public function testCustomStopWords(): void
    {
        // Test custom stop words via constructor
        $customStopWords = ['custom', 'stop', 'words'];
        $analyzer = new StandardAnalyzer([
            'custom_stop_words' => $customStopWords
        ]);
        
        $result = $analyzer->analyze("This has custom stop words in text");
        
        // Custom stop words should be removed
        $this->assertNotContains('custom', $result['tokens']);
        $this->assertNotContains('stop', $result['tokens']);
        $this->assertNotContains('word', $result['tokens']); // Both 'words' and 'word' should be removed
        $this->assertContains('text', $result['tokens']);
        
        // Default stop words should still be removed
        $this->assertNotContains('this', $result['tokens']);
        $this->assertNotContains('has', $result['tokens']);
        $this->assertNotContains('in', $result['tokens']);
    }
    
    public function testCaseSensitivity(): void
    {
        $text = "PHP php PhP";
        $result = $this->analyzer->analyze($text);
        
        // Should be case-insensitive by default
        $uniqueTokens = array_unique($result['tokens']);
        $this->assertCount(1, $uniqueTokens);
        $this->assertContains('php', $uniqueTokens);
    }
    
    public function testPerformanceWithLargeText(): void
    {
        // Generate large text
        $words = [];
        for ($i = 0; $i < 1000; $i++) {
            $words[] = "word{$i}";
        }
        $largeText = implode(' ', $words);
        
        $startTime = microtime(true);
        $result = $this->analyzer->analyze($largeText);
        $duration = microtime(true) - $startTime;
        
        // Should complete within reasonable time (< 1 second)
        $this->assertLessThan(1.0, $duration);
        $this->assertCount(1000, $result['tokens']);
    }
    
    public function testSetCustomStopWords(): void
    {
        $analyzer = new StandardAnalyzer();
        
        // Set custom stop words after initialization
        $analyzer->setCustomStopWords(['foo', 'bar', 'baz']);
        
        $result = $analyzer->analyze("This is foo and bar with some baz content");
        
        // Custom stop words should be removed
        $this->assertNotContains('foo', $result['tokens']);
        $this->assertNotContains('bar', $result['tokens']);
        $this->assertNotContains('baz', $result['tokens']);
        $this->assertContains('content', $result['tokens']);
    }
    
    public function testAddCustomStopWord(): void
    {
        $analyzer = new StandardAnalyzer();
        
        // Add individual stop words
        $analyzer->addCustomStopWord('rocket');
        $analyzer->addCustomStopWord('engine');
        
        $result = $analyzer->analyze("The rocket has a powerful engine for propulsion");
        
        // Added stop words should be removed
        $this->assertNotContains('rocket', $result['tokens']);
        $this->assertNotContains('engin', $result['tokens']); // 'engine' gets stemmed
        $this->assertContains('power', $result['tokens']); // 'powerful' gets stemmed
        $this->assertContains('propuls', $result['tokens']); // 'propulsion' gets stemmed
    }
    
    public function testRemoveCustomStopWord(): void
    {
        $analyzer = new StandardAnalyzer([
            'custom_stop_words' => ['alpha', 'beta', 'gamma']
        ]);
        
        // Remove one custom stop word
        $analyzer->removeCustomStopWord('beta');
        
        $result = $analyzer->analyze("Testing alpha beta gamma values");
        
        // 'beta' should now appear in tokens
        $this->assertNotContains('alpha', $result['tokens']);
        $this->assertContains('beta', $result['tokens']);
        $this->assertNotContains('gamma', $result['tokens']);
        $this->assertContains('valu', $result['tokens']); // 'values' gets stemmed
    }
    
    public function testGetCustomStopWords(): void
    {
        $customWords = ['one', 'two', 'three'];
        $analyzer = new StandardAnalyzer([
            'custom_stop_words' => $customWords
        ]);
        
        $retrievedWords = $analyzer->getCustomStopWords();
        
        $this->assertEquals($customWords, $retrievedWords);
        
        // Test that words are normalized to lowercase
        $analyzer->setCustomStopWords(['Upper', 'CASE', 'Words']);
        $retrievedWords = $analyzer->getCustomStopWords();
        
        $this->assertEquals(['upper', 'case', 'words'], $retrievedWords);
    }
    
    public function testDisableStopWords(): void
    {
        $analyzer = new StandardAnalyzer([
            'disable_stop_words' => true
        ]);
        
        $result = $analyzer->analyze("The quick brown fox jumps over the lazy dog");
        
        // All words should be present (except stemming still applies)
        $this->assertContains('the', $result['tokens']);
        $this->assertContains('quick', $result['tokens']);
        $this->assertContains('brown', $result['tokens']);
        $this->assertContains('fox', $result['tokens']);
        $this->assertContains('jump', $result['tokens']); // 'jumps' gets stemmed
        $this->assertContains('over', $result['tokens']);
        $this->assertContains('lazi', $result['tokens']); // 'lazy' gets stemmed
        $this->assertContains('dog', $result['tokens']);
    }
    
    public function testSetStopWordsDisabled(): void
    {
        $analyzer = new StandardAnalyzer();
        
        // Initially stop words are removed
        $result = $analyzer->analyze("The test is complete");
        $this->assertNotContains('the', $result['tokens']);
        $this->assertNotContains('is', $result['tokens']);
        
        // Disable stop words
        $analyzer->setStopWordsDisabled(true);
        $result = $analyzer->analyze("The test is complete");
        $this->assertContains('the', $result['tokens']);
        $this->assertContains('is', $result['tokens']);
        
        // Re-enable stop words
        $analyzer->setStopWordsDisabled(false);
        $result = $analyzer->analyze("The test is complete");
        $this->assertNotContains('the', $result['tokens']);
        $this->assertNotContains('is', $result['tokens']);
    }
    
    public function testIsStopWordsDisabled(): void
    {
        $analyzer1 = new StandardAnalyzer();
        $this->assertFalse($analyzer1->isStopWordsDisabled());
        
        $analyzer2 = new StandardAnalyzer(['disable_stop_words' => true]);
        $this->assertTrue($analyzer2->isStopWordsDisabled());
        
        $analyzer1->setStopWordsDisabled(true);
        $this->assertTrue($analyzer1->isStopWordsDisabled());
    }
    
    public function testCustomStopWordsWithMultipleLanguages(): void
    {
        $analyzer = new StandardAnalyzer([
            'custom_stop_words' => ['rocket', 'fusée', 'rakete']
        ]);
        
        // Test English
        $result = $analyzer->analyze("The rocket launches into space", 'english');
        $this->assertNotContains('rocket', $result['tokens']);
        $this->assertContains('launch', $result['tokens']);
        
        // Test French
        $result = $analyzer->analyze("La fusée décolle dans l'espace", 'french');
        $this->assertNotContains('fusée', $result['tokens']);
        $this->assertContains('décoll', $result['tokens']); // stemmed
        
        // Test German
        $result = $analyzer->analyze("Die Rakete startet in den Weltraum", 'german');
        $this->assertNotContains('raket', $result['tokens']); // 'rakete' gets stemmed
        $this->assertContains('startet', $result['tokens']); // 'startet' is the stemmed form in German
    }
    
    public function testCustomStopWordsAreCaseInsensitive(): void
    {
        $analyzer = new StandardAnalyzer([
            'custom_stop_words' => ['Rocket', 'ENGINE', 'Space']
        ]);
        
        $result = $analyzer->analyze("The ROCKET has an engine for space travel");
        
        // All variations should be removed regardless of case
        $this->assertNotContains('rocket', $result['tokens']);
        $this->assertNotContains('ROCKET', $result['tokens']);
        $this->assertNotContains('engin', $result['tokens']); // 'engine' gets stemmed
        $this->assertNotContains('space', $result['tokens']);
        $this->assertContains('travel', $result['tokens']);
    }
    
    public function testCustomStopWordsWithDuplicates(): void
    {
        $analyzer = new StandardAnalyzer();
        
        // Add duplicates
        $analyzer->addCustomStopWord('test');
        $analyzer->addCustomStopWord('test');
        $analyzer->addCustomStopWord('TEST');
        
        $customWords = $analyzer->getCustomStopWords();
        
        // Should only contain one instance
        $this->assertCount(1, $customWords);
        $this->assertEquals(['test'], $customWords);
    }
    
    public function testCustomStopWordsWithWhitespace(): void
    {
        $analyzer = new StandardAnalyzer([
            'custom_stop_words' => ['  trimmed  ', "\ttabbed\t", "\nnewline\n"]
        ]);
        
        $customWords = $analyzer->getCustomStopWords();
        
        // Words should be trimmed
        $this->assertEquals(['trimmed', 'tabbed', 'newline'], $customWords);
        
        $result = $analyzer->analyze("This is trimmed and tabbed with newline text");
        $this->assertNotContains('trimmed', $result['tokens']);
        $this->assertNotContains('tabbed', $result['tokens']);
        $this->assertNotContains('newline', $result['tokens']);
    }
}