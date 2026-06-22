<?php

require_once __DIR__ . '/../models/Wallet.php';
require_once __DIR__ . '/../models/WalletTransaction.php';
require_once __DIR__ . '/../models/RechargeRecord.php';

class WalletService {
    private $walletModel;
    private $transactionModel;
    private $rechargeModel;

    public function __construct() {
        $this->walletModel = new Wallet();
        $this->transactionModel = new WalletTransaction();
        $this->rechargeModel = new RechargeRecord();
    }

    private function beginTransaction() {
        return $this->walletModel->beginTransaction();
    }

    private function commit() {
        return $this->walletModel->commit();
    }

    private function rollBack() {
        return $this->walletModel->rollBack();
    }

    private function ensureWalletExists($userId) {
        $wallet = $this->walletModel->getByUserId($userId);
        if (!$wallet) {
            $walletId = $this->walletModel->insert([
                'user_id' => $userId,
                'balance' => 0,
                'frozen_amount' => 0,
            ]);
            return $this->walletModel->findById($walletId);
        }
        return $wallet;
    }

    private function getWalletForUpdate($userId) {
        $pdo = $this->walletModel->getConnection();
        $table = $this->walletModel->getTable();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $forUpdate = ($driver === 'mysql') ? ' FOR UPDATE' : '';
        $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE user_id = :user_id{$forUpdate}");
        $stmt->execute([':user_id' => $userId]);
        $wallet = $stmt->fetch();
        if (!$wallet) {
            $this->walletModel->insert([
                'user_id' => $userId,
                'balance' => 0,
                'frozen_amount' => 0,
            ]);
            $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE user_id = :user_id{$forUpdate}");
            $stmt->execute([':user_id' => $userId]);
            $wallet = $stmt->fetch();
        }
        return $wallet;
    }

    public function getWalletInfo($userId) {
        $wallet = $this->ensureWalletExists($userId);
        return [
            'balance' => floatval($wallet['balance']),
            'frozen_amount' => floatval($wallet['frozen_amount']),
            'available_balance' => floatval($wallet['balance']) - floatval($wallet['frozen_amount']),
            'updated_at' => $wallet['updated_at'],
        ];
    }

    public function getShortage($userId, $requiredAmount) {
        $walletInfo = $this->getWalletInfo($userId);
        return max(0, floatval($requiredAmount) - $walletInfo['available_balance']);
    }

    public function hasEnoughBalance($userId, $requiredAmount) {
        $shortage = $this->getShortage($userId, $requiredAmount);
        return $shortage <= 0;
    }

    public function recharge($userId, $amount, $channel = 'manual') {
        if ($amount <= 0) {
            throw new BizInvalidArgumentException('充值金额必须大于0', 1001);
        }

        $this->beginTransaction();

        try {
            $wallet = $this->getWalletForUpdate($userId);
            $balanceBefore = floatval($wallet['balance']);
            $balanceAfter = $balanceBefore + $amount;

            $this->walletModel->update($wallet['id'], [
                'balance' => $balanceAfter,
            ]);

            $this->transactionModel->insert([
                'user_id' => $userId,
                'type' => WalletTransaction::TYPE_RECHARGE,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'description' => '账户充值',
            ]);

            $rechargeId = $this->rechargeModel->insert([
                'user_id' => $userId,
                'amount' => $amount,
                'channel' => $channel,
                'status' => RechargeRecord::STATUS_SUCCESS,
                'transaction_id' => 'TXN' . date('YmdHis') . mt_rand(1000, 9999),
            ]);

            $this->commit();

            return [
                'recharge_id' => $rechargeId,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'available_after' => $balanceAfter - floatval($wallet['frozen_amount']),
                'channel' => $channel,
            ];
        } catch (Exception $e) {
            $this->rollBack();
            if ($e instanceof BizInvalidArgumentException) {
                throw $e;
            }
            throw new BizRuntimeException('充值失败：' . $e->getMessage(), 1002, $e);
        }
    }

