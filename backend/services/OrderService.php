<?php

require_once __DIR__ . '/../models/Order.php';
require_once __DIR__ . '/WalletService.php';

class OrderService {
    private $orderModel;
    private $walletService;

    public function __construct() {
        $this->orderModel = new Order();
        $this->walletService = new WalletService();
    }

    private function beginTransaction() {
        return $this->orderModel->beginTransaction();
    }
    private function commit() {
        return $this->orderModel->commit();
    }
    private function rollBack() {
        return $this->orderModel->rollBack();
    }
    private function inTransaction() {
        return $this->orderModel->inTransaction();
    }

    private function getOrderOrThrow($orderId) {
        $order = $this->orderModel->findById($orderId);
        if (!$order) {
            throw new OrderNotFoundException('订单不存在', 2001, ['order_id' => $orderId]);
        }
        return $order;
    }

    private function checkOrderOwner($order, $userId) {
        if (intval($order['user_id']) !== intval($userId)) {
            throw new OrderPermissionDeniedException(
                '无权操作此订单',
                2002,
                ['order_id' => $order['id'], 'order_user_id' => $order['user_id'], 'operator_id' => $userId]
            );
        }
    }

    private function getAndValidateOrder($orderId, $userId) {
        $order = $this->getOrderOrThrow($orderId);
        $this->checkOrderOwner($order, $userId);
        return $order;
    }

    private function buildShortageResponse($order, $walletInfo, $message = '') {
        $amount = floatval($order['amount']);
        $shortage = max(0, $amount - $walletInfo['available_balance']);
        return [
            'success' => false,
            'order' => $order,
            'wallet' => $walletInfo,
            'frozen' => true,
            'shortage' => $shortage,
            'suggest_recharge' => $shortage > 0 ? intval(ceil($shortage)) : 0,
            'message' => $message ?: ($shortage > 0
                ? sprintf('余额不足，还差 %.2f 元，请充值后重试', $shortage)
                : '订单已冻结'),
        ];
    }

    private function buildSuccessResponse($order, $walletInfo, $message = '支付成功') {
        return [
            'success' => true,
            'order' => $order,
            'wallet' => $walletInfo,
            'frozen' => false,
            'shortage' => 0,
            'suggest_recharge' => 0,
            'message' => $message,
        ];
    }

    public function createOrder($userId, $amount, $title, $description = '') {
        if ($amount <= 0) {
            throw new BizInvalidArgumentException('订单金额必须大于0', 2101);
        }
        if (empty($title)) {
            throw new BizInvalidArgumentException('订单标题不能为空', 2102);
        }
        $this->beginTransaction();
        try {
            $orderNo = $this->orderModel->generateOrderNo();
            $orderId = $this->orderModel->insert([
                'order_no' => $orderNo,
                'user_id' => $userId,
                'amount' => $amount,
                'title' => $title,
                'description' => $description,
                'status' => Order::STATUS_PENDING,
            ]);
            $this->commit();
            return $this->processPayment($orderId, $userId);
        } catch (Exception $e) {
            if ($this->inTransaction()) {
                $this->rollBack();
            }
            if ($e instanceof BizInvalidArgumentException || $e instanceof OrderNotFoundException || $e instanceof OrderPermissionDeniedException) {
                throw $e;
            }
            throw new BizRuntimeException('创建订单失败：' . $e->getMessage(), 2103, $e);
        }
    }

    public function processPayment($orderId, $userId) {
        $order = $this->getAndValidateOrder($orderId, $userId);
        if (!in_array($order['status'], [Order::STATUS_PENDING, Order::STATUS_FROZEN], true)) {
            throw new OrderStateException(
                sprintf('当前订单状态「%s」不支持支付', $order['status']),
                2201,
                ['order_id' => $orderId, 'status' => $order['status']]
            );
        }
        $amount = floatval($order['amount']);
        $walletInfo = $this->walletService->getWalletInfo($userId);
        if ($walletInfo['available_balance'] < $amount) {
            if ($order['status'] === Order::STATUS_FROZEN) {
                return $this->buildShortageResponse($order, $walletInfo);
            }
            return $this->freezeOrderInternal($order, $userId, $walletInfo, '余额不足，订单已冻结，请充值后重试');
        }
        return $this->executePayment($order, $userId, $amount, $walletInfo);
    }

