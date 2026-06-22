<?php

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../models/BaseModel.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Wallet.php';
require_once __DIR__ . '/../models/WalletTransaction.php';
require_once __DIR__ . '/../models/RechargeRecord.php';
require_once __DIR__ . '/../models/Order.php';
require_once __DIR__ . '/../services/WalletService.php';
require_once __DIR__ . '/../services/OrderService.php';

class TestHelper {
    public static function createTestPdo() {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        self::initTables($pdo);
        self::seedData($pdo);
        return $pdo;
    }

    private static function initTables($pdo) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username VARCHAR(50) NOT NULL,
            real_name VARCHAR(50),
            balance DECIMAL(12,2) DEFAULT 0.00,
            status TINYINT DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS wallets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            balance DECIMAL(12,2) DEFAULT 0.00,
            frozen_amount DECIMAL(12,2) DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS wallet_transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            order_id INTEGER,
            type VARCHAR(20) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            balance_before DECIMAL(12,2) DEFAULT 0.00,
            balance_after DECIMAL(12,2) DEFAULT 0.00,
            description VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS recharge_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            order_id INTEGER,
            amount DECIMAL(12,2) NOT NULL,
            channel VARCHAR(50) DEFAULT 'manual',
            status VARCHAR(20) DEFAULT 'pending',
            transaction_id VARCHAR(64),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_no VARCHAR(32) NOT NULL,
            user_id INTEGER NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            status VARCHAR(20) DEFAULT 'pending',
            frozen_reason VARCHAR(255),
            paid_at TIMESTAMP NULL,
            completed_at TIMESTAMP NULL,
            cancelled_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS withdrawal_rules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            rule_name VARCHAR(100) NOT NULL,
            min_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            max_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            daily_limit DECIMAL(10,2) DEFAULT 0.00,
            fee_rate DECIMAL(5,4) DEFAULT 0.0000,
            fee_min DECIMAL(10,2) DEFAULT 0.00,
            fee_max DECIMAL(10,2) DEFAULT 0.00,
            status TINYINT DEFAULT 1,
            description VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS withdrawal_applications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            fee DECIMAL(10,2) DEFAULT 0.00,
            actual_amount DECIMAL(12,2) DEFAULT 0.00,
            bank_name VARCHAR(100),
            bank_account VARCHAR(50),
            account_name VARCHAR(50),
            rule_id INTEGER,
            status VARCHAR(20) DEFAULT 'pending',
            review_remark VARCHAR(255),
            reviewer_id INTEGER,
            reviewed_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS withdrawal_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            application_id INTEGER NOT NULL,
            transaction_no VARCHAR(64) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            status VARCHAR(20) DEFAULT 'processing',
            arrived_at TIMESTAMP NULL,
            fail_reason VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS review_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            application_id INTEGER NOT NULL,
            reviewer_id INTEGER NOT NULL,
            action VARCHAR(20) NOT NULL,
            remark VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
    }

    private static function seedData($pdo) {
        $now = date('Y-m-d H:i:s');

        $pdo->exec("INSERT INTO users (id, username, real_name, balance, status, created_at, updated_at) VALUES
            (1, 'demo', '演示用户', 100.00, 1, '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO users (id, username, real_name, balance, status, created_at, updated_at) VALUES
            (2, 'testuser', '测试用户', 0.00, 1, '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO users (id, username, real_name, balance, status, created_at, updated_at) VALUES
            (3, 'poormer', '穷用户', 0.00, 1, '{$now}', '{$now}')");

        $pdo->exec("INSERT INTO wallets (id, user_id, balance, frozen_amount, created_at, updated_at) VALUES
            (1, 1, 100.00, 0.00, '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO wallets (id, user_id, balance, frozen_amount, created_at, updated_at) VALUES
            (2, 2, 0.00, 0.00, '{$now}', '{$now}')");
        $pdo->exec("INSERT INTO wallets (id, user_id, balance, frozen_amount, created_at, updated_at) VALUES
            (3, 3, 0.00, 0.00, '{$now}', '{$now}')");
    }

    public static function rawQuery($pdo, $sql, $params = []) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function rawFetch($pdo, $sql, $params = []) {
        $stmt = self::rawQuery($pdo, $sql, $params);
        return $stmt->fetch();
    }

    public static function rawFetchAll($pdo, $sql, $params = []) {
        $stmt = self::rawQuery($pdo, $sql, $params);
        return $stmt->fetchAll();
    }
}

class BaseTestCase {
    protected $pdo;
    protected $walletService;
    protected $orderService;
    protected $passCount = 0;
    protected $failCount = 0;
    protected $failMessages = [];
    protected $currentTestName = '';

    public function __construct() {
    }

    protected function setUp() {
        $this->pdo = TestHelper::createTestPdo();
        Database::setTestPdo($this->pdo);
        $this->walletService = new WalletService();
        $this->orderService = new OrderService();
    }

    protected function tearDown() {
        Database::resetTestPdo();
        $this->pdo = null;
    }

    public function run() {
        $reflection = new ReflectionClass($this);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
        $testMethods = [];
        foreach ($methods as $method) {
            if (strpos($method->getName(), 'test') === 0) {
                $testMethods[] = $method->getName();
            }
        }
        sort($testMethods);

        $className = get_class($this);
        echo "\n" . str_repeat('=', 70) . "\n";
        echo "  {$className}\n";
        echo str_repeat('=', 70) . "\n";

        $this->passCount = 0;
        $this->failCount = 0;
        $this->failMessages = [];

        foreach ($testMethods as $methodName) {
            $this->currentTestName = $methodName;
            try {
                $this->setUp();
                $this->$methodName();
                $this->tearDown();
                $this->passCount++;
                echo "  [PASS] {$methodName}\n";
            } catch (Throwable $e) {
                $this->failCount++;
                $msg = $e->getMessage();
                $file = $e->getFile();
                $line = $e->getLine();
                $this->failMessages[] = [
                    'method' => $methodName,
                    'message' => $msg,
                    'file' => $file,
                    'line' => $line,
                    'trace' => $e->getTraceAsString(),
                ];
                echo "  [FAIL] {$methodName}\n         {$msg} ({$file}:{$line})\n";
                try {
                    $this->tearDown();
                } catch (Throwable $te) {
                }
            }
        }

        $total = $this->passCount + $this->failCount;
        echo str_repeat('-', 70) . "\n";
        echo "  Results: {$this->passCount}/{$total} passed, {$this->failCount} failed\n";
        echo str_repeat('=', 70) . "\n";

        return [
            'class' => $className,
            'total' => $total,
            'pass' => $this->passCount,
            'fail' => $this->failCount,
            'failures' => $this->failMessages,
        ];
    }

    protected function assertEqual($expected, $actual, $message = '') {
        if ($expected != $actual) {
            $msg = $message ?: "Expected " . var_export($expected, true) . " but got " . var_export($actual, true);
            throw new Exception($msg);
        }
    }

    protected function assertSame($expected, $actual, $message = '') {
        if ($expected !== $actual) {
            $msg = $message ?: "Expected (same) " . var_export($expected, true) . " but got " . var_export($actual, true);
            throw new Exception($msg);
        }
    }

    protected function assertTrue($condition, $message = '') {
        if ($condition !== true) {
            $msg = $message ?: "Expected true but got " . var_export($condition, true);
            throw new Exception($msg);
        }
    }

    protected function assertFalse($condition, $message = '') {
        if ($condition !== false) {
            $msg = $message ?: "Expected false but got " . var_export($condition, true);
            throw new Exception($msg);
        }
    }

    protected function assertNull($value, $message = '') {
        if ($value !== null) {
            $msg = $message ?: "Expected null but got " . var_export($value, true);
            throw new Exception($msg);
        }
    }

    protected function assertNotNull($value, $message = '') {
        if ($value === null) {
            $msg = $message ?: "Expected not null but got null";
            throw new Exception($msg);
        }
    }

    protected function assertEmpty($value, $message = '') {
        if (!empty($value)) {
            $msg = $message ?: "Expected empty but got " . var_export($value, true);
            throw new Exception($msg);
        }
    }

    protected function assertNotEmpty($value, $message = '') {
        if (empty($value)) {
            $msg = $message ?: "Expected not empty but got empty";
            throw new Exception($msg);
        }
    }

    protected function assertException($callable, $expectedException = null, $expectedCode = null, $message = '') {
        $caught = null;
        try {
            $callable();
        } catch (Throwable $e) {
            $caught = $e;
        }
        if ($caught === null) {
            $msg = $message ?: "Expected exception but none was thrown";
            throw new Exception($msg);
        }
        if ($expectedException !== null && !($caught instanceof $expectedException)) {
            $msg = $message ?: "Expected exception {$expectedException} but got " . get_class($caught) . ": " . $caught->getMessage();
            throw new Exception($msg);
        }
        if ($expectedCode !== null && $caught->getCode() !== $expectedCode) {
            $msg = $message ?: "Expected exception code {$expectedCode} but got " . $caught->getCode() . ": " . $caught->getMessage();
            throw new Exception($msg);
        }
        return $caught;
    }

    protected function assertArrayHasKey($key, $array, $message = '') {
        if (!is_array($array) || !array_key_exists($key, $array)) {
            $msg = $message ?: "Expected array to have key '{$key}'";
            throw new Exception($msg);
        }
    }

    protected function assertBetween($value, $min, $max, $message = '') {
        if ($value < $min || $value > $max) {
            $msg = $message ?: "Expected {$value} to be between {$min} and {$max}";
            throw new Exception($msg);
        }
    }

    protected function assertGreaterThan($expected, $actual, $message = '') {
        if ($actual <= $expected) {
            $msg = $message ?: "Expected {$actual} to be greater than {$expected}";
            throw new Exception($msg);
        }
    }

    protected function assertLessThan($expected, $actual, $message = '') {
        if ($actual >= $expected) {
            $msg = $message ?: "Expected {$actual} to be less than {$expected}";
            throw new Exception($msg);
        }
    }

    protected function assertContains($needle, $haystack, $message = '') {
        if (is_array($haystack)) {
            if (!in_array($needle, $haystack)) {
                $msg = $message ?: "Expected array to contain " . var_export($needle, true);
                throw new Exception($msg);
            }
        } elseif (is_string($haystack)) {
            if (strpos($haystack, $needle) === false) {
                $msg = $message ?: "Expected string to contain '{$needle}'";
                throw new Exception($msg);
            }
        } else {
            throw new Exception("assertContains: haystack must be array or string");
        }
    }

    protected function assertNotContains($needle, $haystack, $message = '') {
        if (is_array($haystack)) {
            if (in_array($needle, $haystack)) {
                $msg = $message ?: "Expected array NOT to contain " . var_export($needle, true);
                throw new Exception($msg);
            }
        } elseif (is_string($haystack)) {
            if (strpos($haystack, $needle) !== false) {
                $msg = $message ?: "Expected string NOT to contain '{$needle}'";
                throw new Exception($msg);
            }
        } else {
            throw new Exception("assertNotContains: haystack must be array or string");
        }
    }

    protected function assertCount($expected, $array, $message = '') {
        if (!is_array($array) && !($array instanceof Countable)) {
            throw new Exception("assertCount: subject must be array or Countable");
        }
        $actual = is_array($array) ? count($array) : $array->count();
        if ($expected !== $actual) {
            $msg = $message ?: "Expected count {$expected} but got {$actual}";
            throw new Exception($msg);
        }
    }

    protected function assertInstanceOf($className, $object, $message = '') {
        if (!($object instanceof $className)) {
            $msg = $message ?: "Expected instance of {$className} but got " . (is_object($object) ? get_class($object) : gettype($object));
            throw new Exception($msg);
        }
    }

    protected function assertIsArray($value, $message = '') {
        if (!is_array($value)) {
            $msg = $message ?: "Expected array but got " . gettype($value);
            throw new Exception($msg);
        }
    }

    protected function assertIsString($value, $message = '') {
        if (!is_string($value)) {
            $msg = $message ?: "Expected string but got " . gettype($value);
            throw new Exception($msg);
        }
    }

    protected function assertIsInt($value, $message = '') {
        if (!is_int($value)) {
            $msg = $message ?: "Expected int but got " . gettype($value);
            throw new Exception($msg);
        }
    }

    protected function assertIsNumeric($value, $message = '') {
        if (!is_numeric($value)) {
            $msg = $message ?: "Expected numeric but got " . gettype($value);
            throw new Exception($msg);
        }
    }
}

class TestRunner {
    public static function run($testClasses) {
        echo "\n";
        echo str_repeat('#', 70) . "\n";
        echo "  TestRunner - Starting Test Suite\n";
        echo "  Time: " . date('Y-m-d H:i:s') . "\n";
        echo str_repeat('#', 70) . "\n";

        $totalPass = 0;
        $totalFail = 0;
        $allFailures = [];
        $startTime = microtime(true);

        foreach ($testClasses as $className) {
            if (!class_exists($className)) {
                echo "\n[ERROR] Class {$className} not found\n";
                $totalFail++;
                continue;
            }
            $test = new $className();
            $result = $test->run();
            $totalPass += $result['pass'];
            $totalFail += $result['fail'];
            if (!empty($result['failures'])) {
                foreach ($result['failures'] as $f) {
                    $allFailures[] = [
                        'class' => $result['class'],
                        'method' => $f['method'],
                        'message' => $f['message'],
                        'file' => $f['file'],
                        'line' => $f['line'],
                    ];
                }
            }
        }

        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 4);
        $total = $totalPass + $totalFail;

        echo "\n\n";
        echo str_repeat('#', 70) . "\n";
        echo "  TEST SUITE SUMMARY\n";
        echo str_repeat('#', 70) . "\n";
        echo "  Total tests:  {$total}\n";
        echo "  Passed:       {$totalPass}\n";
        echo "  Failed:       {$totalFail}\n";
        echo "  Duration:     {$duration}s\n";

        if ($totalFail > 0) {
            echo "\n  FAILED TESTS:\n";
            echo str_repeat('-', 70) . "\n";
            foreach ($allFailures as $idx => $f) {
                $n = $idx + 1;
                echo "  {$n}. {$f['class']}::{$f['method']}\n";
                echo "     {$f['message']}\n";
                echo "     {$f['file']}:{$f['line']}\n\n";
            }
        }

        echo str_repeat('#', 70) . "\n";

        if ($totalFail > 0) {
            exit(1);
        }
        exit(0);
    }
}
