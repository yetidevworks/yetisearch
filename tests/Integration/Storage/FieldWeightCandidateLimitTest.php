<?php

namespace YetiSearch\Tests\Integration\Storage;

use YetiSearch\Tests\TestCase;

class FieldWeightCandidateLimitTest extends TestCase
{
    public function test_field_weight_candidate_limit_defaults_to_conservative_cap(): void
    {
        $storage = $this->createStorageInstance();
        $storage->createIndex('fw_limit_idx');

        $debug = $storage->search('fw_limit_idx', [
            'query' => 'rocket',
            'field_weights' => ['title' => 10.0],
            'limit' => 20,
            'offset' => 0,
            '_debug_sql' => true,
        ]);

        $params = $debug['_params'];
        $this->assertGreaterThanOrEqual(2, count($params));
        $this->assertSame(400, (int)$params[count($params) - 2]);
    }

    public function test_field_weight_candidate_limit_respects_explicit_cap(): void
    {
        $storage = $this->createStorageInstance();
        $storage->createIndex('fw_limit_idx2');

        $debug = $storage->search('fw_limit_idx2', [
            'query' => 'rocket',
            'field_weights' => ['title' => 10.0],
            'limit' => 50,
            'offset' => 0,
            'field_weight_candidate_cap' => 150,
            '_debug_sql' => true,
        ]);

        $params = $debug['_params'];
        $this->assertGreaterThanOrEqual(2, count($params));
        $this->assertSame(150, (int)$params[count($params) - 2]);
    }
}
