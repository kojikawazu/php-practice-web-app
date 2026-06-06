<?php

declare(strict_types=1);

namespace Application\InputFilter;

use Laminas\Filter\StringTrim;
use Laminas\InputFilter\InputFilter;
use Laminas\Validator\NotEmpty;
use Laminas\Validator\StringLength;

/**
 * タスク（タイトル）入力の検証。
 */
class TaskInputFilter extends InputFilter
{
    public function __construct()
    {
        $this->add([
            'name'     => 'title',
            'required' => true,
            'filters'  => [
                ['name' => StringTrim::class],
            ],
            'validators' => [
                [
                    'name'    => NotEmpty::class,
                    'options' => ['messages' => [NotEmpty::IS_EMPTY => 'タイトルは必須です。']],
                ],
                [
                    'name'    => StringLength::class,
                    'options' => [
                        'max'      => 255,
                        'messages' => [StringLength::TOO_LONG => 'タイトルは255文字以内にしてください。'],
                    ],
                ],
            ],
        ]);
    }
}
