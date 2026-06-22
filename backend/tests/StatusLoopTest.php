<?php

require_once __DIR__ . '/BaseTestCase.php';

class StatusLoopTest extends BaseTestCase {

    private function assertOrderStatus($orderId, $expectedStatus) {
        $row = TestHelper::rawFetch($this->pdo, "SELECT status FROM orders WHERE id = ?", [$orderId]);
        $this->assertNotNull($row, "订单ID={$orderId}不存在");
        $this->assertEqual($expectedStatus, $row['status'], "订单ID={$orderId}状态应为{$expectedStatus}，实际为{$row['status']}");
    }

    public function testFullStatusLoopCreateFrozenRechargeRetryPaidComplete() {
        $create = $this->orderService->createOrder(2, 800, '完整闭环订单');
        $orderId = $create['order']['id'];
        $this->assertOrderStatus($orderId, Order::STATUS_FROZEN);

        $detailFrozen = $this->orderService->getOrderDetail($orderId, 2);
        $this->assertTrue($detailFrozen['can_retry']);
        $this->assertTrue($detailFrozen['can_pay']);
        $this->assertTrue($detailFrozen['can_cancel']);
        $this->assertFalse($detailFrozen['can_complete']);

        $this->walletService->recharge(2, 500);
        $partial = $this->orderService->retryPayment($orderId, 2);
        $this->assertFalse($partial['success']);
        $this->assertTrue($partial['frozen']);
        $this->assertEqual(300, $partial['shortage']);
        $this->assertOrderStatus($orderId, Order::STATUS_FROZEN);

        $this->walletService->recharge(2, 300);
        $paid = $this->orderService->retryPayment($orderId, 2);
        $this->assertTrue($paid['success']);
        $this->assertEqual(Order::STATUS_PAID, $paid['order']['status']);
        $this->assertOrderStatus($orderId, Order::STATUS_PAID);

        $detailPaid = $this->orderService->getOrderDetail($orderId, 2);
        $this->assertFalse($detailPaid['can_retry']);
        $this->assertFalse($detailPaid['can_pay']);
        $this->assertFalse($detailPaid['can_cancel']);
        $this->assertTrue($detailPaid['can_complete']);

        $completed = $this->orderService->completeOrder($orderId, 2);
        $this->assertEqual(Order::STATUS_COMPLETED, $completed['status']);
        $this->assertOrderStatus($orderId, Order::STATUS_COMPLETED);
    }

    public function testStatusLoopRechargeAndRetryDirectToPaid() {
        $create = $this->orderService->createOrder(3, 450, '一键充值支付订单');
        $orderId = $create['order']['id'];
        $this->assertOrderStatus($orderId, Order::STATUS_FROZEN);

        $oneStep = $this->orderService->rechargeAndRetry($orderId, 3, 450);
        $this->assertTrue($oneStep['success']);
        $this->assertEqual(Order::STATUS_PAID, $oneStep['order']['status']);
        $this->assertOrderStatus($orderId, Order::STATUS_PAID);

        $walletPaid = $this->walletService->getWalletInfo(3);
        $this->assertEqual(0, $walletPaid['balance']);

        $completed = $this->orderService->completeOrder($orderId, 3);
        $this->assertEqual(Order::STATUS_COMPLETED, $completed['status']);
    }

    public function testStatusLoopUnfreezeBackToPendingThenPay() {
        $create = $this->orderService->createOrder(2, 250, '解冻后再支付');
        $orderId = $create['order']['id'];
        $this->assertOrderStatus($orderId, Order::STATUS_FROZEN);

        $unfrozen = $this->orderService->unfreezeOrder($orderId, 2);
        $this->assertEqual(Order::STATUS_PENDING, $unfrozen['status']);
        $this->assertOrderStatus($orderId, Order::STATUS_PENDING);

        $pendingDetail = $this->orderService->getOrderDetail($orderId, 2);
        $this->assertFalse($pendingDetail['can_retry']);
        $this->assertTrue($pendingDetail['can_pay']);
        $this->assertTrue($pendingDetail['can_cancel']);
        $this->assertFalse($pendingDetail['can_complete']);

        $processBefore = $this->orderService->processPayment($orderId, 2);
        $this->assertFalse($processBefore['success']);
        $this->assertTrue($processBefore['frozen'], '余额不足应重新冻结');
        $this->assertOrderStatus($orderId, Order::STATUS_FROZEN);

        $this->walletService->recharge(2, 250);
        $processAfter = $this->orderService->processPayment($orderId, 2);
        $this->assertTrue($processAfter['success'], '充值后processPayment应成功');
        $this->assertEqual(Order::STATUS_PAID, $processAfter['order']['status']);
        $this->assertOrderStatus($orderId, Order::STATUS_PAID);
    }

