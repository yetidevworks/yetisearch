<?php

namespace YetiSearch\Tests\Unit\Utils;

use PHPUnit\Framework\TestCase;
use YetiSearch\Utils\Trigram;

class TrigramTest extends TestCase
{
    /**
     * Test n-gram generation
     */
    public function testGenerateNgrams()
    {
        // Test trigrams with padding
        $ngrams = Trigram::generateNgrams('test', 3, true);
        $this->assertEquals(['  t', ' te', 'tes', 'est', 'st ', 't  '], $ngrams);
        
        // Test trigrams without padding
        $ngrams = Trigram::generateNgrams('test', 3, false);
        $this->assertEquals(['tes', 'est'], $ngrams);
        
        // Test bigrams
        $ngrams = Trigram::generateNgrams('test', 2, true);
        $this->assertEquals([' t', 'te', 'es', 'st', 't '], $ngrams);
        
        // Test empty string
        $ngrams = Trigram::generateNgrams('', 3, true);
        $this->assertEquals([], $ngrams);
        
        // Test single character
        $ngrams = Trigram::generateNgrams('a', 3, true);
        $this->assertEquals(['  a', ' a ', 'a  '], $ngrams);
    }
    
    /**
     * Test exact matches
     */
    public function testExactMatches()
    {
        $this->assertEquals(1.0, Trigram::similarity('test', 'test'));
        $this->assertEquals(1.0, Trigram::similarity('rocket', 'rocket'));
        $this->assertEquals(1.0, Trigram::dice('test', 'test'));
    }
    
    /**
     * Test completely different strings
     */
    public function testCompletelyDifferent()
    {
        // No common trigrams
        $this->assertEquals(0.0, Trigram::similarity('abc', 'xyz'));
        $this->assertEquals(0.0, Trigram::similarity('', 'test'));
        $this->assertEquals(0.0, Trigram::similarity('test', ''));
    }
    
    /**
     * Test known similarities
     */
    public function testKnownSimilarities()
    {
        // Similar words should have high similarity
        $this->assertGreaterThan(0.1, Trigram::similarity('night', 'nite'));
        $this->assertGreaterThan(0.3, Trigram::similarity('color', 'colour'));
        $this->assertGreaterThan(0.4, Trigram::similarity('test', 'tests'));
        
        // Less similar words should have lower similarity
        $this->assertLessThan(0.4, Trigram::similarity('test', 'best'));
        $this->assertLessThan(0.2, Trigram::similarity('cat', 'dog'));
    }
    
    /**
     * Test Star Wars specific example
     */
    public function testStarWarsExample()
    {
        // Test the specific case from the user's request
        $similarity1 = Trigram::similarity('Anakin', 'Amakin');
        $similarity2 = Trigram::similarity('Skywalker', 'Dkywalker');
        
        // Both should have reasonable similarity
        $this->assertGreaterThan(0.4, $similarity1);
        $this->assertGreaterThan(0.5, $similarity2);
        
        // Test against unrelated words
        $this->assertLessThan(0.2, Trigram::similarity('Anakin', 'amazon'));
        $this->assertLessThan(0.2, Trigram::similarity('Anakin', 'amazing'));
    }
    
    /**
     * Test Dice coefficient
     */
    public function testDiceCoefficient()
    {
        $this->assertEquals(1.0, Trigram::dice('test', 'test'));
        
        // Dice coefficient should be different from Jaccard similarity
        $jaccard = Trigram::similarity('test', 'text');
        $dice = Trigram::dice('test', 'text');
        $this->assertNotEquals($jaccard, $dice);
        
        // But both should indicate some similarity
        $this->assertGreaterThan(0, $jaccard);
        $this->assertGreaterThan(0, $dice);
    }
    
    /**
     * Test best matches functionality
     */
    public function testFindBestMatches()
    {
        $candidates = ['rocket', 'socket', 'pocket', 'bracket', 'ticket', 'rocks'];
        $matches = Trigram::findBestMatches('rocket', $candidates, 0.3);
        
        // Should find exact match first
        $this->assertEquals('rocket', $matches[0][0]);
        $this->assertEquals(1.0, $matches[0][1]);
        
        // Should include similar words
        $matchedTerms = array_column($matches, 0);
        $this->assertContains('socket', $matchedTerms);
        $this->assertContains('pocket', $matchedTerms);
    }
    
    /**
     * Test threshold functionality
     */
    public function testMeetsThreshold()
    {
        $this->assertTrue(Trigram::meetsThreshold('test', 'test', 0.9));
        $this->assertTrue(Trigram::meetsThreshold('test', 'tests', 0.4));
        $this->assertFalse(Trigram::meetsThreshold('test', 'best', 0.4));
        
        // Early termination for very different lengths
        $this->assertFalse(Trigram::meetsThreshold('a', 'abcdefghij', 0.5));
    }
    
    /**
     * Test index key generation
     */
    public function testGenerateIndexKey()
    {
        $key = Trigram::generateIndexKey('test');
        $this->assertEquals('tes est', $key);
        
        $key = Trigram::generateIndexKey('rocket');
        $this->assertEquals('roc ock cke ket', $key);
        
        // Should have unique trigrams
        $key = Trigram::generateIndexKey('testtest');
        $this->assertEquals('tes est stt tte', $key);
    }
    
    /**
     * Test different n-gram sizes
     */
    public function testDifferentNgramSizes()
    {
        // Bigrams
        $sim2 = Trigram::similarity('test', 'text', 2);
        $this->assertGreaterThan(0.4, $sim2);
        
        // Trigrams (default)
        $sim3 = Trigram::similarity('test', 'text', 3);
        $this->assertGreaterThan(0.1, $sim3);
        
        // 4-grams
        $sim4 = Trigram::similarity('test', 'text', 4);
        $this->assertGreaterThan(0.0, $sim4); // May have some common 4-grams with padding
        
        // Larger n-grams are less forgiving
        $this->assertGreaterThan($sim3, $sim2);
        $this->assertGreaterThanOrEqual($sim4, $sim3);
    }
    
    /**
     * Test multibyte string support
     */
    public function testMultibyteStrings()
    {
        // Test with Unicode characters
        $this->assertEquals(1.0, Trigram::similarity('café', 'café'));
        $ngrams = Trigram::generateNgrams('café', 3, false);
        $this->assertEquals(['caf', 'afé'], $ngrams);
        
        // Test with emoji
        $this->assertEquals(1.0, Trigram::similarity('🚀rocket', '🚀rocket'));
        $this->assertGreaterThan(0.4, Trigram::similarity('🚀rocket', '🚀rock'));
    }
    
    /**
     * Test case insensitivity
     */
    public function testCaseInsensitivity()
    {
        // Trigrams are generated from lowercase
        $this->assertEquals(1.0, Trigram::similarity('TEST', 'test'));
        $this->assertEquals(1.0, Trigram::similarity('RoCkEt', 'ROCKET'));
        
        $ngrams1 = Trigram::generateNgrams('TEST', 3, false);
        $ngrams2 = Trigram::generateNgrams('test', 3, false);
        $this->assertEquals($ngrams1, $ngrams2);
    }
}