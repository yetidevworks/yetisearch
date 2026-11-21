<?php

namespace YetiSearch\Tests\Integration\Search;

use YetiSearch\Tests\TestCase;

class EnhancedFuzzySearchTest extends TestCase
{
    private string $index = 'enhanced_fuzzy_test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSearchInstance([
            'storage' => [
                'external_content' => true,
            ],
            'indexer' => [
                'fields' => [
                    'title' => ['boost' => 3.0, 'store' => true],
                    'content' => ['boost' => 1.0, 'store' => true],
                ],
                'chunk_size' => 500,
                'chunk_overlap' => 50,
            ],
            'search' => [
                'enable_fuzzy' => true,
                'fuzzy_correction_mode' => true,
                'fuzzy_algorithm' => 'trigram',
                'correction_threshold' => 0.6,
                'trigram_threshold' => 0.35,
                'fuzzy_score_penalty' => 0.25,
                'cache_ttl' => 0,
                'min_term_frequency' => 1,
            ],
        ]);

        $this->createTestIndex($this->index);
        $this->seedDocuments();
    }

    private function seedDocuments(): void
    {
        $docs = [
            [
                'id' => 'doc1',
                'content' => [
                    'title' => 'The Quick Brown Fox',
                    'content' => 'A quick brown fox jumps over the lazy dog.',
                ],
                'metadata' => ['route' => '/fox']
            ],
            [
                'id' => 'doc2',
                'content' => [
                    'title' => 'Phone Number Directory',
                    'content' => 'Contact us by phone for assistance.',
                ],
                'metadata' => ['route' => '/contact']
            ],
            [
                'id' => 'doc3',
                'content' => [
                    'title' => 'Their House',
                    'content' => 'This is their house and their car.',
                ],
                'metadata' => ['route' => '/house']
            ],
            [
                'id' => 'doc4',
                'content' => [
                    'title' => 'Keyboard Tutorial',
                    'content' => 'Learn to type on the keyboard efficiently.',
                ],
                'metadata' => ['route' => '/keyboard']
            ],
            [
                'id' => 'doc5',
                'content' => [
                    'title' => 'Search Engine',
                    'content' => 'How search engines work and rank results.',
                ],
                'metadata' => ['route' => '/search']
            ],
        ];

        foreach ($docs as $doc) {
            $this->search->index($this->index, $doc);
        }
    }

    public function testPhoneticTypoCorrection(): void
    {
        // Test phonetic typo: fone -> phone
        $results = $this->search->search($this->index, 'fone', [
            'fuzzy' => true,
            'limit' => 5
        ]);

        $this->assertGreaterThan(0, $results['total']);
        $ids = array_column($results['results'], 'id');
        $this->assertContains('doc2', $ids); // Should find the phone document
    }

    public function testKeyboardProximityTypoCorrection(): void
    {
        // Test keyboard proximity typo: qyick -> quick (q and w are adjacent, y and u are adjacent)
        $results = $this->search->search($this->index, 'qyick brown', [
            'fuzzy' => true,
            'limit' => 5
        ]);

        $this->assertGreaterThan(0, $results['total']);
        $ids = array_column($results['results'], 'id');
        $this->assertContains('doc1', $ids); // Should find the quick brown fox document
    }

    public function testCommonTypoPatterns(): void
    {
        // Test common typo: thier -> their
        $results = $this->search->search($this->index, 'thier house', [
            'fuzzy' => true,
            'limit' => 5
        ]);

        $this->assertGreaterThan(0, $results['total']);
        $ids = array_column($results['results'], 'id');
        $this->assertContains('doc3', $ids); // Should find the their house document
    }

    public function testMultipleTypoCorrection(): void
    {
        // Two typos in one query: qyick fone -> quick phone. No document has both words, and a
        // fuzzy search, like a search without fuzzy, finds the documents that have either
        $results = $this->search->search($this->index, 'qyick fone', [
            'fuzzy' => true,
            'limit' => 5
        ]);

        $ids = array_column($results['results'], 'id');
        $this->assertContains('doc1', $ids); // the quick brown fox
        $this->assertContains('doc2', $ids); // contact us by phone
    }

    public function testDidYouMeanSuggestions(): void
    {
        // A search without fuzzy finds nothing for the typos, and suggests the corrected words
        $results = $this->search->search($this->index, 'qyick fone', [
            'fuzzy' => false,
            'limit' => 5
        ]);

        $this->assertSame(0, $results['total']);
        $this->assertNotEmpty($results['suggestions'] ?? []);
    }

    public function testEnhancedVsBasicFuzzy(): void
    {
        // Test with a term that should only work with enhanced correction
        // 'thier' -> 'their' should work with phonetic matching in enhanced mode
        $basicResults = $this->search->search($this->index, 'thier', [
            'fuzzy' => true,
            'fuzzy_correction_mode' => false,
            'limit' => 10
        ]);

        // Test with correction mode enabled (enhanced fuzzy)
        $enhancedResults = $this->search->search($this->index, 'thier', [
            'fuzzy' => true,
            'fuzzy_correction_mode' => true,
            'limit' => 10
        ]);

        // Enhanced should find more results due to better typo correction
        $this->assertGreaterThan($basicResults['total'], $enhancedResults['total']);
    }

    public function testConfidenceScoring(): void
    {
        // Test that corrections have appropriate confidence scores
        $suggestions = $this->search->generateSuggestions($this->index, 'qyick', 3);

        if (!empty($suggestions)) {
            $this->assertArrayHasKey('confidence', $suggestions[0]);
            $this->assertArrayHasKey('type', $suggestions[0]);
            $this->assertArrayHasKey('original_token', $suggestions[0]);
            $this->assertArrayHasKey('correction', $suggestions[0]);
            
            // Confidence should be reasonable
            $this->assertGreaterThan(0.5, $suggestions[0]['confidence']);
            $this->assertLessThanOrEqual(1.0, $suggestions[0]['confidence']);
        }
    }

    private function getTestDocuments(): array
    {
        return [
            [
                'id' => 'doc1',
                'content' => [
                    'title' => 'The Quick Brown Fox',
                    'content' => 'A quick brown fox jumps over the lazy dog.',
                ],
                'metadata' => ['route' => '/fox']
            ],
            [
                'id' => 'doc2',
                'content' => [
                    'title' => 'Phone Number Directory',
                    'content' => 'Contact us by phone for assistance.',
                ],
                'metadata' => ['route' => '/contact']
            ],
            [
                'id' => 'doc3',
                'content' => [
                    'title' => 'Their House',
                    'content' => 'This is their house and their car.',
                ],
                'metadata' => ['route' => '/house']
            ],
            [
                'id' => 'doc4',
                'content' => [
                    'title' => 'Keyboard Tutorial',
                    'content' => 'Learn to type on the keyboard efficiently.',
                ],
                'metadata' => ['route' => '/keyboard']
            ],
            [
                'id' => 'doc5',
                'content' => [
                    'title' => 'Search Engine',
                    'content' => 'How search engines work and rank results.',
                ],
                'metadata' => ['route' => '/search']
            ],
        ];
    }
}