    public function freeze($userId, $amount, $orderId = null, $description = '订单冻结') {
        if ($amount <= 0) {
            throw new BizInvalidArgumentException('冻结金额必须大于0', 1101);
        }

        $this->beginTransaction();

        try {
            $wallet = $this->getWalletForUpdate($userId);
            $available = floatval($wallet['balance']) - floatval($wallet['frozen_amount']);

            if ($available < $amount) {
                $this->rollBack();
                throw new InsufficientBalanceException(
                    '可用余额不足，无法冻结',
                    1102,
                    [
                        'available' => $available,
                        'required' => $amount,
                        'shortage' => $amount - $available,
                    ]
                );
            }

            $newFrozenAmount = floatval($wallet['frozen_amount']) + $amount;

            $this->walletModel->update($wallet['id'], [
                'frozen_amount' => $newFrozenAmount,
            ]);

            $this->transactionModel->insert([
                'user_id' => $userId,
                'order_id' => $orderId,
                'type' => WalletTransaction::TYPE_FREEZE,
                'amount' => $amount,
                'balance_before' => floatval($wallet['balance']),
                'balance_after' => floatval($wallet['balance']),
                'description' => $description,
            ]);

            $this->commit();

            return [
                'frozen_amount' => $newFrozenAmount,
                'available_after' => floatval($wallet['balance']) - $newFrozenAmount,
            ];
        } catch (Exception $e) {
            if ($this->walletModel->inTransaction()) {
                $this->rollBack();
            }
            if ($e instanceof BizInvalidArgumentException || $e instanceof InsufficientBalanceException) {
                throw $e;
            }
            throw new BizRuntimeException('冻结失败：' . $e->getMessage(), 1103, $e);
        }
    }

    public function unfreeze($userId, $amount, $orderId = null, $description = '订单解冻') {
        if ($amount <= 0) {
            throw new BizInvalidArgumentException('解冻金额必须大于0', 1201);
        }

        $this->beginTransaction();

        try {
            $wallet = $this->getWalletForUpdate($userId);

            if (floatval($wallet['frozen_amount']) < $amount) {
                $this->rollBack();
                throw new BizInvalidArgumentException(
                    sprintf('冻结金额不足，当前冻结%.2f，需解冻%.2f', floatval($wallet['frozen_amount']), $amount),
                    1202
                );
            }

            $newFrozenAmount = floatval($wallet['frozen_amount']) - $amount;

            $this->walletModel->update($wallet['id'], [
                'frozen_amount' => $newFrozenAmount,
            ]);

            $this->transactionModel->insert([
                'user_id' => $userId,
                'order_id' => $orderId,
                'type' => WalletTransaction::TYPE_UNFREEZE,
                'amount' => $amount,
                'balance_before' => floatval($wallet['balance']),
                'balance_after' => floatval($wallet['balance']),
                'description' => $description,
            ]);

            $this->commit();

            return [
                'frozen_amount' => $newFrozenAmount,
                'available_after' => floatval($wallet['balance']) - $newFrozenAmount,
            ];
        } catch (Exception $e) {
            if ($this->walletModel->inTransaction()) {
                $this->rollBack();
            }
            if ($e instanceof BizInvalidArgumentException) {
                throw $e;
            }
            throw new BizRuntimeException('解冻失败：' . $e->getMessage(), 1203, $e);
        }
    }

    public function deduct($userId, $amount, $orderId = null, $description = '订单支付') {
        if ($amount <= 0) {
            throw new BizInvalidArgumentException('扣款金额必须大于0', 1301);
        }

        $this->beginTransaction();

        try {
            $wallet = $this->getWalletForUpdate($userId);
            $available = floatval($wallet['balance']) - floatval($wallet['frozen_amount']);

            if ($available < $amount) {
                $this->rollBack();
                throw new InsufficientBalanceException(
                    '可用余额不足',
                    1302,
                    [
                        'available' => $available,
                        'required' => $amount,
                        'shortage' => $amount - $available,
                    ]
                );
            }

            $balanceBefore = floatval($wallet['balance']);
            $balanceAfter = $balanceBefore - $amount;

            $this->walletModel->update($wallet['id'], [
                'balance' => $balanceAfter,
            ]);

            $this->transactionModel->insert([
                'user_id' => $userId,
                'order_id' => $orderId,
                'type' => WalletTransaction::TYPE_PAYMENT,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'description' => $description,
            ]);

            $this->commit();

            return [
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'available_after' => $balanceAfter - floatval($wallet['frozen_amount']),
            ];
        } catch (Exception $e) {
            if ($this->walletModel->inTransaction()) {
                $this->rollBack();
            }
            if ($e instanceof BizInvalidArgumentException || $e instanceof InsufficientBalanceException) {
                throw $e;
            }
            throw new BizRuntimeException('扣款失败：' . $e->getMessage(), 1303, $e);
        }
    }

