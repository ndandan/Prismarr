<?php

namespace App\Tests\Util;

use App\Util\SafeUrl;
use PHPUnit\Framework\TestCase;

/**
 * Covers SafeUrl::httpOrNull() — the guard MediaController and ProwlarrClient
 * apply to an indexer-supplied `infoUrl` before it's ever handed to the
 * frontend as an <a href>. Upstream has no CSP to fall back on, so a
 * `javascript:`/`data:` scheme from a hostile indexer must be neutralized here.
 * (Previously duplicated as each client's private safeInfoUrl(); consolidated.)
 */
class SafeUrlTest extends TestCase
{
    public function testJavascriptSchemeIsRejected(): void
    {
        $this->assertNull(SafeUrl::httpOrNull('javascript:alert(1)'));
    }

    public function testDataSchemeIsRejected(): void
    {
        $this->assertNull(SafeUrl::httpOrNull('data:text/html,<script>alert(1)</script>'));
    }

    public function testHttpUrlIsKept(): void
    {
        $this->assertSame('http://tracker.example.com/details?id=1', SafeUrl::httpOrNull('http://tracker.example.com/details?id=1'));
    }

    public function testHttpsUrlIsKept(): void
    {
        $this->assertSame('https://tracker.example.com/details?id=1', SafeUrl::httpOrNull('https://tracker.example.com/details?id=1'));
    }

    public function testUppercaseSchemeIsKept(): void
    {
        $this->assertSame('HTTPS://tracker.example.com/details?id=1', SafeUrl::httpOrNull('HTTPS://tracker.example.com/details?id=1'));
    }

    public function testNonStringInputIsRejected(): void
    {
        $this->assertNull(SafeUrl::httpOrNull(null));
        $this->assertNull(SafeUrl::httpOrNull(123));
        $this->assertNull(SafeUrl::httpOrNull(['not' => 'a string']));
    }

    public function testEmptyStringIsRejected(): void
    {
        $this->assertNull(SafeUrl::httpOrNull(''));
    }

    public function testSchemeRelativeUrlIsRejected(): void
    {
        $this->assertNull(SafeUrl::httpOrNull('//tracker.example.com/details?id=1'));
    }
}
