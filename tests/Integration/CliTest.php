<?php

namespace YetiSearch\Tests\Integration;

use YetiSearch\Tests\TestCase;

class CliTest extends TestCase
{
    private string $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->getTestDbPath();
        $search = $this->createSearchInstance(['storage' => ['path' => $this->db]]);
        $this->createTestIndex('places');
        $search->indexBatch('places', [
            ['id' => 'sf', 'content' => ['title' => 'cafe sf'], 'geo' => ['lat' => 37.7749, 'lng' => -122.4194]],
            ['id' => 'oak', 'content' => ['title' => 'cafe oakland'], 'geo' => ['lat' => 37.8044, 'lng' => -122.2712]],
            ['id' => 'ny', 'content' => ['title' => 'cafe ny'], 'geo' => ['lat' => 40.7128, 'lng' => -74.0060]],
        ]);
        $search->close();
    }

    /**
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private function cli(string ...$args): array
    {
        $command = array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/bin/yetisearch'], $args, ['--db=' . $this->db]);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), (string)$stdout, (string)$stderr];
    }

    private function ids(string $json): array
    {
        $ids = array_column(json_decode($json, true)['results'] ?? [], 'id');
        sort($ids);

        return $ids;
    }

    public function testGeoUnitsSetTheRadiusUnitWhenUnitsIsNotGiven(): void
    {
        $near = ['search', '--index=places', '--query=cafe', '--lat=37.77', '--lng=-122.41', '--radius=20'];

        [$code, $out] = $this->cli(...array_merge($near, ['--geo-units=km']));
        $this->assertSame(0, $code);
        $this->assertSame(['oak', 'sf'], $this->ids($out));

        // --units wins over --geo-units
        [, $out] = $this->cli(...array_merge($near, ['--geo-units=km', '--units=m']));
        $this->assertSame([], $this->ids($out));
    }

    public function testOptionWithoutValueIsAnError(): void
    {
        [$code, $out, $err] = $this->cli('search', '--index', '--query=cafe');

        $this->assertSame(1, $code);
        $this->assertSame('', $out);
        $this->assertStringContainsString('Option --index needs a value', $err);
        $this->assertStringNotContainsString('TypeError', $err);
    }

    public function testDslAndUrlErrorsArePrinted(): void
    {
        // geo[units] as a list reaches SearchQuery::geoUnits() as an array: a TypeError, not an Exception
        [$code, , $err] = $this->cli('search-url', '--index=places', '--url=q=cafe&geo[units][]=km');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('URL parse error:', $err);
        // PHP 7 says 'must be of the type string'
        $this->assertMatchesRegularExpression('/must be of (the )?type string/', $err);

        [$code, , $err] = $this->cli('search-dsl', '--index=missing_index', '--dsl=cafe');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('DSL parse error:', $err);
    }
}