    public function deductFromFrozen($userId, $amount, $orderId = null, $description = '冻结金额扣款') {
        if ($amount <= 0) {
            throw new BizInvalidArgumentException('扣款金额必须大于0', 1401);
        }

        $this->beginTransaction();

        try {
            $wallet = $this->getWalletForUpdate($userId);

            if (floatval($wallet['frozen_amount']) < $amount) {
                $this->rollBack();
                throw new BizInvalidArgumentException(
                    sprintf('冻结金额不足，当前冻结%.2f，需扣款%.2f', floatval($wallet['frozen_amount']), $amount),
                    1402
                );
            }

            $balanceBefore = floatval($wallet['balance']);
            $frozenBefore = floatval($wallet['frozen_amount']);
            $balanceAfter = $balanceBefore - $amount;
            $frozenAfter = $frozenBefore - $amount;

            $this->walletModel->update($wallet['id'], [
                'balance' => $balanceAfter,
                'frozen_amount' => $frozenAfter,
            ]);

            $this->transactionModel->insert([
                'user_id' => $userId,
                'order_id' => $orderId,
                'type' => WalletTransaction::TYPE_PAYMENT,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'description' => $description,
            ]);

            $this->commit();

            return [
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'frozen_before' => $frozenBefore,
                'frozen_after' => $frozenAfter,
                'available_after' => $balanceAfter - $frozenAfter,
            ];
        } catch (Exception $e) {
            if ($this->walletModel->inTransaction()) {
                $this->rollBack();
            }
            if ($e instanceof BizInvalidArgumentException) {
                throw $e;
            }
            throw new BizRuntimeException('冻结扣款失败：' . $e->getMessage(), 1403, $e);
        }
    }

    public function rechargeAndDeductFromFrozen($userId, $rechargeAmount, $deductAmount, $orderId = null, $channel = 'manual') {
        if ($rechargeAmount <= 0) {
            throw new BizInvalidArgumentException('充值金额必须大于0', 1501);
        }
        if ($deductAmount <= 0) {
            throw new BizInvalidArgumentException('扣款金额必须大于0', 1502);
        }

        $this->beginTransaction();

        try {
            $wallet = $this->getWalletForUpdate($userId);
            $balanceBefore = floatval($wallet['balance']);
            $frozenBefore = floatval($wallet['frozen_amount']);

            $balanceAfterRecharge = $balanceBefore + $rechargeAmount;

            $this->walletModel->update($wallet['id'], [
                'balance' => $balanceAfterRecharge,
            ]);

            $this->transactionModel->insert([
                'user_id' => $userId,
                'order_id' => $orderId,
                'type' => WalletTransaction::TYPE_RECHARGE,
                'amount' => $rechargeAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfterRecharge,
                'description' => '补款充值',
            ]);

            $rechargeId = $this->rechargeModel->insert([
                'user_id' => $userId,
                'amount' => $rechargeAmount,
                'channel' => $channel,
                'status' => RechargeRecord::STATUS_SUCCESS,
                'transaction_id' => 'TXN' . date('YmdHis') . mt_rand(1000, 9999),
                'order_id' => $orderId,
            ]);

            if ($frozenBefore < $deductAmount) {
                $this->rollBack();
                throw new BizInvalidArgumentException(
                    sprintf('冻结金额不足，无法扣款，当前冻结%.2f，需扣款%.2f', $frozenBefore, $deductAmount),
                    1503
                );
            }

            $balanceFinal = $balanceAfterRecharge - $deductAmount;
            $frozenFinal = $frozenBefore - $deductAmount;

            $this->walletModel->update($wallet['id'], [
                'balance' => $balanceFinal,
                'frozen_amount' => $frozenFinal,
            ]);

            $this->transactionModel->insert([
                'user_id' => $userId,
                'order_id' => $orderId,
                'type' => WalletTransaction::TYPE_PAYMENT,
                'amount' => $deductAmount,
                'balance_before' => $balanceAfterRecharge,
                'balance_after' => $balanceFinal,
                'description' => '补款支付扣款',
            ]);

            $this->commit();

            return [
                'recharge_id' => $rechargeId,
                'recharge_amount' => $rechargeAmount,
                'deduct_amount' => $deductAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceFinal,
                'frozen_before' => $frozenBefore,
                'frozen_after' => $frozenFinal,
                'available_after' => $balanceFinal - $frozenFinal,
            ];
        } catch (Exception $e) {
            if ($this->walletModel->inTransaction()) {
                $this->rollBack();
            }
            if ($e instanceof BizInvalidArgumentException) {
                throw $e;
            }
            throw new BizRuntimeException('充值扣款失败：' . $e->getMessage(), 1504, $e);
        }
    }

    public function getTransactions($userId, $limit = 20) {
        return $this->transactionModel->getListByUserId($userId, $limit);
    }

    public function getRechargeRecords($userId, $limit = 20) {
        return $this->rechargeModel->getListByUserId($userId, $limit);
    }
}

class BizInvalidArgumentException extends Exception {
    protected $context;
    public function __construct($message = "", $code = 0, $context = []) {
        parent::__construct($message, $code);
        $this->context = is_array($context) ? $context : [];
    }
    public function getContext() { return $this->context; }
}

class InsufficientBalanceException extends Exception {
    protected $context;
    public function __construct($message = "", $code = 0, $context = []) {
        parent::__construct($message, $code);
        $this->context = is_array($context) ? $context : [];
    }
    public function getContext() { return $this->context; }
}

class BizRuntimeException extends Exception {
    protected $context;
    public function __construct($message = "", $code = 0, $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}
