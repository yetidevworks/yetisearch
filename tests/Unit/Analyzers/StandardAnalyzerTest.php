<?php

namespace YetiSearch\Tests\Unit\Analyzers;

use YetiSearch\Tests\TestCase;
use YetiSearch\Analyzers\StandardAnalyzer;
use YetiSearch\Stemmer\StemmerFactory;
use YetiSearch\Stemmer\StemmerInterface;
use YetiSearch\Stemmer\Languages\EnglishStemmer;
use YetiSearch\Stemmer\Languages\FrenchStemmer;
use YetiSearch\Stemmer\Languages\GermanStemmer;
use YetiSearch\Stemmer\Languages\SpanishStemmer;

class StandardAnalyzerTest extends TestCase
{
    private StandardAnalyzer $analyzer;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new StandardAnalyzer();
    }

    protected function tearDown(): void
    {
        StemmerFactory::reset();
        parent::tearDown();
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
        $this->assertContains('ordinat', $result['tokens']); // 'ordinateurs' gets stemmed to 'ordinat'
        
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
        $this->assertNotContains('rakete', $result['tokens']); // 'rakete' gets stemmed
        $this->assertContains('start', $result['tokens']); // 'startet' gets stemmed to 'start'
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

    public function testStopWordsByCodeNameAndLocale(): void
    {
        $french = $this->analyzer->getStopWords('french');

        $this->assertContains('les', $french);
        $this->assertNotContains('the', $french);
        $this->assertSame($french, $this->analyzer->getStopWords('fr'));
        $this->assertSame($french, $this->analyzer->getStopWords('FR'));
        $this->assertSame($french, $this->analyzer->getStopWords('fr_FR'));
        $this->assertSame($french, $this->analyzer->getStopWords('fr-CA'));
        $this->assertSame($french, $this->analyzer->getStopWords('francais'));

        $this->assertContains('der', $this->analyzer->getStopWords('de'));
        $this->assertContains('el', $this->analyzer->getStopWords('es'));
        $this->assertSame($this->analyzer->getStopWords('english'), $this->analyzer->getStopWords('en_US'));
    }

    public function testStopWordsOfAnEmptyLanguageAreEnglish(): void
    {
        $english = $this->analyzer->getStopWords('english');

        $this->assertSame($english, $this->analyzer->getStopWords(''));
        $this->assertSame(['quick', 'fox'], $this->analyzer->removeStopWords(['the', 'quick', 'fox'], null));
        $this->assertSame(['quick', 'fox'], $this->analyzer->removeStopWords(['the', 'quick', 'fox'], ''));
    }

    public function testLanguageCodeRemovesThatLanguagesStopWordsOnly(): void
    {
        // 'a' and 'the' are English stop words; 'les' is French
        $this->assertSame(['a', 'the', 'chanson'], $this->analyzer->removeStopWords(['les', 'a', 'the', 'chanson'], 'fr'));
        $this->assertSame(['les', 'chanson'], $this->analyzer->removeStopWords(['les', 'a', 'the', 'chanson'], 'en'));
    }

    public function testUnknownLanguageHasNoStopWords(): void
    {
        $this->assertSame([], $this->analyzer->getStopWords('it'));
        $this->assertSame([], $this->analyzer->getStopWords('klingon'));
        $this->assertSame(['il', 'the', 'gatto'], $this->analyzer->removeStopWords(['il', 'the', 'gatto'], 'it'));
    }

    public function testCustomStopWordsMergeIntoWhateverListApplies(): void
    {
        $analyzer = new StandardAnalyzer(['custom_stop_words' => ['Foo', 'bar']]);

        $french = $analyzer->getStopWords('fr');
        $this->assertContains('les', $french);
        $this->assertContains('foo', $french);
        $this->assertContains('bar', $french);
        $this->assertSame(['foo', 'bar'], $analyzer->getStopWords('it'));
        $this->assertSame(['gatto'], $analyzer->removeStopWords(['foo', 'gatto', 'bar'], 'it'));
    }

    public function testStopWordsOptionReplacesAndProvidesLists(): void
    {
        $analyzer = new StandardAnalyzer([
            'stop_words' => [
                'fr' => ['Alors', 'donc'],
                'Italiano' => ['il', 'la'],
            ],
            'custom_stop_words' => ['extra'],
        ]);

        // Replaces the built-in French list, found by any name of the language
        $this->assertSame(['alors', 'donc', 'extra'], $analyzer->getStopWords('french'));
        $this->assertSame(['alors', 'donc', 'extra'], $analyzer->getStopWords('fr_CA'));
        $this->assertNotContains('les', $analyzer->getStopWords('fr'));
        // Provides a list for a language that has none, keyed by a lowercased name
        $this->assertSame(['il', 'la', 'extra'], $analyzer->getStopWords('italiano'));
        // Leaves the other built-in lists alone
        $this->assertContains('the', $analyzer->getStopWords('en'));
    }

    public function testStopWordsOptionFollowsALanguageRegisteredLater(): void
    {
        $analyzer = new StandardAnalyzer(['stop_words' => ['it' => ['il', 'la']]]);
        StemmerFactory::register('italian', ItalianTestStemmer::class, ['it', 'ita']);

        $this->assertSame(['il', 'la'], $analyzer->getStopWords('italian'));
        $this->assertSame(['il', 'la'], $analyzer->getStopWords('ita'));
        $this->assertSame(['il', 'la'], $analyzer->getStopWords('it_IT'));
    }

    public function testStopWordsOptionCanBeDisabledAsAWhole(): void
    {
        $analyzer = new StandardAnalyzer(['stop_words' => ['it' => ['il']], 'disable_stop_words' => true]);

        $this->assertSame(['il', 'gatto'], $analyzer->removeStopWords(['il', 'gatto'], 'it'));
    }

    public function testStemLeavesALanguageWithoutAStemmerAlone(): void
    {
        $this->assertSame('gatti', $this->analyzer->stem('gatti', 'it'));
        $this->assertSame('running', $this->analyzer->stem('running', 'klingon'));
        // null and empty still mean English, a locale uses its language
        $this->assertSame('run', $this->analyzer->stem('running'));
        $this->assertSame('run', $this->analyzer->stem('running', null));
        $this->assertSame('run', $this->analyzer->stem('running', ''));
        $this->assertSame('run', $this->analyzer->stem('running', 'en_US'));
        $this->assertSame('chanson', $this->analyzer->stem('chansons', 'fr_FR'));
    }

    public function testAnalyzeDoesNotStemAnUnsupportedLanguage(): void
    {
        $result = $this->analyzer->analyze('running cats', 'it');

        $this->assertSame(['running', 'cats'], $result['tokens']);
        $this->assertSame('it', $result['language']);
        $this->assertSame(['run', 'cat'], $this->analyzer->analyze('running cats')['tokens']);
    }

    public function testStemUsesAStemmerRegisteredAfterTheAnalyzerWasUsed(): void
    {
        $this->assertSame('gatti', $this->analyzer->stem('gatti', 'it'));

        StemmerFactory::register('italian', ItalianTestStemmer::class, ['it']);
        $this->assertSame('gatt', $this->analyzer->stem('gatti', 'it'));

        StemmerFactory::register('english', ItalianTestStemmer::class);
        $this->assertSame('runn', $this->analyzer->stem('running'));

        StemmerFactory::reset();
        $this->assertSame('run', $this->analyzer->stem('running'));
    }

    public function testRememberedStemsFollowAStemmerRegisteredReplacedOrReset(): void
    {
        // Every word is stemmed and then asked for again, so the second answer is a remembered one
        $this->assertSame('run', $this->analyzer->stem('running'));
        $this->assertSame(['run'], $this->analyzer->analyze('running')['tokens']);

        StemmerFactory::register('english', ItalianTestStemmer::class);
        $this->assertSame('runn', $this->analyzer->stem('running'));
        $this->assertSame(['runn'], $this->analyzer->analyze('running')['tokens']);

        StemmerFactory::register('english', EwokTestStemmer::class);
        $this->assertSame('running!', $this->analyzer->stem('running'));
        $this->assertSame(['running!'], $this->analyzer->analyze('running')['tokens']);

        StemmerFactory::reset();
        $this->assertSame('run', $this->analyzer->stem('running'));
        $this->assertSame(['run'], $this->analyzer->analyze('running')['tokens']);
    }

    public function testRememberedStemsFollowALanguageRegisteredUnderAnAliasOfAnother(): void
    {
        $this->assertSame('chanson', $this->analyzer->stem('chansons', 'fr'));

        // 'fr' now means another language
        StemmerFactory::register('frenchish', EwokTestStemmer::class, ['fr']);

        $this->assertSame('chansons!', $this->analyzer->stem('chansons', 'fr'));
        $this->assertSame('chanson', $this->analyzer->stem('chansons', 'french'));
    }

    public function testRememberedStemsAreDroppedByClearCache(): void
    {
        $made = 0;
        StemmerFactory::register('english', function () use (&$made) {
            $made++;

            return $made === 1 ? new ItalianTestStemmer() : new EwokTestStemmer();
        });

        $this->assertSame('runn', $this->analyzer->stem('running'));
        $this->assertSame('runn', $this->analyzer->stem('running'));
        $this->assertSame(1, $made);

        // The cached instance goes, and the callable makes the next one
        StemmerFactory::clearCache();
        $this->assertSame('running!', $this->analyzer->stem('running'));
        $this->assertSame(2, $made);
    }

    public function testCustomStemmersAreCalledForEveryWord(): void
    {
        $calls = new \ArrayObject();
        StemmerFactory::register('english', new CountingTestStemmer($calls));

        $analyzer = new StandardAnalyzer();
        $this->assertSame(['cat', 'dog', 'cat', 'cat'], $analyzer->analyze('cats dogs cats cats')['tokens']);
        $this->assertSame('cat', $analyzer->stem('cats'));

        $this->assertSame(['cats', 'dogs', 'cats', 'cats', 'cats'], $calls->getArrayCopy());
        $this->assertSame([], $this->privateProperty($analyzer, 'stemMemo'));
    }

    public function testStatefulCustomStemmersAndBuiltInSubclassesAreNotMemoized(): void
    {
        $stemmers = [
            new class implements StemmerInterface {
                private int $calls = 0;
                public function stem(string $word): string { return $word . ++$this->calls; }
                public function getLanguage(): string { return 'en'; }
            },
            new class extends EnglishStemmer {
                private int $calls = 0;
                public function stem(string $word): string { return $word . ++$this->calls; }
            },
            new class extends FrenchStemmer {
                private int $calls = 0;
                public function stem(string $word): string { return $word . ++$this->calls; }
            },
            new class extends GermanStemmer {
                private int $calls = 0;
                public function stem(string $word): string { return $word . ++$this->calls; }
            },
            new class extends SpanishStemmer {
                private int $calls = 0;
                public function stem(string $word): string { return $word . ++$this->calls; }
            },
        ];
        foreach ($stemmers as $stemmer) {
            StemmerFactory::register('english', $stemmer);
            $analyzer = new StandardAnalyzer();
            $this->assertSame('cats1', $analyzer->stem('cats'));
            $this->assertSame('cats2', $analyzer->stem('cats'));
            $this->assertSame(['cats3', 'cats4'], $analyzer->analyze('cats cats')['tokens']);
            $this->assertSame([], $this->privateProperty($analyzer, 'stemMemo'));
        }
    }

    public function testAllFourExactBuiltInClassesRememberStems(): void
    {
        foreach (['english', 'french', 'german', 'spanish'] as $language) {
            $analyzer = new StandardAnalyzer();
            $expected = StemmerFactory::create($language)->stem('chansons');
            $this->assertSame($expected, $analyzer->stem('chansons', $language));
            $this->assertSame($expected, $analyzer->stem('chansons', $language));
            $this->assertSame([$language => ['chansons' => $expected]], $this->privateProperty($analyzer, 'stemMemo'));
            $this->assertSame(1, $this->privateProperty($analyzer, 'stemMemoSize'));
        }
    }

    public function testRememberedStemsStayUnderTheirLimit(): void
    {
        $limit = $this->privateConstant('STEM_MEMO_LIMIT');
        $analyzer = new StandardAnalyzer();

        for ($i = 0; $i < $limit + 25; $i++) {
            $this->assertSame('w' . $i, $analyzer->stem('w' . $i));
        }

        $memo = $this->privateProperty($analyzer, 'stemMemo');
        $remembered = 0;
        foreach ($memo as $words) {
            $remembered += count($words);
        }
        $this->assertLessThanOrEqual($limit, $remembered);
        $this->assertSame($remembered, $this->privateProperty($analyzer, 'stemMemoSize'));
        $this->assertGreaterThan(0, $remembered);

        // What was forgotten is stemmed again, and the same
        $this->assertSame('w0', $analyzer->stem('w0'));
    }

    public function testAVeryLongWordIsNotRemembered(): void
    {
        $long = str_repeat('a', 200) . 's';

        $this->assertSame(str_repeat('a', 200), $this->analyzer->stem($long));
        $this->assertSame([], $this->privateProperty($this->analyzer, 'stemMemo'));
        $this->assertSame('cat', $this->analyzer->stem('cats'));
        $this->assertSame(1, $this->privateProperty($this->analyzer, 'stemMemoSize'));
    }

    public function testOverriddenStopWordProviderIsAskedOnEveryCall(): void
    {
        $analyzer = new class extends StandardAnalyzer {
            public array $words = ['fox'];
            public int $calls = 0;
            public function getStopWords(string $language): array
            {
                $this->calls++;
                return $this->words;
            }
        };
        $this->assertSame(['dog'], $analyzer->removeStopWords(['fox', 'dog'], 'en'));
        $analyzer->words = ['dog'];
        $this->assertSame(['fox'], $analyzer->removeStopWords(['fox', 'dog'], 'en'));
        $this->assertSame(['fox'], $analyzer->analyze('fox dog', 'en')['tokens']);
        $this->assertSame(3, $analyzer->calls);
        $this->assertSame([], $this->privateProperty($analyzer, 'stopWordSets'));
    }

    public function testInheritedStopWordOverrideIsAskedOnEveryCall(): void
    {
        $analyzer = new class extends DynamicStopWordTestAnalyzer {};
        $this->assertSame(['dog'], $analyzer->removeStopWords(['fox', 'dog']));
        $analyzer->words = ['dog'];
        $this->assertSame(['fox'], $analyzer->removeStopWords(['fox', 'dog']));
        $this->assertSame([], $this->privateProperty($analyzer, 'stopWordSets'));
    }

    public function testStopWordChangesAfterTheListWasUsedTakeEffect(): void
    {
        $analyzer = new StandardAnalyzer();
        $this->assertSame(['fox'], $analyzer->removeStopWords(['the', 'fox']));

        $analyzer->addCustomStopWord('Fox');
        $this->assertSame([], $analyzer->removeStopWords(['the', 'FOX']));

        $analyzer->removeCustomStopWord('fox');
        $this->assertSame(['fox'], $analyzer->removeStopWords(['the', 'fox']));

        $analyzer->setCustomStopWords(['fox', 'dog']);
        $this->assertSame(['cat'], $analyzer->removeStopWords(['dog', 'cat', 'fox']));

        $analyzer->setCustomStopWords([]);
        $this->assertSame(['dog', 'cat'], $analyzer->removeStopWords(['dog', 'cat', 'the']));

        $analyzer->setStopWordsDisabled(true);
        $this->assertSame(['the', 'cat'], $analyzer->removeStopWords(['the', 'cat']));
        $analyzer->setStopWordsDisabled(false);
        $this->assertSame(['cat'], $analyzer->removeStopWords(['the', 'cat']));
    }

    public function testStopWordsOfEachLanguageStayApart(): void
    {
        $analyzer = new StandardAnalyzer(['custom_stop_words' => ['extra']]);

        $this->assertSame(['les'], $analyzer->removeStopWords(['les', 'the', 'extra'], 'en'));
        $this->assertSame(['the', 'a'], $analyzer->removeStopWords(['les', 'the', 'a', 'extra'], 'fr'));
        $this->assertSame(['les', 'the', 'a'], $analyzer->removeStopWords(['les', 'the', 'a', 'extra'], 'it'));
        $this->assertSame(['les'], $analyzer->removeStopWords(['les', 'the'], 'english'));
        $this->assertSame(['the'], $analyzer->removeStopWords(['les', 'the'], 'fr_FR'));
    }

    public function testStopWordsFollowALanguageRegisteredAfterTheyWereUsed(): void
    {
        $analyzer = new StandardAnalyzer(['stop_words' => ['it' => ['il', 'la']]]);
        $this->assertSame(['il', 'gatto'], $analyzer->removeStopWords(['il', 'gatto'], 'italian'));
        $this->assertSame(['gatto'], $analyzer->removeStopWords(['il', 'gatto'], 'it'));

        StemmerFactory::register('italian', ItalianTestStemmer::class, ['it', 'ita']);

        $this->assertSame(['gatto'], $analyzer->removeStopWords(['il', 'gatto'], 'italian'));
        $this->assertSame(['gatto'], $analyzer->removeStopWords(['il', 'gatto'], 'ita'));

        StemmerFactory::reset();

        $this->assertSame(['il', 'gatto'], $analyzer->removeStopWords(['il', 'gatto'], 'italian'));
    }

    public function testNumericStopWordsMatchByValueAsTheyAlwaysDid(): void
    {
        $analyzer = new StandardAnalyzer(['custom_stop_words' => ['10', 'x1']]);

        // '010' and '1e1' are the number 10, as a loose comparison reads them
        $this->assertSame(['100', 'x10'], $analyzer->removeStopWords(['10', '010', '1e1', '100', 'a', 'x1', 'x10']));
    }

    public function testManyLanguagesDoNotGrowTheStopWordListsWithoutLimit(): void
    {
        $analyzer = new StandardAnalyzer();
        $limit = $this->privateConstant('STOP_WORD_SETS_LIMIT');

        for ($i = 0; $i < $limit * 3; $i++) {
            $this->assertSame(['the', 'fox'], $analyzer->removeStopWords(['the', 'fox'], 'xx' . $i));
        }

        $this->assertLessThanOrEqual($limit, count($this->privateProperty($analyzer, 'stopWordSets')));
        $this->assertSame(['fox'], $analyzer->removeStopWords(['the', 'fox'], 'english'));
    }

    private function privateConstant(string $name): int
    {
        return (new \ReflectionClassConstant(StandardAnalyzer::class, $name))->getValue();
    }

    /** @return mixed */
    private function privateProperty(StandardAnalyzer $analyzer, string $name)
    {
        $property = new \ReflectionProperty(StandardAnalyzer::class, $name);
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }

        return $property->getValue($analyzer);
    }
}

class DynamicStopWordTestAnalyzer extends StandardAnalyzer
{
    public array $words = ['fox'];
    public function getStopWords(string $language): array
    {
        return $this->words;
    }
}

class CountingTestStemmer implements StemmerInterface
{
    private \ArrayObject $calls;

    public function __construct(\ArrayObject $calls)
    {
        $this->calls = $calls;
    }

    public function stem(string $word): string
    {
        $this->calls[] = $word;

        return rtrim($word, 's');
    }

    public function getLanguage(): string
    {
        return 'en';
    }
}

class EwokTestStemmer implements StemmerInterface
{
    public function stem(string $word): string
    {
        return $word . '!';
    }

    public function getLanguage(): string
    {
        return 'ew';
    }
}

class ItalianTestStemmer implements StemmerInterface
{
    public function stem(string $word): string
    {
        return preg_replace('/(i|e|o|a|ing)$/', '', $word);
    }

    public function getLanguage(): string
    {
        return 'it';
    }
}
