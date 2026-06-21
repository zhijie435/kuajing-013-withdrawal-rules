<?php

require_once __DIR__ . '/BaseModel.php';

class Wallet extends BaseModel {
    protected $table = 'wallets';

    public function getByUserId($userId) {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE user_id = :user_id");
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetch();
    }

    public function getBalance($userId) {
        $wallet = $this->getByUserId($userId);
        return $wallet ? floatval($wallet['balance']) : 0;
    }

    public function getAvailableBalance($userId) {
        $wallet = $this->getByUserId($userId);
        if (!$wallet) return 0;
        return floatval($wallet['balance']) - floatval($wallet['frozen_amount']);
    }
}
