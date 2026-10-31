<?php

declare(strict_types=1);

$autoload = getenv('METADATA_TEST_AUTOLOAD') ?: dirname(__DIR__) . '/vendor/autoload.php';
defined('YII_ENABLE_ERROR_HANDLER') or define('YII_ENABLE_ERROR_HANDLER', false);
defined('YII_DEBUG') or define('YII_DEBUG', true);
require $autoload;
require dirname($autoload) . '/yiisoft/yii2/Yii.php';

$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/index.php';

new yii\web\Application([
    'id' => 'metadata-tests',
    'basePath' => __DIR__,
    'aliases' => ['@rapttor/yii2metadata' => dirname(__DIR__) . '/src'],
    'components' => [
        'db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'request' => ['cookieValidationKey' => 'x', 'scriptUrl' => '/index.php', 'baseUrl' => ''],
    ],
]);
