<?php

namespace semsty\ws;

use semsty\ws\commands\BaseCommand;
use semsty\ws\commands\Broadcast;
use semsty\ws\events\ConnectionCommandEvent;
use semsty\ws\events\ConnectionErrorEvent;
use semsty\ws\events\ConnectionEvent;
use semsty\ws\events\ConnectionMessageEvent;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;
use yii\base\BootstrapInterface;
use yii\base\Component;
use yii\base\ErrorException;
use yii\base\InvalidConfigException;
use yii\console\Application as ConsoleApp;
use yii\helpers\ArrayHelper;
use yii\helpers\BaseInflector;
use yii\helpers\Inflector;

/**
 * Class Server
 * @property $url
 * @property $context
 * @package semsty\ws
 */
class Server extends Component implements BootstrapInterface
{
    const EVENT_SOCKET_OPEN = 'socket_open';
    const EVENT_SOCKET_CLOSE = 'socket_close';
    const EVENT_SOCKET_OPEN_ERROR = 'ws_open_error';

    const EVENT_CLIENT_CONNECTED = 'client_connected';
    const EVENT_CLIENT_DISCONNECTED = 'client_disconnected';
    const EVENT_CLIENT_ERROR = 'client_error';
    const EVENT_CLIENT_MESSAGE = 'client_message';
    const EVENT_CLIENT_RUN_COMMAND = 'client_run_command';
    const EVENT_CLIENT_END_COMMAND = 'client_end_command';

    const EVENT_BIND_MESSAGE = 'bind_message';
    const EVENT_EXCHANGE_MESSAGE = 'exchange_message';
    const EVENT_EXCHANGE_RECIEVED_MESSAGE = 'exchange_message';

    public $schema = 'websocket';
    public $host = '0.0.0.0';
    public $port = 8000;
    public $processes = 1;

    public $exchangePort = 10000;

    public $ssl = false;
    public $cert;
    public $pem;
    public $verify_peer = false;

    public $commandClass = Command::class;
    public $commandOptions = [];

    /**
     * @var $socket Worker
     */
    public $socket;

    /**
     * @var $exchange Worker
     */
    public $exchange;

    /**
     * @var $bind AsyncTcpConnection
     */
    protected $bind;
    protected $closeOnError = false;
    protected $runCommands = true;
    protected $connections = [];

    public function init()
    {
        parent::init();
        if ($this->ssl && (empty($this->cert) || empty($this->pem))) {
            throw new ErrorException('With ssl option cert and pem required');
        }
        $this->configure();
    }

    protected function getCommandId()
    {
        foreach (\Yii::$app->getComponents(false) as $id => $component) {
            if ($component === $this) {
                return Inflector::camel2id($id);
            }
        }
        throw new InvalidConfigException('Queue must be an application component.');
    }

    public function bootstrap($app)
    {
        if ($app instanceof ConsoleApp) {
            $app->controllerMap[$this->getCommandId()] = [
                    'class' => $this->commandClass,
                    'server' => $this,
                ] + $this->commandOptions;
        }
    }

    public function configure()
    {
        $this->socket = $this->createWorker();
        $this->socket->onWorkerStart = function () {
            if ($this->exchangePort) {
                $this->bind = new AsyncTcpConnection("tcp://0.0.0.0:$this->exchangePort");
                $this->bind->onMessage = function ($connection, $message) {
                    $this->trigger(
                        static::EVENT_BIND_MESSAGE,
                        new ConnectionMessageEvent(['connection' => $connection, 'message' => $message])
                    );
                    $this->handleBindMessage($connection, $message);
                };
                $this->bind->connect();
            }
            $this->trigger(static::EVENT_SOCKET_OPEN);
            try {
                $this->handleStart();
            } catch (\Exception $e) {
                $this->trigger(static::EVENT_SOCKET_OPEN_ERROR);
            }
        };
        $this->socket->onWorkerStop = function () {
            $this->trigger(static::EVENT_SOCKET_CLOSE);
            $this->handleStop();
        };
        $this->socket->onConnect = function ($connection) {
            $this->trigger(static::EVENT_CLIENT_CONNECTED, new ConnectionEvent(['connection' => $connection]));
            $this->handleConnect($connection);
        };
        $this->socket->onClose = function ($connection) {
            $this->trigger(static::EVENT_CLIENT_DISCONNECTED, new ConnectionEvent(['connection' => $connection]));
            $this->handleClose($connection);
        };
        $this->socket->onMessage = function ($connection, $message) {
            $this->trigger(
                static::EVENT_CLIENT_MESSAGE,
                new ConnectionMessageEvent(['connection' => $connection, 'message' => $message])
            );
            $this->handleMessage($connection, $message);
        };
        $this->socket->onError = function ($connection, $e) {
            $this->handleError($connection);
            $this->trigger(
                static::EVENT_CLIENT_ERROR,
                new ConnectionErrorEvent(['connection' => $connection, 'exception' => $e])
            );
        };
        $this->socket->onBufferDrain = function ($connection) {
            $this->handleBufferDrain($connection);
        };
        $this->socket->onBufferFull = function ($connection) {
            $this->handleBufferFull($connection);
        };
        if ($this->exchangePort) {
            $this->exchange = new Worker("tcp://0.0.0.0:$this->exchangePort");
            $this->exchange->onMessage = function ($connection, $message) {
                $this->trigger(
                    static::EVENT_EXCHANGE_MESSAGE,
                    new ConnectionMessageEvent(['connection' => $connection, 'message' => $message])
                );
                $this->handleExchangeMessage($connection, $message);
            };
            $this->exchange->onConnect = function ($connection) {
                $this->trigger(static::EVENT_CLIENT_CONNECTED, new ConnectionEvent(['connection' => $connection]));
                $this->handleConnect($connection);
            };
            $this->exchange->onClose = function ($connection) {
                $this->trigger(static::EVENT_CLIENT_DISCONNECTED, new ConnectionEvent(['connection' => $connection]));
                $this->handleClose($connection);
            };
        }
    }

