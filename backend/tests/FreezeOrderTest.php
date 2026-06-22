<?php

require_once __DIR__ . '/BaseTestCase.php';

class FreezeOrderTest extends BaseTestCase {

    private function createPendingOrder($userId, $amount, $title) {
        $orderModel = new Order();
        $orderId = $orderModel->insert([
            'order_no' => 'ORD' . date('YmdHis') . mt_rand(1000, 9999),
            'user_id' => $userId,
            'amount' => $amount,
            'title' => $title,
            'description' => '',
            'status' => Order::STATUS_PENDING,
        ]);
        return $orderModel->findById($orderId);
    }

    public function testFreezePendingOrderManually() {
        $order = $this->createPendingOrder(1, 50, '待冻结订单');
        $orderId = $order['id'];

        $result = $this->orderService->freezeOrder($orderId, 1, '手动冻结');
        $this->assertFalse($result['success'], '冻结返回success=false');
        $this->assertTrue($result['frozen']);
        $this->assertEqual(Order::STATUS_FROZEN, $result['order']['status']);
        $this->assertEqual('手动冻结', $result['order']['frozen_reason']);
    }

    public function testFreezeAlreadyFrozenOrderShouldReturnShortage() {
        $order = $this->createPendingOrder(2, 500, '测试重复冻结');
        $orderId = $order['id'];

        $first = $this->orderService->freezeOrder($orderId, 2, '首次冻结');
        $this->assertTrue($first['frozen']);

        $second = $this->orderService->freezeOrder($orderId, 2);
        $this->assertTrue($second['frozen']);
        $this->assertContains('已处于冻结状态', $second['message']);
        $this->assertEqual(500, $second['shortage']);
    }

    public function testFreezeNonPendingOrderShouldThrowException() {
        $createResult = $this->orderService->createOrder(1, 50, '已支付订单');
        $orderId = $createResult['order']['id'];
        $this->assertEqual(Order::STATUS_PAID, $createResult['order']['status']);

        $this->assertException(
            function () use ($orderId) {
                $this->orderService->freezeOrder($orderId, 1, '冻结paid订单');
            },
            'OrderStateException',
            2301,
            'paid订单冻结应抛2301异常'
        );
    }

    public function testUnfreezeFrozenOrder() {
        $createResult = $this->orderService->createOrder(2, 200, '待解冻订单');
        $orderId = $createResult['order']['id'];
        $this->assertEqual(Order::STATUS_FROZEN, $createResult['order']['status']);

        $unfrozen = $this->orderService->unfreezeOrder($orderId, 2);
        $this->assertEqual(Order::STATUS_PENDING, $unfrozen['status']);
        $this->assertNull($unfrozen['frozen_reason']);

        $detail = $this->orderService->getOrderDetail($orderId, 2);
        $this->assertEqual(Order::STATUS_PENDING, $detail['order']['status']);
    }

    public function testUnfreezeNonFrozenOrderShouldThrowException() {
        $order = $this->createPendingOrder(1, 30, '待支付订单');
        $orderId = $order['id'];

        $this->assertException(
            function () use ($orderId) {
                $this->orderService->unfreezeOrder($orderId, 1);
            },
            'OrderStateException',
            2401,
            '非冻结态解冻抛异常2401'
        );
    }

    public function testCancelFrozenOrder() {
        $createResult = $this->orderService->createOrder(2, 150, '待取消冻结订单');
        $orderId = $createResult['order']['id'];

        $cancelled = $this->orderService->cancelOrder($orderId, 2);
        $this->assertEqual(Order::STATUS_CANCELLED, $cancelled['status']);

        $this->assertException(
            function () use ($orderId) {
                $this->orderService->retryPayment($orderId, 2);
            },
            'OrderStateException',
            2501,
            '取消后retry应抛异常'
        );
    }

    public function testWalletFreezeAndUnfreezeBalance() {
        $this->walletService->recharge(2, 500);
        $walletBefore = $this->walletService->getWalletInfo(2);
        $this->assertEqual(500, $walletBefore['balance']);
        $this->assertEqual(0, $walletBefore['frozen_amount']);
        $this->assertEqual(500, $walletBefore['available_balance']);

        $freezeResult = $this->walletService->freeze(2, 200, null, '测试冻结');
        $this->assertEqual(200, $freezeResult['frozen_amount']);
        $this->assertEqual(300, $freezeResult['available_after']);

        $walletAfterFreeze = $this->walletService->getWalletInfo(2);
        $this->assertEqual(500, $walletAfterFreeze['balance']);
        $this->assertEqual(200, $walletAfterFreeze['frozen_amount']);
        $this->assertEqual(300, $walletAfterFreeze['available_balance']);

        $unfreezeResult = $this->walletService->unfreeze(2, 200, null, '测试解冻');
        $this->assertEqual(0, $unfreezeResult['frozen_amount']);
        $this->assertEqual(500, $unfreezeResult['available_after']);

        $walletFinal = $this->walletService->getWalletInfo(2);
        $this->assertEqual(500, $walletFinal['balance']);
        $this->assertEqual(0, $walletFinal['frozen_amount']);
        $this->assertEqual(500, $walletFinal['available_balance']);
    }

