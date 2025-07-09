<?php

namespace YetiSearch\Tests\Fixtures;

use Faker\Factory;
use Faker\Generator;

class DataGenerator
{
    private Generator $faker;
    private array $categories = [
        'electronics', 'books', 'clothing', 'sports', 'home', 
        'toys', 'automotive', 'health', 'beauty', 'food'
    ];
    private array $articleCategories = [
        'technology', 'science', 'politics', 'sports', 
        'entertainment', 'business', 'health', 'travel'
    ];
    private array $languages = ['en', 'fr', 'de', 'es', 'it'];
    
    public function __construct(string $locale = 'en_US')
    {
        $this->faker = Factory::create($locale);
        $this->faker->seed(1234); // Consistent data for tests
    }
    
    /**
     * Generate e-commerce products
     */
    public function generateProducts(int $count = 100): array
    {
        $products = [];
        
        for ($i = 0; $i < $count; $i++) {
            $category = $this->faker->randomElement($this->categories);
            $products[] = [
                'id' => 'prod-' . ($i + 1),
                'content' => [
                    'title' => $this->generateProductTitle($category),
                    'description' => $this->faker->paragraph(3),
                    'features' => $this->faker->sentences(5),
                    'specifications' => $this->generateSpecs($category)
                ],
                'metadata' => [
                    'sku' => strtoupper($this->faker->bothify('??##??##')),
                    'category' => $category,
                    'brand' => $this->faker->company(),
                    'price' => $this->faker->randomFloat(2, 10, 2000),
                    'sale_price' => $this->faker->optional(0.3)->randomFloat(2, 5, 1500),
                    'in_stock' => $this->faker->boolean(80),
                    'stock_quantity' => $this->faker->numberBetween(0, 500),
                    'rating' => $this->faker->randomFloat(1, 1, 5),
                    'review_count' => $this->faker->numberBetween(0, 1000),
                    'tags' => $this->faker->words(5),
                    'color' => $this->faker->optional()->safeColorName(),
                    'size' => $this->faker->optional()->randomElement(['XS', 'S', 'M', 'L', 'XL', '2XL']),
                    'weight' => $this->faker->randomFloat(2, 0.1, 50),
                    'dimensions' => [
                        'length' => $this->faker->numberBetween(5, 100),
                        'width' => $this->faker->numberBetween(5, 100),
                        'height' => $this->faker->numberBetween(5, 100)
                    ]
                ],
                'type' => 'product',
                'language' => $this->faker->randomElement($this->languages),
                'timestamp' => $this->faker->unixTime()
            ];
        }
        
        return $products;
    }
    
    /**
     * Generate news articles
     */
    public function generateArticles(int $count = 50): array
    {
        $articles = [];
        
        for ($i = 0; $i < $count; $i++) {
            $publishedDate = $this->faker->dateTimeBetween('-1 year', 'now');
            $articles[] = [
                'id' => 'article-' . ($i + 1),
                'content' => [
                    'headline' => $this->faker->sentence(8),
                    'subheadline' => $this->faker->sentence(12),
                    'lead' => $this->faker->paragraph(2),
                    'body' => implode("\n\n", $this->faker->paragraphs(6)),
                    'conclusion' => $this->faker->paragraph()
                ],
                'metadata' => [
                    'author' => [
                        'id' => 'author-' . $this->faker->numberBetween(1, 20),
                        'name' => $this->faker->name(),
                        'email' => $this->faker->email(),
                        'bio' => $this->faker->sentence()
                    ],
                    'category' => $this->faker->randomElement($this->articleCategories),
                    'subcategory' => $this->faker->word(),
                    'tags' => $this->faker->words(8),
                    'published_date' => $publishedDate->format('Y-m-d'),
                    'updated_date' => $this->faker->optional()->dateTimeBetween($publishedDate, 'now')->format('Y-m-d'),
                    'read_time' => $this->faker->numberBetween(1, 15),
                    'views' => $this->faker->numberBetween(0, 100000),
                    'shares' => $this->faker->numberBetween(0, 5000),
                    'comments_count' => $this->faker->numberBetween(0, 500),
                    'featured' => $this->faker->boolean(20),
                    'editorial_review' => $this->faker->boolean(80)
                ],
                'type' => 'article',
                'language' => $this->faker->randomElement($this->languages),
                'timestamp' => $publishedDate->getTimestamp()
            ];
        }
        
        return $articles;
    }
    
