<?php

namespace semsty\ws\commands;

use semsty\ws\Server;
use Workerman\Connection\TcpConnection;
use yii\base\BaseObject;

class BaseCommand extends BaseObject
{
    const NAME = 'command';

    /**
     * @var $connection TcpConnection
     */
    public $connection;

    /**
     * @var $server Server
     */
    public $server;

    public $message;

    public function process()
    {

    }
}