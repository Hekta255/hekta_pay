<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$suites = glob(__DIR__ . '/unit/*Test.php') ?: [];
if (!in_array('--unit-only', $argv, true)) {
    $suites = array_merge($suites, glob(__DIR__ . '/integration/*Test.php') ?: []);
}
foreach ($suites as $suite) {
    echo 'Loading ' . basename($suite) . "\n";
    require $suite;
}
exit(T::run());
