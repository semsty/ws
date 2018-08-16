<?php

namespace semsty\ws\events;

use yii\base\Event;

class ExceptionEvent extends Event
{
    public $exception;
}