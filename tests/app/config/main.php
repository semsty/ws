<?php
$config = [
    'id' => 'ws-app',
    'basePath' => dirname(__DIR__),
    'vendorPath' => dirname(dirname(__DIR__)) . '/vendor',
    'bootstrap' => [
        'socket-server', 'socket-client'
    ],
    'components' => [
        'socket-server' => [
            'class' => \tests\app\TestServer::class,
            'host' => '0.0.0.0',
            'port' => '8000'
        ],
        'socket-client' => [
            'class' => \semsty\ws\Client::class,
            'schema' => 'tcp',
            'host' => 'localhost',
            'port' => '10000'
        ],
    ],
];

return $config;
