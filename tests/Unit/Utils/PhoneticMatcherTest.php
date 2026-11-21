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

    /**
     * The keys PHP's metaphone() gives these words, one or more for each of its rules
     *
     * @dataProvider metaphoneKeys
     */
    public function testMetaphoneGivesTheKeysOfPhpsMetaphone(string $word, string $key): void
    {
        $this->assertSame($key, PhoneticMatcher::metaphone($word));
        $this->assertSame($key, PhoneticMatcher::metaphone(strtoupper($word)));
    }

    public function metaphoneKeys(): array
    {
        return [
            ['aebersold', 'EBRSLT'],
            ['gnome', 'NM'],
            ['knight', 'NFT'],
            ['pneumatic', 'NMTK'],
            ['wright', 'RFT'],
            ['whale', 'WL'],
            ['water', 'WTR'],
            ['xavier', 'SFR'],
            ['eagle', 'EKL'],
            ['ice', 'IS'],
            ['ocean', 'OSN'],
            ['under', 'UNTR'],
            ['bomb', 'BM'],
            ['climb', 'KLM'],
            ['science', 'SNS'],
            ['scene', 'SN'],
            ['cyber', 'SBR'],
            ['ciao', 'X'],
            ['special', 'SPXL'],
            ['chord', 'XRT'],
            ['school', 'SXL'],
            ['schwarz', 'SXWRS'],
            ['church', 'XRX'],
            ['edge', 'EJ'],
            ['judge', 'JJ'],
            ['budge', 'BJ'],
            ['laugh', 'LF'],
            ['tough', 'TF'],
            ['night', 'NFT'],
            ['though', '0'],
            ['bought', 'BT'],
            ['sign', 'SN'],
            ['signed', 'SNT'],
            ['gnashing', 'NXNK'],
            ['george', 'JRJ'],
            ['giant', 'JNT'],
            ['gigolo', 'JKL'],
            ['bigger', 'BKR'],
            ['hello', 'HL'],
            ['ahead', 'AHT'],
            ['behind', 'BHNT'],
            ['lock', 'LK'],
            ['acknowledge', 'AKNLJ'],
            ['phone', 'FN'],
            ['shepherd', 'XFRT'],
            ['queen', 'KN'],
            ['station', 'STXN'],
            ['mission', 'MSN'],
            ['asia', 'AX'],
            ['sugar', 'SKR'],
            ['think', '0NK'],
            ['thomas', '0MS'],
            ['match', 'MX'],
            ['watch', 'WX'],
            ['ratio', 'RX'],
            ['vivid', 'FFT'],
            ['wow', 'W'],
            ['yes', 'YS'],
            ['yonder', 'YNTR'],
            ['azure', 'ASR'],
            ['fizz', 'FS'],
            ['mississippi', 'MSSP'],
            ['accept', 'AKSPT'],
            ['succeed', 'SKST'],
            ['c', 'K'],
            ['x', 'S'],
            ['h', ''],
            ['y', ''],
            ['w', ''],
            ['ghost', 'FST'],
            ['dough', 'T'],
            ['hugh', 'HF'],
            ['agnes', 'AKNS'],
            ['ghana', 'FN'],
            ['cough', 'KF'],
            ['', ''],
            ['42 - 7', ''],
            ["pho\0ne", 'F'],
            ['café', 'KF'],
        ];
    }

    public function testMetaphoneAgreesWithPhpsOnAsciiWords(): void
    {
        if (!function_exists('metaphone')) {
            $this->markTestSkipped('This PHP has no metaphone() to compare with');
        }
        mt_srand(3);
        $letters = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789 -'.";
        for ($i = 0; $i < 20000; $i++) {
            $word = '';
            for ($j = mt_rand(0, 12); $j > 0; $j--) {
                $word .= $letters[mt_rand(0, strlen($letters) - 1)];
            }
            // @: metaphone() is deprecated from PHP 8.6
            $this->assertSame(@metaphone($word), PhoneticMatcher::metaphone($word), $word);
        }
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