    public function testWalletFreezeInsufficientShouldThrow() {
        $e = $this->assertException(
            function () {
                $this->walletService->freeze(1, 500, null, '超额冻结');
            },
            'InsufficientBalanceException',
            1102,
            '100元余额冻结500应抛异常'
        );
        $ctx = $e->getContext();
        $this->assertArrayHasKey('available', $ctx);
        $this->assertArrayHasKey('shortage', $ctx);
        $this->assertEqual(100, $ctx['available']);
        $this->assertEqual(400, $ctx['shortage']);
    }

    public function testWalletUnfreezeMoreThanFrozenShouldThrow() {
        $this->walletService->recharge(2, 500);
        $this->walletService->freeze(2, 20);

        $e = $this->assertException(
            function () {
                $this->walletService->unfreeze(2, 100);
            },
            'BizInvalidArgumentException',
            1202,
            '冻结20解冻100应抛异常'
        );
    }

    public function testBatchFreezeOrdersMixedStatus() {
        $p1 = $this->createPendingOrder(1, 10, 'pending订单1');
        $p2 = $this->createPendingOrder(1, 20, 'pending订单2');
        $frozen = $this->createPendingOrder(1, 30, '已冻结订单');
        $this->orderService->freezeOrder($frozen['id'], 1);
        $paid = $this->orderService->createOrder(1, 40, 'paid订单');
        $other = $this->createPendingOrder(2, 50, '用户2订单');

        $ids = [$p1['id'], $p2['id'], $frozen['id'], $paid['order']['id'], 99999, $other['id']];
        $result = $this->orderService->batchFreezeOrders($ids, 1, '批量冻结');

        $this->assertEqual(6, $result['total_count']);
        $this->assertEqual(3, $result['success_count'], '2个pending+1个已冻结=3成功');
        $this->assertEqual(1, $result['skipped_count'], '1个已冻结被跳过');
        $this->assertEqual(3, $result['failed_count'], '1paid+1不存在+1他人=3失败');

        $failedIds = array_column($result['failed_items'], 'order_id');
        $this->assertContains(99999, $failedIds);
        $this->assertContains($other['id'], $failedIds);
    }

    public function testFreezeOrderPermissionDenied() {
        $order = $this->createPendingOrder(1, 75, '用户1的订单');
        $orderId = $order['id'];

        $e = $this->assertException(
            function () use ($orderId) {
                $this->orderService->freezeOrder($orderId, 2, '用户2越权冻结');
            },
            'OrderPermissionDeniedException',
            2002,
            '用户2冻结用户1的订单应抛权限异常'
        );
        $ctx = $e->getContext();
        $this->assertEqual($orderId, $ctx['order_id']);
        $this->assertEqual(1, $ctx['order_user_id']);
        $this->assertEqual(2, $ctx['operator_id']);
    }

    public function testFrozenReasonPersistedInDatabase() {
        $order = $this->createPendingOrder(3, 88, '测试reason持久化');
        $orderId = $order['id'];

        $this->orderService->freezeOrder($orderId, 3, '特殊冻结原因ABC');
        $fromDB = TestHelper::rawFetch($this->pdo, "SELECT frozen_reason FROM orders WHERE id = ?", [$orderId]);
        $this->assertEqual('特殊冻结原因ABC', $fromDB['frozen_reason']);

        $this->orderService->unfreezeOrder($orderId, 3);
        $afterUnfreeze = TestHelper::rawFetch($this->pdo, "SELECT frozen_reason, status FROM orders WHERE id = ?", [$orderId]);
        $this->assertNull($afterUnfreeze['frozen_reason']);
        $this->assertEqual(Order::STATUS_PENDING, $afterUnfreeze['status']);
    }

    public function testFreezeAndDeductFromFrozenAtomic() {
        $this->walletService->recharge(2, 100);
        $walletBefore = $this->walletService->getWalletInfo(2);
        $this->assertEqual(100, $walletBefore['balance']);

        $freezeR = $this->walletService->freeze(2, 40, 999, '测试冻结扣款');
        $this->assertEqual(40, $freezeR['frozen_amount']);

        $deductR = $this->walletService->deductFromFrozen(2, 40, 999, '冻结扣款');
        $this->assertEqual(60, $deductR['balance_after']);
        $this->assertEqual(0, $deductR['frozen_after']);
        $this->assertEqual(60, $deductR['available_after']);

        $walletFinal = $this->walletService->getWalletInfo(2);
        $this->assertEqual(60, $walletFinal['balance']);
        $this->assertEqual(0, $walletFinal['frozen_amount']);

        $txns = TestHelper::rawFetchAll(
            $this->pdo,
            "SELECT type, amount FROM wallet_transactions WHERE user_id = ? AND order_id = 999 ORDER BY id",
            [2]
        );
        $this->assertCount(2, $txns);
        $this->assertEqual(WalletTransaction::TYPE_FREEZE, $txns[0]['type']);
        $this->assertEqual(40, $txns[0]['amount']);
        $this->assertEqual(WalletTransaction::TYPE_PAYMENT, $txns[1]['type']);
        $this->assertEqual(40, $txns[1]['amount']);
    }
}