    private function executePayment($order, $userId, $amount, $walletInfo = null) {
        if ($walletInfo === null) {
            $walletInfo = $this->walletService->getWalletInfo($userId);
        }
        $this->beginTransaction();
        try {
            $this->walletService->freeze($userId, $amount, $order['id'], '订单支付冻结');
            $this->walletService->deductFromFrozen($userId, $amount, $order['id'], '订单支付扣款');
            $this->orderModel->updateStatus($order['id'], Order::STATUS_PAID, ['frozen_reason' => null]);
            $this->commit();
            $paidOrder = $this->orderModel->findById($order['id']);
            $updatedWallet = $this->walletService->getWalletInfo($userId);
            return $this->buildSuccessResponse($paidOrder, $updatedWallet, '支付成功');
        } catch (Exception $e) {
            if ($this->inTransaction()) {
                $this->rollBack();
            }
            if ($e instanceof InsufficientBalanceException) {
                $currentWallet = $this->walletService->getWalletInfo($userId);
                return $this->buildShortageResponse($order, $currentWallet, '支付时余额发生变化，请重新确认');
            }
            if ($e instanceof BizInvalidArgumentException) {
                throw $e;
            }
            throw new BizRuntimeException('支付失败：' . $e->getMessage(), 2202, $e);
        }
    }

    public function freezeOrder($orderId, $userId, $reason = '') {
        $order = $this->getAndValidateOrder($orderId, $userId);
        $walletInfo = $this->walletService->getWalletInfo($userId);
        if ($order['status'] === Order::STATUS_FROZEN) {
            return $this->buildShortageResponse($order, $walletInfo, $reason ?: '订单已处于冻结状态');
        }
        if ($order['status'] !== Order::STATUS_PENDING) {
            throw new OrderStateException(
                sprintf('当前订单状态「%s」无法冻结，仅待支付订单可冻结', $order['status']),
                2301,
                ['order_id' => $orderId, 'status' => $order['status']]
            );
        }
        return $this->freezeOrderInternal($order, $userId, $walletInfo, $reason);
    }

    private function freezeOrderInternal($order, $userId, $walletInfo, $reason = '') {
        $this->orderModel->updateStatus($order['id'], Order::STATUS_FROZEN, [
            'frozen_reason' => $reason,
        ]);
        $frozenOrder = $this->orderModel->findById($order['id']);
        $amount = floatval($order['amount']);
        $shortage = max(0, $amount - $walletInfo['available_balance']);
        return [
            'success' => false,
            'order' => $frozenOrder,
            'wallet' => $walletInfo,
            'frozen' => true,
            'shortage' => $shortage,
            'suggest_recharge' => $shortage > 0 ? intval(ceil($shortage)) : 0,
            'message' => $reason ?: ($shortage > 0
                ? sprintf('余额不足，还差 %.2f 元，订单已冻结', $shortage)
                : '订单已冻结'),
        ];
    }

    public function unfreezeOrder($orderId, $userId) {
        $order = $this->getAndValidateOrder($orderId, $userId);
        if ($order['status'] !== Order::STATUS_FROZEN) {
            throw new OrderStateException(
                sprintf('订单未冻结，当前状态「%s」无需解冻', $order['status']),
                2401,
                ['order_id' => $orderId, 'status' => $order['status']]
            );
        }
        $this->orderModel->updateStatus($orderId, Order::STATUS_PENDING, [
            'frozen_reason' => null,
        ]);
        return $this->orderModel->findById($orderId);
    }

    public function retryPayment($orderId, $userId) {
        $order = $this->getAndValidateOrder($orderId, $userId);
        if ($order['status'] !== Order::STATUS_FROZEN) {
            throw new OrderStateException(
                sprintf('只有冻结状态的订单才能重试支付', $order['status']),
                2501,
                ['order_id' => $orderId, 'status' => $order['status']]
            );
        }
        $amount = floatval($order['amount']);
        $walletInfo = $this->walletService->getWalletInfo($userId);
        if ($walletInfo['available_balance'] < $amount) {
            return $this->buildShortageResponse($order, $walletInfo, '余额仍然不足，请继续充值');
        }
        try {
            $result = $this->executePayment($order, $userId, $amount, $walletInfo);
        } catch (InsufficientBalanceException $e) {
            $currentWallet = $this->walletService->getWalletInfo($userId);
            return $this->buildShortageResponse($order, $currentWallet, '支付时余额发生变化，请继续充值');
        }
        return $result;
    }

