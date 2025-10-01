<?php

namespace YetiSearch\Tests\Unit\Utils;

use YetiSearch\Tests\TestCase;
use YetiSearch\Utils\PhoneticMatcher;

class PhoneticMatcherTest extends TestCase
{
    public function testMetaphoneGeneration(): void
    {
        $this->assertEquals('FN', PhoneticMatcher::metaphone('phone'));
        $this->assertEquals('FN', PhoneticMatcher::metaphone('fone'));
        $this->assertEquals('0R', PhoneticMatcher::metaphone('their'));
        $this->assertEquals('0R', PhoneticMatcher::metaphone('there'));
    }
    
    public function testPhoneticSimilarity(): void
    {
        // Exact matches
        $this->assertEquals(1.0, PhoneticMatcher::phoneticSimilarity('phone', 'phone'));
        
        // Phonetic matches
        $this->assertEquals(1.0, PhoneticMatcher::phoneticSimilarity('phone', 'fone'));
        $this->assertEquals(1.0, PhoneticMatcher::phoneticSimilarity('their', 'there'));
        
        // Non-phonetic matches
        $this->assertLessThan(0.5, PhoneticMatcher::phoneticSimilarity('phone', 'table'));
    }
    
    public function testIsPhoneticTypo(): void
    {
        $this->assertTrue(PhoneticMatcher::isPhoneticTypo('fone', 'phone'));
        $this->assertTrue(PhoneticMatcher::isPhoneticTypo('thier', 'their'));
        $this->assertFalse(PhoneticMatcher::isPhoneticTypo('phone', 'table'));
        $this->assertFalse(PhoneticMatcher::isPhoneticTypo('cat', 'dog'));
    }
    
    public function testQuickPhoneticCorrection(): void
    {
        $this->assertEquals('phone', PhoneticMatcher::quickPhoneticCorrection('fone'));
        $this->assertEquals('their', PhoneticMatcher::quickPhoneticCorrection('thier'));
        $this->assertEquals('the', PhoneticMatcher::quickPhoneticCorrection('teh'));
        $this->assertEquals('and', PhoneticMatcher::quickPhoneticCorrection('adn'));
        $this->assertNull(PhoneticMatcher::quickPhoneticCorrection('correct'));
    }
    
    public function testFindPhoneticMatches(): void
    {
        $candidates = ['phone', 'fone', 'table', 'their', 'there', 'other'];
        $matches = PhoneticMatcher::findPhoneticMatches('fone', $candidates, 0.7);
        
        $this->assertNotEmpty($matches);
        $this->assertEquals('phone', $matches[0][0]); // Best match should be 'phone'
        $this->assertGreaterThanOrEqual(0.7, $matches[0][1]); // Score should be >= threshold
    }
}