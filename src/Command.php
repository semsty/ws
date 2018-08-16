<?php

namespace semsty\ws;

use Workerman\Worker;
use yii\console\Controller as ConsoleController;

class Command extends ConsoleController
{
    public $server;

    public function actionCommand()
    {
        global $argv;
        if (strpos($argv[0], 'yii') !== false) {
            $argv = array_slice($argv, 1);
        }
        if (isset($argv[2]) && $argv[2] == 'daemon') {
            $argv[2] = '-d';
        }
        Worker::runAll();
    }
}