    public function rechargeAndRetry($orderId, $userId, $rechargeAmount, $channel = 'manual') {
        if ($rechargeAmount <= 0) {
            throw new BizInvalidArgumentException('充值金额必须大于0', 2601);
        }
        $order = $this->getAndValidateOrder($orderId, $userId);
        if ($order['status'] !== Order::STATUS_FROZEN) {
            throw new OrderStateException(
                sprintf('只有冻结状态的订单才能充值补款，当前状态「%s」', $order['status']),
                2602,
                ['order_id' => $orderId, 'status' => $order['status']]
            );
        }
        $amount = floatval($order['amount']);
        $walletInfo = $this->walletService->getWalletInfo($userId);
        $availableAfterRecharge = $walletInfo['available_balance'] + $rechargeAmount;
        if ($availableAfterRecharge < $amount) {
            $rechargeResult = null;
            try {
                $rechargeResult = $this->walletService->recharge($userId, $rechargeAmount, $channel);
            } catch (Exception $e) {
                throw new BizRuntimeException('充值失败：' . $e->getMessage(), 2603, $e);
            }
            $updatedWallet = $this->walletService->getWalletInfo($userId);
            $shortage = max(0, $amount - $updatedWallet['available_balance']);
            return [
                'success' => false,
                'recharge' => $rechargeResult,
                'order' => $order,
                'wallet' => $updatedWallet,
                'frozen' => true,
                'shortage' => $shortage,
                'suggest_recharge' => $shortage > 0 ? intval(ceil($shortage)) : 0,
                'message' => sprintf('充值成功，但余额仍不足，还差 %.2f 元，请继续充值', $shortage),
            ];
        }
        $this->beginTransaction();
        try {
            $rechargeResult = $this->walletService->recharge($userId, $rechargeAmount, $channel);
            $this->walletService->freeze($userId, $amount, $orderId, '补款支付冻结');
            $this->walletService->deductFromFrozen($userId, $amount, $orderId, '补款支付扣款');
            $this->orderModel->updateStatus($orderId, Order::STATUS_PAID, ['frozen_reason' => null]);
            $this->commit();
            $paidOrder = $this->orderModel->findById($orderId);
            $updatedWallet = $this->walletService->getWalletInfo($userId);
            return [
                'success' => true,
                'recharge' => $rechargeResult,
                'order' => $paidOrder,
                'wallet' => $paidOrder,
                'frozen' => false,
                'shortage' => 0,
                'suggest_recharge' => 0,
                'message' => '充值成功，订单支付完成',
            ];
        } catch (Exception $e) {
            if ($this->inTransaction()) {
                $this->rollBack();
            }
            if ($e instanceof InsufficientBalanceException) {
                $partialWallet = $this->walletService->getWalletInfo($userId);
                $shortage = max(0, $amount - $partialWallet['available_balance']);
                return [
                    'success' => false,
                    'recharge' => $rechargeResult ?? null,
                    'order' => $order,
                    'wallet' => $partialWallet,
                    'frozen' => true,
                    'shortage' => $shortage,
                    'suggest_recharge' => $shortage > 0 ? intval(ceil($shortage)) : 0,
                    'message' => sprintf('充值后余额仍不足，还差 %.2f 元，请继续充值', $shortage),
                ];
            }
            if ($e instanceof BizInvalidArgumentException || $e instanceof OrderNotFoundException || $e instanceof OrderPermissionDeniedException || $e instanceof OrderStateException) {
                throw $e;
            }
            throw new BizRuntimeException('充值补款失败：' . $e->getMessage(), 2604, $e);
        }
    }

    public function cancelOrder($orderId, $userId) {
        $order = $this->getAndValidateOrder($orderId, $userId);
        if (!in_array($order['status'], [Order::STATUS_PENDING, Order::STATUS_FROZEN], true)) {
            throw new OrderStateException(
                sprintf('当前订单状态「%s」无法取消', $order['status']),
                2701,
                ['order_id' => $orderId, 'status' => $order['status']]
            );
        }
        $this->orderModel->updateStatus($orderId, Order::STATUS_CANCELLED, ['frozen_reason' => null]);
        return $this->orderModel->findById($orderId);
    }

