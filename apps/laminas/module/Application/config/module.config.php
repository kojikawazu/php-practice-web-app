<?php

declare(strict_types=1);

namespace Application;

use Laminas\Authentication\AuthenticationService;
use Laminas\Router\Http\Literal;
use Laminas\Router\Http\Segment;
use Laminas\ServiceManager\Factory\InvokableFactory;
use Laminas\Session\Storage\SessionArrayStorage;

return [
    'router' => [
        'routes' => [
            'home' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/',
                    'defaults' => [
                        'controller' => Controller\IndexController::class,
                        'action'     => 'index',
                    ],
                ],
            ],
            'application' => [
                'type'    => Segment::class,
                'options' => [
                    'route'    => '/application[/:action]',
                    'defaults' => [
                        'controller' => Controller\IndexController::class,
                        'action'     => 'index',
                    ],
                ],
            ],
            'login' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/login',
                    'defaults' => [
                        'controller' => Controller\AuthController::class,
                        'action'     => 'login',
                    ],
                ],
            ],
            'register' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/register',
                    'defaults' => [
                        'controller' => Controller\AuthController::class,
                        'action'     => 'register',
                    ],
                ],
            ],
            'logout' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/logout',
                    'defaults' => [
                        'controller' => Controller\AuthController::class,
                        'action'     => 'logout',
                    ],
                ],
            ],
            'tasks' => [
                'type'    => Segment::class,
                'options' => [
                    'route'    => '/tasks[/:action[/:id]]',
                    'constraints' => [
                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                        'id'     => '[0-9]+',
                    ],
                    'defaults' => [
                        'controller' => Controller\TaskController::class,
                        'action'     => 'index',
                    ],
                ],
            ],
        ],
    ],
    'service_manager' => [
        // DB アダプタは有効化済みモジュール Laminas\Db が config['db'] から自動提供する。
        'factories' => [
            Model\TaskTable::class       => Model\TaskTableFactory::class,
            Model\UserTable::class       => Model\UserTableFactory::class,
            Service\PasswordHasher::class => InvokableFactory::class,
            // CSP の nonce はリクエスト内で 1 つ。共有インスタンスにすることで
            // ヘッダーと PHTML の nonce が必ず一致する。
            Service\ContentSecurityPolicy::class => InvokableFactory::class,
            // CSRF トークンはセッションに紐づくため、発行側と検証側で同一インスタンスを使う
            Service\CsrfGuard::class => Service\CsrfGuardFactory::class,
            // 認証成功・ログアウト時のセッション操作（セッション固定攻撃対策）
            Service\AuthSessionInterface::class => Service\AuthSessionFactory::class,
            // 認証サービス（identity は共有 SessionManager 上の Session ストレージへ）
            AuthenticationService::class => Service\AuthenticationServiceFactory::class,
        ],
    ],
    // セッションの方針（docs/06「セッション管理」）。SessionManager をコンテナから
    // 解決したときに SessionConfigFactory がこのキーを読む（キーが無いと例外になる）。
    'session_config' => [
        // 未知のセッション ID を採用しない。再生成が「仕込まれた後」の対策なのに対し、
        // これは「そもそも ID を仕込ませない」対策で、両方でセッション固定攻撃を塞ぐ。
        'use_strict_mode' => true,
        // Cookie を JavaScript から読めなくする（XSS でセッションを持ち出させない）
        'cookie_httponly' => true,
        // 外部サイト起点のリクエストに Cookie を載せない（CSRF の多層防御）
        'cookie_samesite' => 'Lax',
        // cookie_secure はローカルが HTTP のため設定しない。本番（HTTPS）では
        // 有効化が必須（docs/06「既知の注意点」）。
    ],
    // SessionManagerFactory は session_storage も必須で要求する（欠けると例外）。
    // SessionArrayStorage は SessionManager が既定で使うものと同じで、$_SESSION を直接扱う。
    'session_storage' => [
        'type' => SessionArrayStorage::class,
    ],
    'controllers' => [
        'factories' => [
            Controller\IndexController::class => InvokableFactory::class,
            Controller\TaskController::class  => Controller\TaskControllerFactory::class,
            Controller\AuthController::class  => Controller\AuthControllerFactory::class,
        ],
    ],
    'view_helpers' => [
        'factories' => [
            View\Helper\CspNonce::class => View\Helper\CspNonceFactory::class,
            View\Helper\CsrfInput::class => View\Helper\CsrfInputFactory::class,
        ],
        'aliases' => [
            'cspNonce' => View\Helper\CspNonce::class,
            'csrfInput' => View\Helper\CsrfInput::class,
        ],
    ],
    'view_manager' => [
        'display_not_found_reason' => true,
        'display_exceptions'       => true,
        'doctype'                  => 'HTML5',
        'not_found_template'       => 'error/404',
        'exception_template'       => 'error/index',
        'template_map' => [
            'layout/layout'              => __DIR__ . '/../view/layout/layout.phtml',
            'application/index/index'    => __DIR__ . '/../view/application/index/index.phtml',
            'application/task/index'     => __DIR__ . '/../view/application/task/index.phtml',
            'application/task/edit'      => __DIR__ . '/../view/application/task/edit.phtml',
            'application/auth/login'     => __DIR__ . '/../view/application/auth/login.phtml',
            'application/auth/register'  => __DIR__ . '/../view/application/auth/register.phtml',
            'error/404'                  => __DIR__ . '/../view/error/404.phtml',
            'error/index'                => __DIR__ . '/../view/error/index.phtml',
        ],
        'template_path_stack' => [
            __DIR__ . '/../view',
        ],
    ],
];
