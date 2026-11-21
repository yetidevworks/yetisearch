<?php

namespace YetiSearch\Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use YetiSearch\Models\SearchQuery;

class SearchQueryHighlightLengthTest extends TestCase
{
    public function testHighlightKeepsALengthSetBefore(): void
    {
        $query = (new SearchQuery('x'))->snippetLength(20)->highlight(true);

        $this->assertTrue($query->shouldHighlight());
        $this->assertSame(20, $query->getHighlightLength());
    }

    public function testHighlightSetsALengthWhenGiven(): void
    {
        $query = (new SearchQuery('x'))->snippetLength(20)->highlight(true, 300);

        $this->assertSame(300, $query->getHighlightLength());
        $this->assertSame(150, (new SearchQuery('x'))->highlight()->getHighlightLength());
    }
}
