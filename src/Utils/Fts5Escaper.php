<?php

namespace YetiSearch\Utils;

/**
 * Escape user terms so they are safe to hand to an FTS5 MATCH expression.
 *
 * Only bare alphanumeric terms are legal unquoted in the MATCH grammar.
 * Anything else — a hyphen in an order number or SKU, a colon, an asterisk,
 * a quote, a parenthesis — is an operator there and would either change the
 * meaning of the query or make SQLite reject it outright (a hyphen, for
 * instance, is read as a column filter, so "BENCH-100821" fails with
 * "no such column: 100821"). Such terms are wrapped in double quotes, which
 * makes FTS5 treat them as a literal phrase and re-tokenize them with the
 * table's own tokenizer.
 *
 * This deliberately escapes individual terms only. Operators a caller builds
 * around them (OR, NEAR(), phrase grouping) stay intact.
 */
class Fts5Escaper
{
    /**
     * FTS5 keywords that must be quoted when they appear as a user term,
     * otherwise the MATCH parser reads them as operators.
     */
    private const KEYWORDS = ['AND', 'OR', 'NOT', 'NEAR'];

    /**
     * Escape a single user term so it is safe to hand to an FTS5 MATCH expression.
     *
     * @param bool $inPhrase True when the token is being placed inside a
     *                       double-quoted phrase assembled by the caller.
     */
    public static function escapeToken(string $token, bool $inPhrase = false): string
    {
        if ($inPhrase) {
            // The caller wraps the whole phrase in double quotes, so the quote
            // character is the only one that can break out of it. FTS5 escapes
            // it by doubling.
            return str_replace('"', '""', $token);
        }

        // A trailing asterisk is the prefix operator added by prefix_last_token.
        // Keep it outside the quotes so it stays an operator instead of becoming
        // a literal character in the phrase.
        $suffix = '';
        if (substr($token, -1) === '*') {
            $token = substr($token, 0, -1);
            $suffix = '*';
        }

        if ($token === '') {
            return '';
        }

        if (self::isBareTerm($token)) {
            return $token . $suffix;
        }

        return '"' . str_replace('"', '""', $token) . '"' . $suffix;
    }

    /**
     * Escape a list of user terms, dropping any that escape to nothing.
     *
     * A token can escape to an empty string: it was empty to begin with, or it
     * was nothing but the prefix operator. Left in the list it gets joined into
     * the MATCH expression as a bare operand — "widget OR ", "NEAR(widget , 10)"
     * — which FTS5 rejects with a syntax error.
     *
     * @param array<int, string> $tokens
     * @return array<int, string>
     */
    public static function escapeTokens(array $tokens, bool $inPhrase = false): array
    {
        $escaped = [];

        foreach ($tokens as $token) {
            $value = self::escapeToken($token, $inPhrase);
            if ($value !== '') {
                $escaped[] = $value;
            }
        }

        return $escaped;
    }

    /**
     * True when a term can be passed to FTS5 MATCH without quoting.
     */
    public static function isBareTerm(string $token): bool
    {
        if (in_array(strtoupper($token), self::KEYWORDS, true)) {
            return false;
        }

        return (bool)preg_match('/^[\p{L}\p{N}]+$/u', $token);
    }
}
