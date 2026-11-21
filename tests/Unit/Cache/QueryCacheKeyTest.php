<?php

namespace YetiSearch\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use YetiSearch\Cache\QueryCache;

class QueryCacheKeyTest extends TestCase
{
    private QueryCache $cache;

    protected function setUp(): void
    {
        $this->cache = new QueryCache(new \PDO('sqlite::memory:'), ['enabled' => true]);
    }

    public function testEveryQueryParameterIsInTheKey(): void
    {
        $query = ['query' => 'x', 'field_weights' => ['title' => 2.0]];
        $this->cache->set('idx', $query + ['field_weight_candidate_cap' => 1], ['results' => [['id' => 'A']]]);

        foreach ([
            'field_weight_candidate_cap' => 500,
            'field_weight_candidate_multiplier' => 3,
            'field_weight_candidate_min' => 10,
            'field_weight_candidate_max' => 10,
        ] as $parameter => $value) {
            $this->assertNull($this->cache->get('idx', $query + [$parameter => $value]), $parameter);
        }
        $this->assertNotNull($this->cache->get('idx', $query + ['field_weight_candidate_cap' => 1]));
    }

    public function testRunOptionsAndNullsDoNotChangeTheKey(): void
    {
        $this->cache->set('idx', ['query' => 'y', 'limit' => 10], ['results' => [['id' => 'B']]]);

        $this->assertNotNull($this->cache->get('idx', ['limit' => 10, 'query' => 'y', 'bypass_cache' => false]));
        $this->assertNotNull($this->cache->get('idx', ['query' => 'y', 'limit' => 10, '_debug_sql' => true]));
        $this->assertNotNull($this->cache->get('idx', ['query' => 'y', 'limit' => 10, 'language' => null]));
    }
}
