<?php

namespace YetiSearch\Tests\Unit\Utils;

use PHPUnit\Framework\TestCase;
use YetiSearch\Utils\Fts5Escaper;

class Fts5EscaperTest extends TestCase
{
    /** @return array<string, array{string}> */
    public function keywordsWithATrailingNewline(): array
    {
        return [
            'NOT' => ["NOT\n"],
            'AND' => ["AND\n"],
            'OR' => ["OR\n"],
            'NEAR' => ["NEAR\n"],
            'word' => ["word\n"],
        ];
    }

    /** @dataProvider keywordsWithATrailingNewline */
    public function testATermWithATrailingNewlineIsNotBare(string $term): void
    {
        $this->assertFalse(Fts5Escaper::isBareTerm($term));
        $this->assertSame('"' . $term . '"', Fts5Escaper::escapeToken($term));
    }

    public function testBareTermsStayBare(): void
    {
        $this->assertTrue(Fts5Escaper::isBareTerm('café'));
        $this->assertSame('word*', Fts5Escaper::escapeToken('word*'));
        $this->assertSame('"NOT"', Fts5Escaper::escapeToken('NOT'));
    }

    public function testNulBytesAreRemoved(): void
    {
        $this->assertSame('ab', Fts5Escaper::escapeToken("a\0b"));
        $this->assertSame('"a-b"', Fts5Escaper::escapeToken("a-\0b"));
        $this->assertSame('ab', Fts5Escaper::escapeToken("a\0b", true));
        $this->assertSame('', Fts5Escaper::escapeToken("\0"));
    }

    public function testEveryEscapedTermIsAValidMatchExpression(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE VIRTUAL TABLE f USING fts5(title)');
        $pdo->exec("INSERT INTO f VALUES ('alpha beta')");

        foreach (["NOT\n", "AND\n", "OR\n", "NEAR\n", "a\0\"", "\0\"b", "x\n"] as $term) {
            $match = Fts5Escaper::escapeToken($term);
            if ($match === '') {
                continue;
            }
            $pdo->query('SELECT rowid FROM f WHERE f MATCH ' . $pdo->quote($match))->fetchAll();
            $this->addToAssertionCount(1);
        }
    }
}