    public function testStatusLoopCancelAsEndPoint() {
        $frozenCreate = $this->orderService->createOrder(2, 100, '冻结取消订单');
        $frozenId = $frozenCreate['order']['id'];

        $cancelled1 = $this->orderService->cancelOrder($frozenId, 2);
        $this->assertEqual(Order::STATUS_CANCELLED, $cancelled1['status']);
        $this->assertOrderStatus($frozenId, Order::STATUS_CANCELLED);

        $orderModel = new Order();
        $pendingId = $orderModel->insert([
            'order_no' => 'ORD' . date('YmdHis') . mt_rand(1000, 9999),
            'user_id' => 1,
            'amount' => 30,
            'title' => 'pending取消订单',
            'status' => Order::STATUS_PENDING,
        ]);
        $cancelled2 = $this->orderService->cancelOrder($pendingId, 1);
        $this->assertEqual(Order::STATUS_CANCELLED, $cancelled2['status']);

        $this->assertException(
            function () use ($frozenId) {
                $this->orderService->retryPayment($frozenId, 2);
            },
            'OrderStateException',
            2501,
            '已取消retry应抛异常'
        );
        $this->assertException(
            function () use ($frozenId) {
                $this->orderService->processPayment($frozenId, 2);
            },
            'OrderStateException',
            2201,
            '已取消processPayment应抛异常'
        );
        $this->assertException(
            function () use ($frozenId) {
                $this->orderService->completeOrder($frozenId, 2);
            },
            'OrderStateException',
            2901,
            '已取消complete应抛异常'
        );

        $paidCreate = $this->orderService->createOrder(1, 50, 'completed终态订单');
        $paidId = $paidCreate['order']['id'];
        $this->orderService->completeOrder($paidId, 1);
        $this->assertOrderStatus($paidId, Order::STATUS_COMPLETED);

        $this->assertException(
            function () use ($paidId) {
                $this->orderService->cancelOrder($paidId, 1);
            },
            'OrderStateException',
            2701,
            'completed不能cancel'
        );
    }

    public function testStatusLoopBatchRetryPaymentPartialSuccess() {
        $o50 = $this->orderService->createOrder(3, 50, '订单50');
        $o150 = $this->orderService->createOrder(3, 150, '订单150');
        $o300 = $this->orderService->createOrder(3, 300, '订单300');
        $o500 = $this->orderService->createOrder(3, 500, '订单500');
        $ids = [$o50['order']['id'], $o150['order']['id'], $o300['order']['id'], $o500['order']['id']];

        $this->walletService->recharge(3, 500);
        $batch1 = $this->orderService->batchRetryPayment($ids, 3);
        $this->assertEqual(4, $batch1['total_count']);
        $this->assertEqual(3, $batch1['success_count'], '50+150+300=500，3个成功');
        $this->assertEqual(1, $batch1['frozen_count'], '500的还差500');

        $paidIds = $batch1['success_ids'];
        foreach ($paidIds as $pid) {
            $this->assertOrderStatus($pid, Order::STATUS_PAID);
        }
        $stillFrozen = $batch1['still_frozen_items'];
        $this->assertCount(1, $stillFrozen);
        $this->assertEqual(500, $stillFrozen[0]['amount']);

        $this->walletService->recharge(3, 500);
        $batch2 = $this->orderService->batchRetryPayment($ids, 3);
        $this->assertEqual(4, $batch2['total_count']);
        $this->assertEqual(4, $batch2['success_count'], '第2批4个都成功(含已paid的3个)');
        $this->assertEqual(0, $batch2['frozen_count']);

        foreach ($ids as $oid) {
            $this->assertOrderStatus($oid, Order::STATUS_PAID);
        }
    }

