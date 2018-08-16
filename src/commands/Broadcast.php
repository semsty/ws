<?php

namespace semsty\ws\commands;

use yii\helpers\Json;

class Broadcast extends BaseCommand
{
    const NAME = 'broadcast';

    public function process()
    {
        \Yii::info('broadcast: ' . $this->connection->id . ' ' . json_encode($this->message), 'ws');
        foreach ($this->server->socket->connections as $conn) {
            if ($this->connection->id != $conn->id) {
                $conn->send(Json::encode($this->message));
            }
        }
        parent::process();
    }
}