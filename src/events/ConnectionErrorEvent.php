<?php

namespace semsty\ws\events;

class ConnectionErrorEvent extends ConnectionEvent
{
    public $exception;
}