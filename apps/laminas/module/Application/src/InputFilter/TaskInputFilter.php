<?php

declare(strict_types=1);

namespace Application\InputFilter;

use Laminas\Filter\StringTrim;
use Laminas\InputFilter\InputFilter;
use Laminas\Validator\Callback;
use Laminas\Validator\Date;
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

        // 開始日（任意・Y-m-d）
        $this->add([
            'name'        => 'start_date',
            'required'    => false,
            'allow_empty' => true,
            'validators'  => [
                [
                    'name'    => Date::class,
                    'options' => [
                        'format'   => 'Y-m-d',
                        'messages' => [
                            Date::INVALID      => '開始日の形式が正しくありません。',
                            Date::INVALID_DATE => '開始日の形式が正しくありません。',
                            Date::FALSEFORMAT  => '開始日の形式が正しくありません。',
                        ],
                    ],
                ],
            ],
        ]);

        // 終了日（任意・Y-m-d・開始日以降）
        $this->add([
            'name'        => 'end_date',
            'required'    => false,
            'allow_empty' => true,
            'validators'  => [
                [
                    'name'    => Date::class,
                    'options' => [
                        'format'   => 'Y-m-d',
                        'messages' => [
                            Date::INVALID      => '終了日の形式が正しくありません。',
                            Date::INVALID_DATE => '終了日の形式が正しくありません。',
                            Date::FALSEFORMAT  => '終了日の形式が正しくありません。',
                        ],
                    ],
                ],
                [
                    'name'    => Callback::class,
                    'options' => [
                        'messages' => [Callback::INVALID_VALUE => '終了日は開始日以降にしてください。'],
                        'callback' => static function ($value, $context = []) {
                            $start = $context['start_date'] ?? '';
                            if ($value === '' || $value === null || $start === '' || $start === null) {
                                return true;
                            }

                            // Y-m-d は辞書順比較で日付の前後と一致する
                            return $value >= $start;
                        },
                    ],
                ],
            ],
        ]);
    }
}