    public function getOrderDetail($orderId, $userId) {
        $order = $this->getAndValidateOrder($orderId, $userId);
        $walletInfo = $this->walletService->getWalletInfo($userId);
        $amount = floatval($order['amount']);
        $shortage = max(0, $amount - $walletInfo['available_balance']);
        return [
            'order' => $order,
            'wallet' => $walletInfo,
            'can_retry' => $order['status'] === Order::STATUS_FROZEN && $walletInfo['available_balance'] < $amount,
            'can_pay' => in_array($order['status'], [Order::STATUS_PENDING, Order::STATUS_FROZEN], true),
            'can_cancel' => in_array($order['status'], [Order::STATUS_PENDING, Order::STATUS_FROZEN], true),
            'can_complete' => $order['status'] === Order::STATUS_PAID,
            'shortage' => $shortage,
            'suggest_recharge' => $shortage > 0 ? intval(ceil($shortage)) : 0,
            'has_enough_balance' => $walletInfo['available_balance'] >= $amount,
        ];
    }

    public function getOrderList($userId, $status = null) {
        $allowedStatuses = [null, Order::STATUS_PENDING, Order::STATUS_FROZEN, Order::STATUS_PAID, Order::STATUS_COMPLETED, Order::STATUS_CANCELLED];
        if ($status !== null && !in_array($status, $allowedStatuses, true)) {
            throw new BizInvalidArgumentException(sprintf('无效的订单状态筛选值：%s', $status), 2801);
        }
        return $this->orderModel->getListByUserId($userId, $status);
    }

    public function getFrozenOrders($userId) {
        return $this->orderModel->getFrozenOrders($userId);
    }

    public function getFrozenSummary($userId) {
        $frozenOrders = $this->orderModel->getFrozenOrders($userId);
        $walletInfo = $this->walletService->getWalletInfo($userId);
        $totalAmount = 0.0;
        $payableCount = 0;
        $needRechargeCount = 0;
        foreach ($frozenOrders as $order) {
            $orderAmount = floatval($order['amount']);
            $totalAmount += $orderAmount;
            if ($walletInfo['available_balance'] >= $orderAmount) {
                $payableCount++;
            } else {
                $needRechargeCount++;
            }
        }
        $shortage = max(0, $totalAmount - $walletInfo['available_balance']);
        return [
            'total_count' => count($frozenOrders),
            'total_amount' => $totalAmount,
            'payable_count' => $payableCount,
            'need_recharge_count' => $needRechargeCount,
            'shortage' => $shortage,
            'suggest_recharge' => $shortage > 0 ? intval(ceil($shortage)) : 0,
            'wallet' => $walletInfo,
        ];
    }

    public function completeOrder($orderId, $userId) {
        $order = $this->getAndValidateOrder($orderId, $userId);
        if ($order['status'] !== Order::STATUS_PAID) {
            throw new OrderStateException(
                sprintf('只有已支付订单才能完成', $order['status']),
                2901,
                ['order_id' => $orderId, 'status' => $order['status']]
            );
        }
        $this->orderModel->updateStatus($orderId, Order::STATUS_COMPLETED);
        return $this->orderModel->findById($orderId);
    }