    public function testStatusLoopPaidAtTimestampSet() {
        $direct = $this->orderService->createOrder(1, 50, '直接支付订单');
        $this->assertTrue($direct['success']);
        $row1 = TestHelper::rawFetch($this->pdo, "SELECT paid_at, status FROM orders WHERE id = ?", [$direct['order']['id']]);
        $this->assertNotNull($row1['paid_at'], '直接支付应有paid_at');
        $this->assertEqual(Order::STATUS_PAID, $row1['status']);

        $frozen = $this->orderService->createOrder(2, 300, '冻结后支付');
        $orderId = $frozen['order']['id'];
        $rowFrozen = TestHelper::rawFetch($this->pdo, "SELECT paid_at, status FROM orders WHERE id = ?", [$orderId]);
        $this->assertNull($rowFrozen['paid_at'], '冻结态paid_at为空');
        $this->assertEqual(Order::STATUS_FROZEN, $rowFrozen['status']);

        $this->walletService->recharge(2, 300);
        $paid = $this->orderService->retryPayment($orderId, 2);
        $this->assertTrue($paid['success']);
        $rowPaid = TestHelper::rawFetch($this->pdo, "SELECT paid_at, status FROM orders WHERE id = ?", [$orderId]);
        $this->assertNotNull($rowPaid['paid_at'], '支付后paid_at应有值');
        $this->assertEqual(Order::STATUS_PAID, $rowPaid['status']);
    }

    public function testStatusLoopTransactionRecordsComplete() {
        $create = $this->orderService->createOrder(2, 120, '交易记录完整验证');
        $orderId = $create['order']['id'];

        $this->orderService->rechargeAndRetry($orderId, 2, 120);
        $this->assertOrderStatus($orderId, Order::STATUS_PAID);

        $txns = TestHelper::rawFetchAll(
            $this->pdo,
            "SELECT type, amount, balance_before, balance_after FROM wallet_transactions WHERE user_id = ? ORDER BY id",
            [2]
        );

        $types = array_column($txns, 'type');
        $this->assertContains(WalletTransaction::TYPE_RECHARGE, $types);
        $this->assertContains(WalletTransaction::TYPE_FREEZE, $types);
        $this->assertContains(WalletTransaction::TYPE_PAYMENT, $types);

        $rechargeSum = 0.0;
        $paymentSum = 0.0;
        foreach ($txns as $t) {
            if ($t['type'] === WalletTransaction::TYPE_RECHARGE) {
                $rechargeSum += floatval($t['amount']);
            }
            if ($t['type'] === WalletTransaction::TYPE_PAYMENT) {
                $paymentSum += floatval($t['amount']);
            }
        }
        $this->assertEqual(120, $rechargeSum, '充值总额120');
        $this->assertEqual(120, $paymentSum, '扣款总额120');

        $wallet = $this->walletService->getWalletInfo(2);
        $this->assertEqual(0, $wallet['balance']);
    }

