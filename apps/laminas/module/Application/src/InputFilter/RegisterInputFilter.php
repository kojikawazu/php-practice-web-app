<?php

declare(strict_types=1);

namespace Application\InputFilter;

use Laminas\Filter\StringTrim;
use Laminas\InputFilter\InputFilter;
use Laminas\Validator\NotEmpty;
use Laminas\Validator\StringLength;

/**
 * 新規登録入力の検証（ユーザー名・パスワード）。
 */
class RegisterInputFilter extends InputFilter
{
    public function __construct()
    {
        $this->add([
            'name'     => 'username',
            'required' => true,
            'filters'  => [
                ['name' => StringTrim::class],
            ],
            'validators' => [
                [
                    'name'    => NotEmpty::class,
                    'options' => ['messages' => [NotEmpty::IS_EMPTY => 'ユーザー名は必須です。']],
                ],
                [
                    'name'    => StringLength::class,
                    'options' => [
                        'max'      => 255,
                        'messages' => [StringLength::TOO_LONG => 'ユーザー名は255文字以内にしてください。'],
                    ],
                ],
            ],
        ]);

        $this->add([
            'name'     => 'password',
            'required' => true,
            'filters'  => [], // パスワードは trim しない
            'validators' => [
                [
                    'name'    => StringLength::class,
                    'options' => [
                        'min'      => 8,
                        'messages' => [StringLength::TOO_SHORT => 'パスワードは8文字以上にしてください。'],
                    ],
                ],
            ],
        ]);
    }
}