    protected function createWorker()
    {
        $instance = new Worker($this->url, $this->context);
        $instance->count = $this->processes;
        if ($this->ssl) {
            $instance->transport = 'ssl';
        }
        return $instance;
    }

    /**
     * @param $connection TcpConnection
     */
    public function handleConnect(&$connection)
    {
        \Yii::info('connect: ' . $connection->id, 'ws');
        $this->connections[$connection->id] = $connection;
    }

    /**
     * @param $connection TcpConnection
     */
    public function handleClose(&$connection)
    {
        \Yii::info('close: ' . $connection->id, 'ws');
        if (isset($this->connections[$connection->id])) {
            unset($this->connections[$connection->id]);
        }
    }

    /**
     * @param $connection TcpConnection
     * @param $message
     */
    public function handleBindMessage(&$connection, $message)
    {
        \Yii::info('bind_message: ' . $connection->id . ' ' . $message, 'ws');
        $this->handleMessage($connection, $message);
    }

    /**
     * @param $connection TcpConnection
     * @param $message
     */
    public function handleExchangeMessage(&$connection, $message)
    {
        \Yii::info('exchange_message: ' . $connection->id . ' ' . $message, 'ws');
        if ($json = json_decode($message, true)) {
            $message = $json;
        }
        if (is_array($message)) {
            $message['exchange_connection_id'] = $connection->id;
            $message = json_encode($message);
        }
        foreach ($this->exchange->connections as $id => $bind) {
            if ($connection->id != $id) {
                $bind->send($message);
            }
        }
    }

    /**
     * @param $connection TcpConnection
     * @param $message
     */
    public function handleExchangeRecievedMessage(&$connection, $message)
    {

    }

    /**
     * @param $connection TcpConnection
     * @param $message
     * @throws
     */
    public function handleMessage(&$connection, $message)
    {
        \Yii::info('message: ' . $connection->id . ' ' . $message, 'ws');
        if ($json = json_decode($message, true)) {
            $message = $json;
        }
        if ($this->runCommands) {
            $command = $this->getCommand($connection, $message);
            if ($command) {
                $this->trigger(self::EVENT_CLIENT_RUN_COMMAND, new ConnectionCommandEvent([
                    'connection' => $connection,
                    'message' => $message,
                    'command' => $command
                ]));
                if ($command instanceof BaseCommand) {
                    $result = $command->process();
                } elseif (method_exists($this, 'command' . ucfirst($command))) {
                    $result = call_user_func([$this, 'command' . ucfirst($command)], $connection, $message);
                }
                $this->trigger(self::EVENT_CLIENT_END_COMMAND, new ConnectionCommandEvent([
                    'connection' => $connection,
                    'message' => $message,
                    'command' => $command,
                    'result' => $result
                ]));
            }
        }
    }

    /**
     * @param $connection TcpConnection
     * @param $message
     * @throws
     * @return string
     */
    protected function getCommand(&$connection, $message)
    {
        if (is_array($message)) {
            $name = ArrayHelper::getValue($message, 'cmd');
            unset($message['cmd']);
        } else {
            $name = $message;
        }
        if (ArrayHelper::isIn($name, static::getAvailableCommands())) {
            return BaseInflector::camelize($name);
        } elseif ($class = ArrayHelper::getValue(static::getAvailableCommands(), $name)) {
            return new $class([
                'connection' => $connection,
                'message' => $message,
                'server' => $this
            ]);
        } else {
            throw new ErrorException("$name not supported");
        }
    }

    public static function getAvailableCommands()
    {
        return [
            Broadcast::NAME => Broadcast::class
        ];
    }

    public function handleStart()
    {
        if ($this->exchangePort) {
            $this->bind = new AsyncTcpConnection("tcp://0.0.0.0:$this->exchangePort");
            $this->bind->onMessage = function ($connection, $message) {
                $this->trigger(
                    static::EVENT_EXCHANGE_RECIEVED_MESSAGE,
                    new ConnectionMessageEvent(['connection' => $connection, 'message' => $message])
                );
                $this->handleExchangeRecievedMessage($connection, $message);
            };
            $this->bind->connect();
        }
    }

    public function handleStop()
    {

    }

    /**
     * @param $connection TcpConnection
     */
    public function handleError(&$connection)
    {
        if ($this->closeOnError) {
            $connection->close();
        }
    }

    public function handleBufferDrain($connection)
    {

    }

    public function handleBufferFull($connection)
    {

    }

    public function getUrl()
    {
        return "$this->schema://$this->host:$this->port";
    }

    public function getContext()
    {
        $context = [];
        if ($this->ssl) {
            $context['local_cert'] = $this->cert;
            $context['local_pk'] = $this->pem;
            $context['verify_peer'] = $this->verify_peer;
        }
        return $context;
    }
}