    public function batchFreezeOrders($orderIds, $userId, $reason = '') {
        if (!is_array($orderIds) || empty($orderIds)) {
            throw new BizInvalidArgumentException('订单ID列表不能为空', 3001);
        }
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
        $successIds = [];
        $successOrders = [];
        $failedItems = [];
        $skippedItems = [];
        foreach ($orderIds as $orderId) {
            try {
                $order = $this->orderModel->findById($orderId);
                if (!$order) {
                    $failedItems[] = ['order_id' => $orderId, 'order_no' => '', 'title' => '', 'amount' => 0, 'reason' => '订单不存在'];
                    continue;
                }
                if (intval($order['user_id']) !== intval($userId)) {
                    $failedItems[] = ['order_id' => $orderId, 'order_no' => $order['order_no'], 'title' => $order['title'], 'amount' => floatval($order['amount']), 'reason' => '无权操作此订单'];
                    continue;
                }
                if ($order['status'] === Order::STATUS_FROZEN) {
                    $skippedItems[] = ['order_id' => $orderId, 'order_no' => $order['order_no'], 'title' => $order['title'], 'amount' => floatval($order['amount']), 'reason' => '订单已处于冻结状态'];
                    $successOrders[] = $order;
                    $successIds[] = $orderId;
                    continue;
                }
                if ($order['status'] !== Order::STATUS_PENDING) {
                    $failedItems[] = ['order_id' => $orderId, 'order_no' => $order['order_no'], 'title' => $order['title'], 'amount' => floatval($order['amount']), 'reason' => sprintf('当前状态「%s」无法冻结', $order['status'])];
                    continue;
                }
                $this->orderModel->updateStatus($orderId, Order::STATUS_FROZEN, ['frozen_reason' => $reason ?: '批量冻结']);
                $frozenOrder = $this->orderModel->findById($orderId);
                $successIds[] = $orderId;
                $successOrders[] = $frozenOrder;
            } catch (Exception $e) {
                $failedItems[] = ['order_id' => $orderId, 'order_no' => $order['order_no'] ?? '', 'title' => $order['title'] ?? '', 'amount' => isset($order['amount']) ? floatval($order['amount']) : 0, 'reason' => $e->getMessage()];
            }
        }
        $walletInfo = $this->walletService->getWalletInfo($userId);
        return [
            'success_count' => count($successIds),
            'failed_count' => count($failedItems),
            'skipped_count' => count($skippedItems),
            'total_count' => count($orderIds),
            'success_ids' => $successIds,
            'success_orders' => $successOrders,
            'failed_items' => $failedItems,
            'skipped_items' => $skippedItems,
            'wallet' => $walletInfo,
            'message' => count($successIds) > 0 ? sprintf('批量冻结完成，成功 %d 个', count($successIds)) : '批量冻结未处理任何订单',
        ];
    }

