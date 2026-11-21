<?php

namespace YetiSearch\Tests\Unit\Utils;

use PHPUnit\Framework\TestCase;
use YetiSearch\Utils\JaroWinkler;
use YetiSearch\Utils\KeyboardProximity;
use YetiSearch\Utils\Levenshtein;
use YetiSearch\Utils\PhoneticMatcher;
use YetiSearch\Utils\Trigram;

/**
 * The similarity measures count characters, not bytes, whatever the script.
 */
class SimilarityCharactersTest extends TestCase
{
    public function test_levenshtein_counts_characters(): void
    {
        $this->assertSame(1, Levenshtein::distance('über', 'uber'));
        $this->assertSame(1, Levenshtein::distance('日本語', '日本'));
        $this->assertSame(1, Levenshtein::distance('naïve', 'nave'));
        $this->assertSame(4, Levenshtein::distance('', 'café'));
        $this->assertSame(3, Levenshtein::distance('kitten', 'sitting'));
        $this->assertSame(0.75, Levenshtein::similarity('über', 'uber'));
        $this->assertTrue(Levenshtein::isWithinDistance('straße', 'strase', 1));
    }

    public function test_trigrams_and_jaro_winkler_count_characters(): void
    {
        $this->assertSame(['  é', ' ét', 'été', 'té ', 'é  '], Trigram::generateNgrams('Été'));
        $this->assertSame(Trigram::similarity('cafe', 'cafes'), Trigram::similarity('café', 'cafés'));
        $this->assertSame(JaroWinkler::similarity('martha', 'marhta'), JaroWinkler::similarity('märthä', 'märhtä'));
        $this->assertEqualsWithDelta(0.9611, JaroWinkler::similarity('martha', 'marhta'), 0.0001);
    }

    public function test_words_without_latin_letters_have_no_phonetic_likeness(): void
    {
        $this->assertSame(0.0, PhoneticMatcher::phoneticSimilarity('Москва', 'Берлин'));
        $this->assertSame(0.0, PhoneticMatcher::phoneticSimilarity('日本', '中国'));
        $this->assertSame(0.0, PhoneticMatcher::phoneticSimilarity('123', '456'));
        $this->assertFalse(PhoneticMatcher::isPhoneticTypo('123', '456'));
        $this->assertSame(1.0, PhoneticMatcher::phoneticSimilarity('phone', 'fone'));
    }

    public function test_the_same_key_is_no_distance_away_even_off_the_layout(): void
    {
        $this->assertSame(0.0, KeyboardProximity::keyDistance('_', '_'));
        $this->assertSame(0.0, KeyboardProximity::keyDistance('é', 'É'));
        $this->assertSame(
            KeyboardProximity::proximityScore('naive', 'naice'),
            KeyboardProximity::proximityScore('naïve', 'naïce')
        );
        $this->assertSame(0.8, KeyboardProximity::proximityScore('café', 'cafés'));
        $this->assertTrue(KeyboardProximity::isLikelyKeyboardTypo('naïve', 'naïv'));
    }
}
