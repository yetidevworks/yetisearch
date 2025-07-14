<?php

namespace YetiSearch\Tests\Unit\Utils;

use PHPUnit\Framework\TestCase;
use YetiSearch\Utils\JaroWinkler;

class JaroWinklerTest extends TestCase
{
    /**
     * Test exact matches
     */
    public function testExactMatches()
    {
        $this->assertEquals(1.0, JaroWinkler::similarity('test', 'test'));
        $this->assertEquals(1.0, JaroWinkler::similarity('', ''));
        $this->assertEquals(1.0, JaroWinkler::jaro('test', 'test'));
    }
    
    /**
     * Test completely different strings
     */
    public function testCompletelyDifferent()
    {
        $this->assertEquals(0.0, JaroWinkler::jaro('abc', 'xyz'));
        $this->assertEquals(0.0, JaroWinkler::similarity('', 'test'));
        $this->assertEquals(0.0, JaroWinkler::similarity('test', ''));
    }
    
    /**
     * Test known Jaro-Winkler similarities
     */
    public function testKnownSimilarities()
    {
        // Classic examples
        $this->assertEqualsWithDelta(0.961, JaroWinkler::similarity('MARTHA', 'MARHTA'), 0.001);
        $this->assertEqualsWithDelta(0.813, JaroWinkler::similarity('DIXON', 'DICKSONX'), 0.001);
        $this->assertEqualsWithDelta(0.896, JaroWinkler::similarity('JELLYFISH', 'SMELLYFISH'), 0.001);
        
        // Names with typos
        $this->assertEqualsWithDelta(0.840, JaroWinkler::similarity('Dwayne', 'Duane'), 0.001);
        $this->assertEqualsWithDelta(0.962, JaroWinkler::similarity('Johnson', 'Jonson'), 0.001);
    }
    
    /**
     * Test prefix bonus effect
     */
    public function testPrefixBonus()
    {
        // Same Jaro similarity but different prefixes
        $jaro1 = JaroWinkler::jaro('PREFIXED', 'PREFIEXD');
        $jaro2 = JaroWinkler::jaro('XREFIXED', 'XREFIEXD');
        
        // Both should have similar Jaro scores
        $this->assertEqualsWithDelta($jaro1, $jaro2, 0.1);
        
        // But Jaro-Winkler should favor the one with matching prefix
        $jw1 = JaroWinkler::similarity('PREFIXED', 'PREFIEXD');
        $jw2 = JaroWinkler::similarity('XREFIXED', 'YREFIEXD');
        
        $this->assertGreaterThan($jw2, $jw1);
    }
    
    /**
     * Test threshold functionality
     */
    public function testMeetsThreshold()
    {
        $this->assertTrue(JaroWinkler::meetsThreshold('test', 'test', 0.9));
        $this->assertTrue(JaroWinkler::meetsThreshold('test', 'text', 0.8));
        $this->assertFalse(JaroWinkler::meetsThreshold('test', 'best', 0.9));
        
        // Early termination for very different lengths
        $this->assertFalse(JaroWinkler::meetsThreshold('a', 'abcdefghij', 0.8));
    }
    
    /**
     * Test best matches functionality
     */
    public function testFindBestMatches()
    {
        $candidates = ['test', 'text', 'best', 'rest', 'nest', 'tests', 'testing'];
        $matches = JaroWinkler::findBestMatches('test', $candidates, 0.8);
        
        // Should find exact match first
        $this->assertEquals('test', $matches[0][0]);
        $this->assertEquals(1.0, $matches[0][1]);
        
        // Should include close matches
        $matchedTerms = array_column($matches, 0);
        $this->assertContains('text', $matchedTerms);
        $this->assertContains('best', $matchedTerms);
        $this->assertContains('rest', $matchedTerms);
        $this->assertContains('tests', $matchedTerms);
    }
    
    /**
     * Test Star Wars specific example
     */
    public function testStarWarsExample()
    {
        // Test the specific case from the user's request
        $similarity1 = JaroWinkler::similarity('Anakin', 'Amakin');
        $similarity2 = JaroWinkler::similarity('Skywalker', 'Dkywalker');
        
        // Both should have high similarity
        $this->assertGreaterThan(0.85, $similarity1);
        $this->assertGreaterThan(0.85, $similarity2);
        
        // Test against unrelated words
        $this->assertLessThan(0.7, JaroWinkler::similarity('Anakin', 'amazon'));
        $this->assertLessThan(0.7, JaroWinkler::similarity('Anakin', 'amazing'));
    }
    
    /**
     * Test distance calculation
     */
    public function testDistance()
    {
        $this->assertEquals(0.0, JaroWinkler::distance('test', 'test'));
        $this->assertEquals(1.0, JaroWinkler::distance('', 'test'));
        
        // Distance should be 1 - similarity
        $similarity = JaroWinkler::similarity('test', 'text');
        $distance = JaroWinkler::distance('test', 'text');
        $this->assertEqualsWithDelta(1.0 - $similarity, $distance, 0.001);
    }
    
    /**
     * Test custom prefix scale
     */
    public function testCustomPrefixScale()
    {
        // Higher prefix scale should give more weight to prefix matches
        $sim1 = JaroWinkler::similarity('PREFIX', 'PREFOX', 0.1);
        $sim2 = JaroWinkler::similarity('PREFIX', 'PREFOX', 0.25);
        
        $this->assertGreaterThan($sim1, $sim2);
        
        // Invalid prefix scales should be clamped
        $sim3 = JaroWinkler::similarity('PREFIX', 'PREFOX', -0.5);
        $sim4 = JaroWinkler::similarity('PREFIX', 'PREFOX', 0.0);
        $this->assertEquals($sim4, $sim3);
        
        $sim5 = JaroWinkler::similarity('PREFIX', 'PREFOX', 0.5);
        $sim6 = JaroWinkler::similarity('PREFIX', 'PREFOX', 0.25);
        $this->assertEquals($sim6, $sim5); // Should be clamped to 0.25
    }
    
    /**
     * Test multibyte string support
     */
    public function testMultibyteStrings()
    {
        // Test with Unicode characters
        $this->assertEquals(1.0, JaroWinkler::similarity('café', 'café'));
        $this->assertGreaterThan(0.8, JaroWinkler::similarity('café', 'cafe'));
        
        // Test with emoji
        $this->assertEquals(1.0, JaroWinkler::similarity('🚀rocket', '🚀rocket'));
        $this->assertGreaterThan(0.8, JaroWinkler::similarity('🚀rocket', '🚀rockat'));
    }
}