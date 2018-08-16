<?php

namespace tests\ws;

use semsty\ws\Client;
use tests\TestCase;

class ServerTest extends TestCase
{
    /**
     * @var $socket Client
     */
    protected $socket;

    public function setUp()
    {
        parent::setUp();
        $this->socket = new Client([
            'schema' => 'tcp',
            'host' => 'localhost',
            'port' => '10000'
        ]);
    }

    public function testBroadcast()
    {
        $response = $this->socket->send(['cmd' => 'broadcast']);
    }

    public function testCommand()
    {
        $this->socket->send(['cmd' => 'test', 'message' => 'test']);
        $content = file_get_contents('/tmp/socket');
        $this->assertEquals($content, "test\r\n");
    }
}
