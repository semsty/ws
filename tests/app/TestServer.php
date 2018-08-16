<?php

namespace tests\app;

use semsty\ws\Server;
use Workerman\Connection\TcpConnection;
use yii\helpers\ArrayHelper;

class TestServer extends Server
{
    public static function getAvailableCommands()
    {
        return ArrayHelper::merge(parent::getAvailableCommands(), [
            'test'
        ]);
    }

    /**
     * @param $connection TcpConnection
     * @param $message
     */
    public function commandTest($connection, $message)
    {
        file_put_contents('/tmp/socket', $message['message'] . "\r\n");
        $connection->send('ok');
        $connection = ArrayHelper::getValue($this->connections, $message['exchange_connection_id']);
        if ($connection) {
            $connection->send('ex_ok');
            $connection->close();
        }
    }
}