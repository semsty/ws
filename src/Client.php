<?php

namespace semsty\ws;

use yii\base\Component;
use yii\helpers\Json;

/**
 * Class Client
 * @property $url
 * @package semsty\ws
 */
class Client extends Component
{
    public $schema;
    public $host;
    public $port;

    public function send($message)
    {
        if (is_array($message)) {
            $message = Json::encode($message);
        }
        $conn = stream_socket_client($this->url);
        fwrite($conn, $message);
    }

    public function getUrl()
    {
        return $this->schema . '://' . $this->host . ':' . $this->port;
    }
}