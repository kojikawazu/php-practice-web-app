<?php

declare(strict_types=1);

namespace ApplicationTest\Service;

use Application\Service\PasswordHasher;
use PHPUnit\Framework\TestCase;

class PasswordHasherTest extends TestCase
{
    private PasswordHasher $hasher;

    protected function setUp(): void
    {
        $this->hasher = new PasswordHasher();
    }

    // ---- 正常系 ----

    public function testHashIsVerifiableWithCorrectPassword(): void
    {
        $hash = $this->hasher->hash('secret-password');

        $this->assertTrue($this->hasher->verify('secret-password', $hash));
    }

    public function testHashIsNotPlainText(): void
    {
        $hash = $this->hasher->hash('secret-password');

        $this->assertNotSame('secret-password', $hash);
        $this->assertStringStartsWith('$2y$', $hash); // bcrypt
    }

    // ---- 準正常系・異常系 ----

    public function testVerifyFailsWithWrongPassword(): void
    {
        $hash = $this->hasher->hash('secret-password');

        $this->assertFalse($this->hasher->verify('wrong-password', $hash));
    }

    public function testVerifyFailsWithEmptyPassword(): void
    {
        $hash = $this->hasher->hash('secret-password');

        $this->assertFalse($this->hasher->verify('', $hash));
    }

    public function testVerifyFailsAgainstGarbageHash(): void
    {
        $this->assertFalse($this->hasher->verify('secret-password', 'not-a-real-hash'));
    }

    public function testSamePasswordProducesDifferentHashes(): void
    {
        // bcrypt はソルトを含むため同じ平文でもハッシュは毎回異なる
        $this->assertNotSame(
            $this->hasher->hash('same-password'),
            $this->hasher->hash('same-password')
        );
    }
}
