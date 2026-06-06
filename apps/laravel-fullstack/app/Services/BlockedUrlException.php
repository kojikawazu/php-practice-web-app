<?php

namespace App\Services;

use RuntimeException;

/** 取得を禁止すべき URL（不正スキーム・内部/プライベートアドレス等）に対して投げる。 */
class BlockedUrlException extends RuntimeException
{
}
