<?php

declare(strict_types=1);

namespace ApplicationTest\InputFilter;

use Application\InputFilter\RegisterInputFilter;
use PHPUnit\Framework\TestCase;

class RegisterInputFilterTest extends TestCase
{
    private function filter(): RegisterInputFilter
    {
        return new RegisterInputFilter();
    }

    // ---- 正常系 ----

    public function testValidInputPasses(): void
    {
        $filter = $this->filter();
        $filter->setData(['username' => '  taro  ', 'password' => 'password123']);

        $this->assertTrue($filter->isValid());
        $this->assertSame('taro', $filter->getValues()['username']); // trim される
    }

    public function testPasswordIsNotTrimmed(): void
    {
        $filter = $this->filter();
        $filter->setData(['username' => 'taro', 'password' => ' 12345678 ']);

        $this->assertTrue($filter->isValid());
        $this->assertSame(' 12345678 ', $filter->getValues()['password']);
    }

    // ---- 準正常系・異常系 ----

    public function testMissingUsernameIsInvalid(): void
    {
        $filter = $this->filter();
        $filter->setData(['password' => 'password123']);

        $this->assertFalse($filter->isValid());
        $this->assertArrayHasKey('username', $filter->getMessages());
    }

    public function testEmptyUsernameIsInvalid(): void
    {
        $filter = $this->filter();
        $filter->setData(['username' => '', 'password' => 'password123']);

        $this->assertFalse($filter->isValid());
    }

    public function testShortPasswordIsInvalid(): void
    {
        $filter = $this->filter();
        $filter->setData(['username' => 'taro', 'password' => 'short']);

        $this->assertFalse($filter->isValid());
        $this->assertArrayHasKey('password', $filter->getMessages());
    }

    public function testMissingPasswordIsInvalid(): void
    {
        $filter = $this->filter();
        $filter->setData(['username' => 'taro']);

        $this->assertFalse($filter->isValid());
    }
}
