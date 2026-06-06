<?php

declare(strict_types=1);

namespace ApplicationTest\InputFilter;

use Application\InputFilter\TaskInputFilter;
use PHPUnit\Framework\TestCase;

class TaskInputFilterTest extends TestCase
{
    private function filter(): TaskInputFilter
    {
        return new TaskInputFilter();
    }

    // ---- 正常系 ----

    public function testValidTitlePassesAndIsTrimmed(): void
    {
        $filter = $this->filter();
        $filter->setData(['title' => '  買い物に行く  ']);

        $this->assertTrue($filter->isValid());
        $this->assertSame('買い物に行く', $filter->getValues()['title']);
    }

    // ---- 準正常系・異常系 ----

    public function testEmptyTitleIsInvalid(): void
    {
        $filter = $this->filter();
        $filter->setData(['title' => '']);

        $this->assertFalse($filter->isValid());
        $this->assertArrayHasKey('title', $filter->getMessages());
    }

    public function testWhitespaceOnlyTitleIsInvalid(): void
    {
        $filter = $this->filter();
        $filter->setData(['title' => '   ']);

        $this->assertFalse($filter->isValid());
    }

    public function testMissingTitleIsInvalid(): void
    {
        $filter = $this->filter();
        $filter->setData([]);

        $this->assertFalse($filter->isValid());
    }

    public function testTooLongTitleIsInvalid(): void
    {
        $filter = $this->filter();
        $filter->setData(['title' => str_repeat('あ', 256)]);

        $this->assertFalse($filter->isValid());
    }

    // ---- 日付（任意） ----

    public function testValidDatesPass(): void
    {
        $filter = $this->filter();
        $filter->setData(['title' => 'x', 'start_date' => '2026-06-10', 'end_date' => '2026-06-20']);

        $this->assertTrue($filter->isValid());
    }

    public function testEmptyDatesAreAllowed(): void
    {
        $filter = $this->filter();
        $filter->setData(['title' => 'x', 'start_date' => '', 'end_date' => '']);

        $this->assertTrue($filter->isValid());
    }

    public function testInvalidDateFormatIsInvalid(): void
    {
        $filter = $this->filter();
        $filter->setData(['title' => 'x', 'start_date' => '2026/06/10']);

        $this->assertFalse($filter->isValid());
        $this->assertArrayHasKey('start_date', $filter->getMessages());
    }

    public function testEndDateBeforeStartDateIsInvalid(): void
    {
        $filter = $this->filter();
        $filter->setData(['title' => 'x', 'start_date' => '2026-06-20', 'end_date' => '2026-06-10']);

        $this->assertFalse($filter->isValid());
        $this->assertArrayHasKey('end_date', $filter->getMessages());
    }

    public function testEndDateWithoutStartDatePasses(): void
    {
        $filter = $this->filter();
        $filter->setData(['title' => 'x', 'end_date' => '2026-06-10']);

        $this->assertTrue($filter->isValid());
    }
}
