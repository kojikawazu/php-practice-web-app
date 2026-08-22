<?php

declare(strict_types=1);

namespace Application;

use Laminas\Authentication\AuthenticationService;
use Laminas\Router\Http\Literal;
use Laminas\Router\Http\Segment;
use Laminas\ServiceManager\Factory\InvokableFactory;

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
            // 認証サービス（既定の Session ストレージで identity を保持）
            AuthenticationService::class => function () {
                return new AuthenticationService();
            },
        ],
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
