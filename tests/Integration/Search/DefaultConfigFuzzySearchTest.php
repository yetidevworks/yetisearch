<?php

namespace YetiSearch\Tests\Integration\Search;

use YetiSearch\Tests\TestCase;

/**
 * Typo correction on a small catalogue, at the library's own defaults.
 *
 * Every other fuzzy test sets `min_term_frequency => 1` explicitly, so none of
 * them ever ran the configuration a consumer gets out of the box. That mattered
 * more than it looks: the floor used to be two, meaning a term had to appear in
 * at least two documents before it could be corrected *to*. On a catalogue that
 * excludes almost the entire vocabulary worth correcting — a product name, a
 * brand or a person's name is in exactly one document by its nature — so a shop
 * with twelve products got no typo correction at all while a large prose corpus
 * got it, and nothing here said so.
 *
 * So the fixture is deliberately hostile to that: ten documents, and every term
 * this test corrects towards appears in exactly one of them. Nothing overrides
 * a search setting.
 */
class DefaultConfigFuzzySearchTest extends TestCase
{
    private string $index = 'default_config_fuzzy_test';

    protected function setUp(): void
    {
        parent::setUp();

        // Deliberately no 'search' config at all: the point is the defaults.
        $this->createSearchInstance();

        $this->createTestIndex($this->index);

        foreach ([
            ['id' => 'p1',  'title' => 'Mollie',        'content' => 'Take payments through Mollie.'],
            ['id' => 'p2',  'title' => 'Paddle',        'content' => 'Merchant of record billing with Paddle.'],
            ['id' => 'p3',  'title' => 'Licenses',      'content' => 'Issue a license key for every purchase.'],
            ['id' => 'p4',  'title' => 'Subscriptions', 'content' => 'Recurring plans and renewals.'],
            ['id' => 'p5',  'title' => 'Shopify',       'content' => 'Import a catalogue from Shopify.'],
            ['id' => 'p6',  'title' => 'Chargebee',     'content' => 'Billing through Chargebee.'],
            ['id' => 'p7',  'title' => 'Lemonsqueezy',  'content' => 'Sell through Lemonsqueezy.'],
            ['id' => 'p8',  'title' => 'Invoiceninja',  'content' => 'Invoicing with Invoiceninja.'],
            ['id' => 'p9',  'title' => 'Square',        'content' => 'Card terminals from Square.'],
            ['id' => 'p10', 'title' => 'Sumup',         'content' => 'Card readers from Sumup.'],
        ] as $doc) {
            $this->search->index($this->index, [
                'id' => $doc['id'],
                'content' => ['title' => $doc['title'], 'content' => $doc['content']],
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function idsFor(string $term): array
    {
        $results = $this->search->search($this->index, $term, ['fuzzy' => true, 'limit' => 10]);

        return array_column($results['results'] ?? [], 'id');
    }

    /**
     * @dataProvider typos
     */
    public function testATypoOfATermInOneDocumentIsStillCorrected(string $typo, string $expectedId): void
    {
        $this->assertContains(
            $expectedId,
            $this->idsFor($typo),
            "'{$typo}' should have been corrected to the term in {$expectedId}"
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function typos(): array
    {
        return [
            // Each target term appears in exactly one document.
            'a substituted letter'  => ['molie', 'p1'],
            'a transposed pair'     => ['mollei', 'p1'],
            'a dropped letter'      => ['padle', 'p2'],
            'the British spelling'  => ['licence', 'p3'],
            'a truncated word'      => ['licens', 'p3'],
            'a singular for plural' => ['subscription', 'p4'],
            'a dropped letter, longer word' => ['subscriptons', 'p4'],
        ];
    }

    public function testAnExactTermStillMatchesItsOwnDocument(): void
    {
        $this->assertContains('p1', $this->idsFor('mollie'));
        $this->assertContains('p4', $this->idsFor('subscriptions'));
    }

    /**
     * Correction is not a licence to match anything: a word with no near
     * neighbour in the index still comes back empty rather than being bent
     * onto whichever term is closest.
     */
    public function testAWordThatIsInNoDocumentMatchesNothing(): void
    {
        $this->assertSame([], $this->idsFor('kayak'));
        $this->assertSame([], $this->idsFor('helicopter'));
    }
}
