<?php

declare(strict_types=1);

namespace Application\InputFilter;

use Laminas\InputFilter\InputFilterInterface;

/**
 * InputFilter のメッセージ（field => [messages]）を表示用の文字列配列へ平坦化する。
 */
class ErrorFormatter
{
    /** @return list<string> */
    public static function flatten(InputFilterInterface $filter): array
    {
        $errors = [];
        foreach ($filter->getMessages() as $messages) {
            foreach ($messages as $message) {
                $errors[] = (string) $message;
            }
        }

        return $errors;
    }
}
