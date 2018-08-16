<?php

namespace semsty\ws\events;

class ConnectionCommandEvent extends ConnectionMessageEvent
{
    public $command;
    public $result;
}