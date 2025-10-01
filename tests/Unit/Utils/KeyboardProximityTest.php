<?php

namespace YetiSearch\Tests\Unit\Utils;

use YetiSearch\Tests\TestCase;
use YetiSearch\Utils\KeyboardProximity;

class KeyboardProximityTest extends TestCase
{
    public function testKeyDistance(): void
    {
        // Adjacent keys should have small distance
        $this->assertLessThan(2.0, KeyboardProximity::keyDistance('q', 'w'));
        $this->assertLessThan(2.0, KeyboardProximity::keyDistance('a', 's'));
        $this->assertLessThan(2.0, KeyboardProximity::keyDistance('z', 'x'));
        
        // Far keys should have larger distance
        $this->assertGreaterThan(3.0, KeyboardProximity::keyDistance('q', 'p'));
        $this->assertGreaterThan(3.0, KeyboardProximity::keyDistance('a', 'l'));
        
        // Same key should have zero distance
        $this->assertEquals(0.0, KeyboardProximity::keyDistance('a', 'a'));
    }
    
    public function testStringDistance(): void
    {
        // Similar strings with adjacent key substitutions
        $this->assertLessThan(2.0, KeyboardProximity::stringDistance('qwert', 'qwert'));
        $this->assertLessThan(2.0, KeyboardProximity::stringDistance('qwert', 'qwert'));
        
        // Different length strings should have large distance
        $this->assertEquals(10.0, KeyboardProximity::stringDistance('cat', 'cats'));
    }
    
    public function testIsLikelyKeyboardTypo(): void
    {
        // Adjacent key substitutions
        $this->assertTrue(KeyboardProximity::isLikelyKeyboardTypo('q', 'w'));
        $this->assertTrue(KeyboardProximity::isLikelyKeyboardTypo('s', 'd'));
        
        // String-level keyboard typos
        $this->assertTrue(KeyboardProximity::isLikelyKeyboardTypo('qwert', 'qwert'));
        $this->assertTrue(KeyboardProximity::isLikelyKeyboardTypo('hello', 'hrllo'));
        
        // Not keyboard typos
        $this->assertFalse(KeyboardProximity::isLikelyKeyboardTypo('q', 'p'));
        $this->assertFalse(KeyboardProximity::isLikelyKeyboardTypo('cat', 'dog'));
        $this->assertFalse(KeyboardProximity::isLikelyKeyboardTypo('hello', 'world'));
    }
    
    public function testProximityScore(): void
    {
        // Perfect match
        $this->assertEquals(1.0, KeyboardProximity::proximityScore('test', 'test'));
        
        // Close matches should have high scores
        $this->assertGreaterThan(0.7, KeyboardProximity::proximityScore('qwert', 'qwert'));
        $this->assertGreaterThan(0.7, KeyboardProximity::proximityScore('hello', 'hrllo'));
        
        // Distant matches should have low scores
        $this->assertLessThan(0.3, KeyboardProximity::proximityScore('q', 'p'));
        $this->assertEquals(0.0, KeyboardProximity::proximityScore('cat', 'dog'));
    }
    
    public function testIsAdjacentTypo(): void
    {
        // Adjacent keys
        $this->assertTrue(KeyboardProximity::isAdjacentTypo('q', 'w'));
        $this->assertTrue(KeyboardProximity::isAdjacentTypo('w', 'q'));
        $this->assertTrue(KeyboardProximity::isAdjacentTypo('a', 's'));
        $this->assertTrue(KeyboardProximity::isAdjacentTypo('s', 'a'));
        
        // Non-adjacent keys
        $this->assertFalse(KeyboardProximity::isAdjacentTypo('q', 'p'));
        $this->assertFalse(KeyboardProximity::isAdjacentTypo('a', 'l'));
        
        // Case insensitive
        $this->assertTrue(KeyboardProximity::isAdjacentTypo('Q', 'w'));
        $this->assertTrue(KeyboardProximity::isAdjacentTypo('q', 'W'));
    }
}