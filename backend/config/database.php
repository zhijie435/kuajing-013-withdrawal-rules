<?php

class Database {
    private static $instance = null;
    private static $testPdo = null;
    private $pdo;

    private function __construct() {
        if (self::$testPdo !== null) {
            $this->pdo = self::$testPdo;
            return;
        }

        $host = 'localhost';
        $dbname = 'crm_withdrawal';
        $charset = 'utf8mb4';
        $username = 'root';
        $password = '';

        try {
            $this->pdo = new PDO(
                "mysql:host={$host};dbname={$dbname};charset={$charset}",
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            if (php_sapi_name() !== 'cli') {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['code' => 500, 'msg' => '数据库连接失败', 'data' => null], JSON_UNESCAPED_UNICODE);
                exit;
            } else {
                fwrite(STDERR, "数据库连接失败: " . $e->getMessage() . "\n");
                throw new RuntimeException("数据库连接失败: " . $e->getMessage(), 500, $e);
            }
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function setTestPdo($pdo) {
        self::$testPdo = $pdo;
        self::$instance = null;
    }

    public static function resetTestPdo() {
        self::$testPdo = null;
        self::$instance = null;
    }

    public function getConnection() {
        return $this->pdo;
    }
}

if (php_sapi_name() !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    return Database::getInstance()->getConnection();
}
