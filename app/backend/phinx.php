<?php
$keys = require __DIR__ . '/config/keys.php';

return [
    'paths' => [
        'migrations' => __DIR__ . '/db/migrations',
        'seeds' => __DIR__ . '/db/seeds'
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'development',
        'development' => [
            'adapter' => 'mysql',
            'host' => $keys['db']['host'],
            'name' => $keys['db']['name'],
            'user' => $keys['db']['username'],
            'pass' => $keys['db']['password'],
            'port' => '3306',
            'charset' => 'utf8mb4',
        ]
    ]
];