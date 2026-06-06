<?php

declare(strict_types=1);

namespace Application\InputFilter;

use Laminas\Filter\StringTrim;
use Laminas\InputFilter\InputFilter;
use Laminas\Validator\NotEmpty;

/**
 * ログイン入力の検証（ユーザー名・パスワードの存在のみ）。
 */
class LoginInputFilter extends InputFilter
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
                    'options' => ['messages' => [NotEmpty::IS_EMPTY => 'ユーザー名を入力してください。']],
                ],
            ],
        ]);

        $this->add([
            'name'     => 'password',
            'required' => true,
            'validators' => [
                [
                    'name'    => NotEmpty::class,
                    'options' => ['messages' => [NotEmpty::IS_EMPTY => 'パスワードを入力してください。']],
                ],
            ],
        ]);
    }
}
