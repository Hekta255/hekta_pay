<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';

use HektaPay\Database\Connection;
use HektaPay\GooglePlay\GooglePlayException;
use HektaPay\Payment\PaymentOrchestrator;
use HektaPay\Services\WebhookDispatcher;

Connection::loadEnvironment();

try {
    $db = Connection::create();
    $service = (new PaymentOrchestrator($db, new WebhookDispatcher($db)))->googlePlay();
    $endMillis = (int) round(microtime(true) * 1000);
    // Google only lists voids from the last 30 days; overlap one day with the previous run to cover clock skew.
    $floor = $endMillis - 29 * 86400 * 1000;
    $last = $service->lastReconcileEndMillis();
    $startMillis = $last !== null ? max($floor, $last - 86400 * 1000) : $floor;
    fwrite(STDOUT, json_encode($service->reconcile($startMillis, $endMillis), JSON_THROW_ON_ERROR) . PHP_EOL);
} catch (GooglePlayException $error) {
    fwrite(STDERR, $error->errorCode() . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
