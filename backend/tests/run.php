<?php
require_once __DIR__ . '/BaseTestCase.php';
require_once __DIR__ . '/BalanceShortageRetryTest.php';
require_once __DIR__ . '/FreezeOrderTest.php';
require_once __DIR__ . '/StatusLoopTest.php';
TestRunner::run([
    'BalanceShortageRetryTest',
    'FreezeOrderTest',
    'StatusLoopTest',
]);
