<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';

use HektaPay\Database\Connection;
use HektaPay\GooglePlay\GooglePlayException;
use HektaPay\Payment\PaymentOrchestrator;
use HektaPay\Services\WebhookDispatcher;

Connection::loadEnvironment();
$limit = isset($argv[1]) ? (int) $argv[1] : 50;

try {
    $db = Connection::create();
    $orchestrator = new PaymentOrchestrator($db, new WebhookDispatcher($db));
    fwrite(STDOUT, json_encode($orchestrator->googlePlay()->sweep($limit), JSON_THROW_ON_ERROR) . PHP_EOL);
} catch (GooglePlayException $error) {
    fwrite(STDERR, $error->errorCode() . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