    public function testStatusLoopAllEndpointsAreStable() {
        $create = $this->orderService->createOrder(1, 60, 'completed订单');
        $completedId = $create['order']['id'];
        $this->orderService->completeOrder($completedId, 1);
        $this->assertOrderStatus($completedId, Order::STATUS_COMPLETED);

        $transitions = [
            [Order::STATUS_COMPLETED, 'cancelOrder', [], 2701],
            [Order::STATUS_COMPLETED, 'freezeOrder', ['已完成不能冻结'], 2301],
            [Order::STATUS_COMPLETED, 'unfreezeOrder', [], 2401],
            [Order::STATUS_COMPLETED, 'retryPayment', [], 2501],
            [Order::STATUS_COMPLETED, 'processPayment', [], 2201],
        ];

        foreach ($transitions as $t) {
            list($from, $method, $args, $code) = $t;
            $this->assertOrderStatus($completedId, $from);
            $this->assertException(
                function () use ($completedId, $method, $args) {
                    if ($method === 'cancelOrder') {
                        $this->orderService->cancelOrder($completedId, 1);
                    } elseif ($method === 'freezeOrder') {
                        $this->orderService->freezeOrder($completedId, 1, $args[0]);
                    } elseif ($method === 'unfreezeOrder') {
                        $this->orderService->unfreezeOrder($completedId, 1);
                    } elseif ($method === 'retryPayment') {
                        $this->orderService->retryPayment($completedId, 1);
                    } elseif ($method === 'processPayment') {
                        $this->orderService->processPayment($completedId, 1);
                    }
                },
                'OrderStateException',
                $code,
                "completed->{$method}应抛{$code}"
            );
            $this->assertOrderStatus($completedId, Order::STATUS_COMPLETED, "completed状态不变");
        }

        $frozenCreate = $this->orderService->createOrder(2, 25, 'cancelled订单');
        $cancelledId = $frozenCreate['order']['id'];
        $this->orderService->cancelOrder($cancelledId, 2);
        $this->assertOrderStatus($cancelledId, Order::STATUS_CANCELLED);

        $this->assertException(
            function () use ($cancelledId) {
                $this->orderService->completeOrder($cancelledId, 2);
            },
            'OrderStateException',
            2901,
            'cancelled不能complete'
        );
    }

    public function testStatusLoopDataConsistencyAtEveryStep() {
        $userId = 2;
        $amount = 777;

        $create = $this->orderService->createOrder($userId, $amount, '一致性777订单');
        $orderId = $create['order']['id'];

        $w0 = TestHelper::rawFetch($this->pdo, "SELECT balance, frozen_amount FROM wallets WHERE user_id = ?", [$userId]);
        $o0 = TestHelper::rawFetch($this->pdo, "SELECT status, amount, frozen_reason FROM orders WHERE id = ?", [$orderId]);
        $this->assertEqual(0, $w0['balance']);
        $this->assertEqual(0, $w0['frozen_amount']);
        $this->assertEqual(Order::STATUS_FROZEN, $o0['status']);
        $this->assertEqual($amount, floatval($o0['amount']));
        $this->assertNotNull($o0['frozen_reason']);

        $this->walletService->recharge($userId, 400);
        $w1 = TestHelper::rawFetch($this->pdo, "SELECT balance, frozen_amount FROM wallets WHERE user_id = ?", [$userId]);
        $this->assertEqual(400, floatval($w1['balance']));
        $this->assertEqual(0, floatval($w1['frozen_amount']));
        $rechargeTxn1 = TestHelper::rawFetch(
            $this->pdo,
            "SELECT id, amount FROM wallet_transactions WHERE user_id = ? AND type = ? ORDER BY id DESC LIMIT 1",
            [$userId, WalletTransaction::TYPE_RECHARGE]
        );
        $this->assertEqual(400, floatval($rechargeTxn1['amount']));

        $r1 = $this->orderService->rechargeAndRetry($orderId, $userId, 377);
        $this->assertTrue($r1['success']);
        $this->assertEqual(Order::STATUS_PAID, $r1['order']['status']);

        $w2 = TestHelper::rawFetch($this->pdo, "SELECT balance, frozen_amount FROM wallets WHERE user_id = ?", [$userId]);
        $o2 = TestHelper::rawFetch($this->pdo, "SELECT status, paid_at, frozen_reason FROM orders WHERE id = ?", [$orderId]);
        $this->assertEqual(0, floatval($w2['balance']), '400+377-777=0');
        $this->assertEqual(0, floatval($w2['frozen_amount']));
        $this->assertEqual(Order::STATUS_PAID, $o2['status']);
        $this->assertNotNull($o2['paid_at']);
        $this->assertNull($o2['frozen_reason']);

        $this->orderService->completeOrder($orderId, $userId);
        $o3 = TestHelper::rawFetch($this->pdo, "SELECT status FROM orders WHERE id = ?", [$orderId]);
        $this->assertEqual(Order::STATUS_COMPLETED, $o3['status']);

        $walletFinal = $this->walletService->getWalletInfo($userId);
        $this->assertEqual(0, $walletFinal['balance']);
        $this->assertEqual(0, $walletFinal['frozen_amount']);
        $this->assertEqual(0, $walletFinal['available_balance']);
    }
}
