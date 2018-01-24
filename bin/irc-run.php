#!/usr/bin/env php
<?php

/*
 * This file is part of the OpenStates Framework (osf) package.
 * (c) @author Guillaume Ponçon <guillaume.poncon@openstates.com>
 * For the full copyright and license information, please read the LICENSE file distributed with the project.
 */

require __DIR__ . '/../vendor/autoload.php';

use Osf\Irc\Server;

try {
    (new Server())->start();
} catch (Exception $e) {
    print_r($e);
}
