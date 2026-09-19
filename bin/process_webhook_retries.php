<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';

use HektaPay\Database\Connection;
use HektaPay\Services\WebhookDispatcher;

Connection::loadEnvironment();
$limit = isset($argv[1]) ? (int) $argv[1] : 50;
$result = (new WebhookDispatcher(Connection::create()))->processRetries($limit);
fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL);