    public function batchRetryPayment($orderIds, $userId) {
        if (!is_array($orderIds) || empty($orderIds)) {
            throw new BizInvalidArgumentException('订单ID列表不能为空', 3101);
        }
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
        $successIds = [];
        $successOrders = [];
        $failedItems = [];
        $stillFrozenItems = [];
        $candidates = [];
        foreach ($orderIds as $orderId) {
            try {
                $order = $this->orderModel->findById($orderId);
                if (!$order) {
                    $failedItems[] = ['order_id' => $orderId, 'order_no' => '', 'title' => '', 'amount' => 0, 'shortage' => 0, 'reason' => '订单不存在'];
                    continue;
                }
                if (intval($order['user_id']) !== intval($userId)) {
                    $failedItems[] = ['order_id' => $orderId, 'order_no' => $order['order_no'], 'title' => $order['title'], 'amount' => floatval($order['amount']), 'shortage' => 0, 'reason' => '无权操作此订单'];
                    continue;
                }
                if ($order['status'] !== Order::STATUS_FROZEN) {
                    if ($order['status'] === Order::STATUS_PAID || $order['status'] === Order::STATUS_COMPLETED) {
                        $successIds[] = $orderId;
                        $successOrders[] = $order;
                    } else {
                        $failedItems[] = ['order_id' => $orderId, 'order_no' => $order['order_no'], 'title' => $order['title'], 'amount' => floatval($order['amount']), 'shortage' => 0, 'reason' => sprintf('只有冻结状态的订单才能补款恢复', $order['status'])];
                    }
                    continue;
                }
                $candidates[] = $order;
            } catch (Exception $e) {
                $failedItems[] = ['order_id' => $orderId, 'order_no' => $order['order_no'] ?? '', 'title' => $order['title'] ?? '', 'amount' => isset($order['amount']) ? floatval($order['amount']) : 0, 'shortage' => 0, 'reason' => $e->getMessage()];
            }
        }
        usort($candidates, function ($a, $b) {
            $amtA = floatval($a['amount']);
            $amtB = floatval($b['amount']);
            if ($amtA === $amtB) return intval($a['id']) - intval($b['id']);
            return $amtA <=> $amtB;
        });
        foreach ($candidates as $order) {
            $amount = floatval($order['amount']);
            $orderId = intval($order['id']);
            try {
                $walletInfo = $this->walletService->getWalletInfo($userId);
                if ($walletInfo['available_balance'] < $amount) {
                    $shortage = $amount - $walletInfo['available_balance'];
                    $stillFrozenItems[] = ['order_id' => $orderId, 'order_no' => $order['order_no'], 'title' => $order['title'], 'amount' => $amount, 'shortage' => $shortage, 'suggest_recharge' => intval(ceil($shortage)), 'reason' => sprintf('余额不足，还差 %.2f 元', $shortage)];
                    continue;
                }
                $this->beginTransaction();
                try {
                    $this->walletService->freeze($userId, $amount, $orderId, '补款支付冻结');
                    $this->walletService->deductFromFrozen($userId, $amount, $orderId, '补款支付扣款');
                    $this->orderModel->updateStatus($orderId, Order::STATUS_PAID, ['frozen_reason' => null]);
                    $this->commit();
                    $paidOrder = $this->orderModel->findById($orderId);
                    $successIds[] = $orderId;
                    $successOrders[] = $paidOrder;
                } catch (Exception $innerE) {
                    if ($this->inTransaction()) $this->rollBack();
                    if ($innerE instanceof InsufficientBalanceException) {
                        $ctx = $innerE->getContext();
                        $shortage = $ctx['shortage'] ?? ($amount - $this->walletService->getWalletInfo($userId)['available_balance']);
                        $stillFrozenItems[] = ['order_id' => $orderId, 'order_no' => $order['order_no'], 'title' => $order['title'], 'amount' => $amount, 'shortage' => $shortage, 'suggest_recharge' => intval(ceil($shortage)), 'reason' => sprintf('支付时余额发生变化，还差 %.2f 元', $shortage)];
                    } else {
                        $failedItems[] = ['order_id' => $orderId, 'order_no' => $order['order_no'], 'title' => $order['title'], 'amount' => $amount, 'shortage' => 0, 'reason' => $innerE->getMessage()];
                    }
                }
            } catch (Exception $e) {
                $failedItems[] = ['order_id' => $orderId, 'order_no' => $order['order_no'] ?? '', 'title' => $order['title'] ?? '', 'amount' => $amount, 'shortage' => 0, 'reason' => $e->getMessage()];
            }
        }
        $walletFinal = $this->walletService->getWalletInfo($userId);
        $totalFrozenAmount = array_reduce($stillFrozenItems, function ($sum, $item) { return $sum + floatval($item['amount']); }, 0);
        $totalShortageAmount = array_reduce($stillFrozenItems, function ($sum, $item) { return $sum + floatval($item['shortage']); }, 0);
        return [
            'success_count' => count($successIds),
            'failed_count' => count($failedItems),
            'frozen_count' => count($stillFrozenItems),
            'total_count' => count($orderIds),
            'success_ids' => $successIds,
            'success_orders' => $successOrders,
            'failed_items' => $failedItems,
            'still_frozen_items' => $stillFrozenItems,
            'total_frozen_amount' => $totalFrozenAmount,
            'total_still_frozen_amount' => $totalShortageAmount,
            'suggest_total_recharge' => $totalShortageAmount > 0 ? intval(ceil($totalShortageAmount)) : 0,
            'available_balance' => $walletFinal['available_balance'],
            'wallet' => $walletFinal,
            'message' => (count($successIds) > 0 || count($stillFrozenItems) > 0 ? sprintf('批量补款处理完成，成功 %d 个，待补款 %d 个', count($successIds), count($stillFrozenItems)) : '批量补款未成功处理任何订单'),
        ];
    }
}

class OrderNotFoundException extends Exception {
    protected $context;
    public function __construct($message = '', $code = 0, $context = []) {
        parent::__construct($message, $code);
        $this->context = is_array($context) ? $context : [];
    }
    public function getContext() { return $this->context; }
}

class OrderPermissionDeniedException extends Exception {
    protected $context;
    public function __construct($message = '', $code = 0, $context = []) {
        parent::__construct($message, $code);
        $this->context = is_array($context) ? $context : [];
    }
    public function getContext() { return $this->context; }
}

class OrderStateException extends Exception {
    protected $context;
    public function __construct($message = '', $code = 0, $context = []) {
        parent::__construct($message, $code);
        $this->context = is_array($context) ? $context : [];
    }
    public function getContext() { return $this->context; }
}
