<?php

require_once __DIR__ . '/../config/Database.php';

class BaseModel {
    protected $pdo;
    protected $table;
    private static $transactionLevel = 0;

    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
    }

    public function getConnection() {
        return $this->pdo;
    }

    public function getTable() {
        return $this->table;
    }

    public function beginTransaction() {
        if (self::$transactionLevel === 0) {
            $result = $this->pdo->beginTransaction();
            self::$transactionLevel++;
            return $result;
        }
        self::$transactionLevel++;
        return true;
    }

    public function commit() {
        if (self::$transactionLevel <= 1) {
            self::$transactionLevel = 0;
            return $this->pdo->commit();
        }
        self::$transactionLevel--;
        return true;
    }

    public function rollBack() {
        if (self::$transactionLevel <= 1) {
            self::$transactionLevel = 0;
            return $this->pdo->rollBack();
        }
        self::$transactionLevel--;
        return true;
    }

    public function inTransaction() {
        return self::$transactionLevel > 0;
    }

    public function findById($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    public function findAll($where = [], $orderBy = 'id DESC', $limit = null) {
        $sql = "SELECT * FROM {$this->table}";
        $params = [];

        if (!empty($where)) {
            $conditions = [];
            foreach ($where as $key => $value) {
                $conditions[] = "{$key} = :{$key}";
                $params[":{$key}"] = $value;
            }
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }

        $sql .= " ORDER BY {$orderBy}";

        if ($limit) {
            $sql .= " LIMIT {$limit}";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function insert($data) {
        $keys = array_keys($data);
        $placeholders = array_map(function($key) {
            return ":{$key}";
        }, $keys);

        $sql = "INSERT INTO {$this->table} (" . implode(', ', $keys) . ") 
                VALUES (" . implode(', ', $placeholders) . ")";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
        
        return $this->pdo->lastInsertId();
    }

    public function update($id, $data) {
        $sets = [];
        $params = [':id' => $id];

        foreach ($data as $key => $value) {
            $sets[] = "{$key} = :{$key}";
            $params[":{$key}"] = $value;
        }

        $sets[] = "updated_at = CURRENT_TIMESTAMP";

        $sql = "UPDATE {$this->table} SET " . implode(', ', $sets) . " WHERE id = :id";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    public function delete($id) {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }
}
