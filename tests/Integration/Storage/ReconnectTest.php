<?php

namespace YetiSearch\Tests\Integration\Storage;

use YetiSearch\Cache\QueryCache;
use YetiSearch\Storage\SqliteStorage;
use YetiSearch\Tests\TestCase;

/**
 * A storage connected again reads every setting of its indexes afresh, as another connection may
 * have changed them in between, and has the query cache its new configuration asks for.
 */
class ReconnectTest extends TestCase
{
    public function test_a_reconnected_storage_uses_the_settings_the_index_has_now(): void
    {
        $path = $this->getTestDbPath();
        $worker = new SqliteStorage();
        $worker->connect(['path' => $path, 'external_content' => true]);
        $worker->createIndex('t', ['stemming' => true]);
        $worker->insert('t', ['id' => '1', 'content' => ['content' => 'running dogs']]);
        $this->assertSame('english', $worker->stemmingFor('t'));
        $worker->disconnect();

        $other = new SqliteStorage();
        $other->connect(['path' => $path, 'external_content' => true]);
        $other->rebuildFts('t', ['stemming' => false]);
        $other->disconnect();

        // The same object, as a long-running worker reconnecting after a dropped connection
        $worker->connect(['path' => $path, 'external_content' => true]);
        $this->assertNull($worker->stemmingFor('t'));
        $worker->insert('t', ['id' => '2', 'content' => ['content' => 'jumping cats']]);
        $this->assertSame(['2'], array_column($worker->search('t', ['query' => 'jumping']), 'id'));
        $worker->disconnect();
    }

    public function test_the_query_cache_follows_the_configuration(): void
    {
        $storage = new SqliteStorage();
        $storage->connect(['path' => ':memory:']);
        $this->assertNull($storage->getQueryCache());

        $storage->connect(['path' => ':memory:', 'cache' => ['enabled' => true]]);
        $this->assertInstanceOf(QueryCache::class, $storage->getQueryCache());

        $storage->disconnect();
        $this->assertNull($storage->getQueryCache());

        $storage->connect(['path' => ':memory:', 'cache' => ['enabled' => false]]);
        $this->assertNull($storage->getQueryCache(), 'A cache made for the earlier connection is not kept');
    }
}