    /**
     * Generate books
     */
    public function generateBooks(int $count = 30): array
    {
        $books = [];
        $genres = ['fiction', 'non-fiction', 'science', 'history', 'biography', 'technology', 'self-help'];
        
        for ($i = 0; $i < $count; $i++) {
            $books[] = [
                'id' => 'book-' . ($i + 1),
                'content' => [
                    'title' => $this->generateBookTitle(),
                    'subtitle' => $this->faker->optional()->sentence(6),
                    'summary' => $this->faker->paragraph(5),
                    'excerpt' => $this->faker->paragraphs(2, true),
                    'table_of_contents' => $this->generateChapters()
                ],
                'metadata' => [
                    'isbn' => $this->faker->isbn13(),
                    'author' => $this->faker->name(),
                    'co_authors' => $this->faker->optional()->randomElements([$this->faker->name(), $this->faker->name()]),
                    'publisher' => $this->faker->company() . ' Publishing',
                    'publication_date' => $this->faker->date(),
                    'edition' => $this->faker->numberBetween(1, 5),
                    'pages' => $this->faker->numberBetween(100, 800),
                    'genre' => $this->faker->randomElement($genres),
                    'subgenre' => $this->faker->word(),
                    'language' => $this->faker->randomElement($this->languages),
                    'format' => $this->faker->randomElement(['hardcover', 'paperback', 'ebook', 'audiobook']),
                    'price' => $this->faker->randomFloat(2, 9.99, 49.99),
                    'rating' => $this->faker->randomFloat(1, 3, 5),
                    'reviews' => $this->faker->numberBetween(0, 5000),
                    'bestseller' => $this->faker->boolean(10),
                    'awards' => $this->faker->optional()->words(3)
                ],
                'type' => 'book',
                'language' => $this->faker->randomElement($this->languages),
                'timestamp' => $this->faker->unixTime()
            ];
        }
        
        return $books;
    }
    
    /**
     * Generate technical documentation
     */
    public function generateDocumentation(int $count = 20): array
    {
        $docs = [];
        $docTypes = ['api', 'guide', 'tutorial', 'reference', 'whitepaper'];
        
        for ($i = 0; $i < $count; $i++) {
            $version = $this->faker->numerify('#.#.#');
            $docs[] = [
                'id' => 'doc-' . ($i + 1),
                'content' => [
                    'title' => $this->generateTechnicalTitle(),
                    'overview' => $this->faker->paragraph(3),
                    'sections' => $this->generateDocSections(),
                    'code_examples' => $this->generateCodeExamples()
                ],
                'metadata' => [
                    'doc_type' => $this->faker->randomElement($docTypes),
                    'version' => $version,
                    'release_date' => $this->faker->date(),
                    'deprecated' => $this->faker->boolean(10),
                    'tags' => $this->generateTechnicalTags(),
                    'programming_language' => $this->faker->randomElement(['PHP', 'Python', 'JavaScript', 'Java', 'C#']),
                    'framework' => $this->faker->optional()->randomElement(['Laravel', 'Symfony', 'React', 'Vue', 'Angular']),
                    'difficulty' => $this->faker->randomElement(['beginner', 'intermediate', 'advanced']),
                    'estimated_time' => $this->faker->numberBetween(5, 120) . ' minutes',
                    'prerequisites' => $this->faker->words(5),
                    'last_updated' => $this->faker->dateTimeBetween('-6 months', 'now')->format('Y-m-d')
                ],
                'type' => 'documentation',
                'language' => 'en', // Technical docs usually in English
                'timestamp' => $this->faker->unixTime()
            ];
        }
        
        return $docs;
    }
    
