<?php

declare(strict_types=1);

namespace ApplicationTest\Model;

use Application\Model\Task;
use PHPUnit\Framework\TestCase;

class TaskTest extends TestCase
{
    // ---- 正常系 ----

    public function testExchangeArrayPopulatesAllFields(): void
    {
        $task = new Task();
        $task->exchangeArray(['id' => 5, 'title' => '買い物', 'done' => 1]);

        $this->assertSame(5, $task->id);
        $this->assertSame('買い物', $task->title);
        $this->assertTrue($task->done);
    }

    public function testGetArrayCopyReturnsNormalizedShape(): void
    {
        $task = new Task();
        $task->id = 3;
        $task->title = '掃除';
        $task->done = true;

        $this->assertSame(
            ['id' => 3, 'title' => '掃除', 'done' => 1],
            $task->getArrayCopy()
        );
    }

    // ---- 準正常系・異常系 ----

    public function testExchangeArrayWithEmptyArrayUsesDefaults(): void
    {
        $task = new Task();
        $task->exchangeArray([]);

        $this->assertNull($task->id);
        $this->assertSame('', $task->title);
        $this->assertFalse($task->done);
    }

    public function testDoneStringZeroIsCoercedToFalse(): void
    {
        $task = new Task();
        $task->exchangeArray(['title' => 'x', 'done' => '0']);

        $this->assertFalse($task->done);
    }

    public function testNumericStringIdIsCoercedToInt(): void
    {
        $task = new Task();
        $task->exchangeArray(['id' => '42', 'title' => 'x']);

        $this->assertSame(42, $task->id);
    }

    public function testGetArrayCopyEncodesDoneFalseAsZero(): void
    {
        $task = new Task();
        $task->title = 'pending';

        $this->assertSame(0, $task->getArrayCopy()['done']);
    }
}
