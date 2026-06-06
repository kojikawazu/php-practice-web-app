<?php

declare(strict_types=1);

namespace ApplicationTest\InputFilter;

use Application\InputFilter\LoginInputFilter;
use PHPUnit\Framework\TestCase;

class LoginInputFilterTest extends TestCase
{
    private function filter(): LoginInputFilter
    {
        return new LoginInputFilter();
    }

    // ---- 正常系 ----

    public function testValidInputPasses(): void
    {
        $filter = $this->filter();
        $filter->setData(['username' => 'taro', 'password' => 'whatever']);

        $this->assertTrue($filter->isValid());
    }

    // ---- 準正常系・異常系 ----

    public function testMissingUsernameIsInvalid(): void
    {
        $filter = $this->filter();
        $filter->setData(['password' => 'whatever']);

        $this->assertFalse($filter->isValid());
        $this->assertArrayHasKey('username', $filter->getMessages());
    }

    public function testMissingPasswordIsInvalid(): void
    {
        $filter = $this->filter();
        $filter->setData(['username' => 'taro']);

        $this->assertFalse($filter->isValid());
        $this->assertArrayHasKey('password', $filter->getMessages());
    }

    public function testEmptyValuesAreInvalid(): void
    {
        $filter = $this->filter();
        $filter->setData(['username' => '', 'password' => '']);

        $this->assertFalse($filter->isValid());
    }
}