    /**
     * Generate multilingual content
     */
    public function generateMultilingualContent(int $itemsPerLanguage = 10): array
    {
        $content = [];
        
        foreach ($this->languages as $lang) {
            // Create locale-specific faker
            $localeFaker = $this->getLocaleSpecificFaker($lang);
            
            for ($i = 0; $i < $itemsPerLanguage; $i++) {
                $content[] = [
                    'id' => "ml-{$lang}-" . ($i + 1),
                    'content' => [
                        'title' => $this->getLocalizedTitle($lang, $localeFaker),
                        'description' => $localeFaker->paragraph(),
                        'keywords' => $localeFaker->words(10)
                    ],
                    'metadata' => [
                        'locale' => $this->getLocaleFromLanguage($lang),
                        'country' => $this->getCountryFromLanguage($lang),
                        'currency' => $this->getCurrencyFromLanguage($lang),
                        'translated_from' => $lang === 'en' ? null : 'en',
                        'translation_status' => $this->faker->randomElement(['human', 'machine', 'hybrid']),
                        'cultural_relevance' => $this->faker->randomElement(['global', 'regional', 'local'])
                    ],
                    'type' => 'content',
                    'language' => $lang,
                    'timestamp' => $this->faker->unixTime()
                ];
            }
        }
        
        return $content;
    }
    
    /**
     * Generate mixed content for cross-index testing
     */
    public function generateMixedContent(int $total = 100): array
    {
        $distribution = [
            'products' => 0.4,
            'articles' => 0.3,
            'books' => 0.2,
            'documentation' => 0.1
        ];
        
        $content = [];
        
        $content = array_merge($content, $this->generateProducts((int)($total * $distribution['products'])));
        $content = array_merge($content, $this->generateArticles((int)($total * $distribution['articles'])));
        $content = array_merge($content, $this->generateBooks((int)($total * $distribution['books'])));
        $content = array_merge($content, $this->generateDocumentation((int)($total * $distribution['documentation'])));
        
        // Shuffle to mix content types
        shuffle($content);
        
        return $content;
    }
    
    // Helper methods
    
    private function generateProductTitle(string $category): string
    {
        $adjectives = ['Premium', 'Professional', 'Advanced', 'Ultra', 'Pro', 'Essential', 'Deluxe'];
        $categoryTitles = [
            'electronics' => ['Wireless Headphones', 'Smart Watch', 'Bluetooth Speaker', 'Laptop', 'Tablet'],
            'books' => ['Hardcover Edition', 'Paperback Novel', 'Digital Edition', 'Collector\'s Edition'],
            'clothing' => ['Cotton T-Shirt', 'Denim Jeans', 'Wool Sweater', 'Leather Jacket'],
            'sports' => ['Running Shoes', 'Yoga Mat', 'Fitness Tracker', 'Water Bottle'],
            'home' => ['Kitchen Appliance', 'Bedding Set', 'Storage Solution', 'Decor Item']
        ];
        
        $titles = $categoryTitles[$category] ?? ['Product'];
        
        return $this->faker->randomElement($adjectives) . ' ' . $this->faker->randomElement($titles);
    }
    
    private function generateSpecs(string $category): array
    {
        $specs = [];
        
        switch ($category) {
            case 'electronics':
                $specs['battery_life'] = $this->faker->numberBetween(4, 48) . ' hours';
                $specs['warranty'] = $this->faker->numberBetween(1, 3) . ' years';
                $specs['connectivity'] = $this->faker->randomElements(['Bluetooth', 'WiFi', 'USB-C', '5G'], 2);
                break;
            case 'clothing':
                $specs['material'] = $this->faker->randomElement(['Cotton', 'Polyester', 'Wool', 'Silk']);
                $specs['care'] = $this->faker->randomElement(['Machine wash', 'Hand wash only', 'Dry clean']);
                break;
            default:
                $specs['warranty'] = $this->faker->numberBetween(6, 24) . ' months';
        }
        
        return $specs;
    }
    
