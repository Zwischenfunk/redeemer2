<?php

namespace App\Tests\Http;

use PHPUnit\Framework\TestCase;

final class IntentionalFailureTest extends TestCase
{
    public function testFails(): void
    {
        self::assertSame(1, 2);
    }
}
