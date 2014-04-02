<?php

namespace Bangpound\Silex;

class SwiftMailSystem extends \Bangpound\Bridge\Drupal\SwiftMailSystem
{
    public function __construct()
    {
        parent::__construct($GLOBALS['app']['mailer']);
    }
}