    private function generateBookTitle(): string
    {
        $patterns = [
            'The ' . $this->faker->word() . ' of ' . $this->faker->word(),
            $this->faker->word() . ' and ' . $this->faker->word(),
            'A ' . $this->faker->word() . ' in ' . $this->faker->city(),
            $this->faker->firstName() . '\'s ' . $this->faker->word()
        ];
        
        return ucwords($this->faker->randomElement($patterns));
    }
    
    private function generateChapters(): array
    {
        $chapters = [];
        $count = $this->faker->numberBetween(8, 20);
        
        for ($i = 1; $i <= $count; $i++) {
            $chapters[] = "Chapter {$i}: " . $this->faker->sentence(4);
        }
        
        return $chapters;
    }
    
    private function generateTechnicalTitle(): string
    {
        $topics = ['Getting Started with', 'Advanced Guide to', 'Best Practices for', 'Understanding', 'Mastering'];
        $subjects = ['API Development', 'Database Design', 'Security', 'Performance Optimization', 'Testing'];
        
        return $this->faker->randomElement($topics) . ' ' . $this->faker->randomElement($subjects);
    }
    
    private function generateDocSections(): array
    {
        return [
            'Introduction' => $this->faker->paragraph(),
            'Prerequisites' => $this->faker->sentences(3, true),
            'Installation' => $this->faker->paragraph(),
            'Configuration' => $this->faker->paragraph(),
            'Usage' => $this->faker->paragraphs(3, true),
            'Troubleshooting' => $this->faker->paragraph()
        ];
    }
    
    private function generateCodeExamples(): array
    {
        return [
            'basic' => '// ' . $this->faker->sentence() . "\n" . 'function example() { return true; }',
            'advanced' => '// ' . $this->faker->sentence() . "\n" . 'class Example { /* ... */ }'
        ];
    }
    
    private function generateTechnicalTags(): array
    {
        $tags = ['api', 'rest', 'graphql', 'database', 'security', 'performance', 'testing', 'deployment'];
        return $this->faker->randomElements($tags, 5);
    }
    
    private function getLocaleSpecificFaker(string $lang): Generator
    {
        $localeMap = [
            'en' => 'en_US',
            'fr' => 'fr_FR',
            'de' => 'de_DE',
            'es' => 'es_ES',
            'it' => 'it_IT'
        ];
        
        return Factory::create($localeMap[$lang] ?? 'en_US');
    }
    
    private function getLocalizedTitle(string $lang, Generator $localeFaker): string
    {
        $titles = [
            'en' => 'Special Offer',
            'fr' => 'Offre Spéciale',
            'de' => 'Sonderangebot',
            'es' => 'Oferta Especial',
            'it' => 'Offerta Speciale'
        ];
        
        return ($titles[$lang] ?? 'Special Offer') . ' - ' . $localeFaker->words(3, true);
    }
    
    private function getLocaleFromLanguage(string $lang): string
    {
        $map = [
            'en' => 'en_US',
            'fr' => 'fr_FR',
            'de' => 'de_DE',
            'es' => 'es_ES',
            'it' => 'it_IT'
        ];
        
        return $map[$lang] ?? 'en_US';
    }
    
    private function getCountryFromLanguage(string $lang): string
    {
        $map = [
            'en' => 'United States',
            'fr' => 'France',
            'de' => 'Germany',
            'es' => 'Spain',
            'it' => 'Italy'
        ];
        
        return $map[$lang] ?? 'United States';
    }
    
    private function getCurrencyFromLanguage(string $lang): string
    {
        $map = [
            'en' => 'USD',
            'fr' => 'EUR',
            'de' => 'EUR',
            'es' => 'EUR',
            'it' => 'EUR'
        ];
        
        return $map[$lang] ?? 'USD';
    }
}