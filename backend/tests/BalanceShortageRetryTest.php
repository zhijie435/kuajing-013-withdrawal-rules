<?php

require_once __DIR__ . '/BaseTestCase.php';

class BalanceShortageRetryTest extends BaseTestCase {

    public function testCreateOrderInsufficientBalanceShouldFreezeOrder() {
        $result = $this->orderService->createOrder(2, 500, '测试订单500', '用户2余额0');
        $this->assertFalse($result['success'], '创建订单应该因余额不足而失败');
        $this->assertTrue($result['frozen'], '订单应该被冻结');
        $this->assertEqual(500, $result['shortage'], '差额应为500');
        $this->assertEqual(Order::STATUS_FROZEN, $result['order']['status'], '状态应为frozen');
        $this->assertEqual(500, $result['suggest_recharge'], '建议充值应为500');

        $wallet = $this->walletService->getWalletInfo(2);
        $this->assertEqual(0, $wallet['balance'], '余额仍为0');
    }

    public function testFreezeOrderThenRechargeThenRetrySuccess() {
        $createResult = $this->orderService->createOrder(3, 300, '300元订单', '用户3余额0');
        $this->assertTrue($createResult['frozen']);
        $orderId = $createResult['order']['id'];

        $this->walletService->recharge(3, 500);
        $walletAfter = $this->walletService->getWalletInfo(3);
        $this->assertEqual(500, $walletAfter['balance']);

        $retryResult = $this->orderService->retryPayment($orderId, 3);
        $this->assertTrue($retryResult['success'], '重试应该成功');
        $this->assertEqual(Order::STATUS_PAID, $retryResult['order']['status']);

        $walletFinal = $this->walletService->getWalletInfo(3);
        $this->assertEqual(200, $walletFinal['balance'], '支付后余额应为200');
        $this->assertEqual(200, $walletFinal['available_balance']);
    }

    public function testPartialRechargeStillShortageThenMoreRechargeThenSuccess() {
        $createResult = $this->orderService->createOrder(2, 1000, '千元订单', '1000元');
        $orderId = $createResult['order']['id'];
        $this->assertEqual(1000, $createResult['shortage']);

        $this->walletService->recharge(2, 300);
        $retry1 = $this->orderService->retryPayment($orderId, 2);
        $this->assertFalse($retry1['success']);
        $this->assertTrue($retry1['frozen']);
        $this->assertEqual(700, $retry1['shortage'], '还差700');

        $this->walletService->recharge(2, 500);
        $retry2 = $this->orderService->retryPayment($orderId, 2);
        $this->assertFalse($retry2['success']);
        $this->assertEqual(200, $retry2['shortage'], '还差200');

        $this->walletService->recharge(2, 200);
        $retry3 = $this->orderService->retryPayment($orderId, 2);
        $this->assertTrue($retry3['success'], '第三次应该成功');
        $this->assertEqual(Order::STATUS_PAID, $retry3['order']['status']);

        $wallet = $this->walletService->getWalletInfo(2);
        $this->assertEqual(0, $wallet['balance'], '全部用于支付');
    }

    public function testRechargeAndRetryOneStepWithSufficientAmount() {
        $createResult = $this->orderService->createOrder(3, 500, '500元订单');
        $orderId = $createResult['order']['id'];
        $this->assertTrue($createResult['frozen']);

        $result = $this->orderService->rechargeAndRetry($orderId, 3, 600);
        $this->assertTrue($result['success'], '一键充值重试应成功');
        $this->assertEqual(Order::STATUS_PAID, $result['order']['status']);
        $this->assertNotNull($result['recharge'], '应返回充值记录');

        $wallet = $this->walletService->getWalletInfo(3);
        $this->assertEqual(100, $wallet['balance'], '余额剩100');
        $this->assertEqual(100, $wallet['available_balance']);
    }

    public function testRechargeAndRetryOneStepWithInsufficientAmount() {
        $createResult = $this->orderService->createOrder(2, 1000, '千元订单');
        $orderId = $createResult['order']['id'];

        $step1 = $this->orderService->rechargeAndRetry($orderId, 2, 300);
        $this->assertFalse($step1['success']);
        $this->assertTrue($step1['frozen']);
        $this->assertEqual(700, $step1['shortage']);
        $this->assertNotNull($step1['recharge'], '充值记录应存在');

        $wallet = $this->walletService->getWalletInfo(2);
        $this->assertEqual(300, $wallet['balance']);

        $step2 = $this->orderService->rechargeAndRetry($orderId, 2, 700);
        $this->assertTrue($step2['success'], '第二次充值后应成功');
        $this->assertEqual(Order::STATUS_PAID, $step2['order']['status']);
    }

    public function testProcessPaymentOnFrozenOrderAfterBalanceChange() {
        $createResult = $this->orderService->createOrder(2, 200, '200元订单');
        $orderId = $createResult['order']['id'];
        $this->assertTrue($createResult['frozen']);

        $beforeRecharge = $this->orderService->processPayment($orderId, 2);
        $this->assertFalse($beforeRecharge['success']);
        $this->assertEqual(200, $beforeRecharge['shortage']);

        $this->walletService->recharge(2, 200);

        $afterRecharge = $this->orderService->processPayment($orderId, 2);
        $this->assertTrue($afterRecharge['success'], '充值后processPayment应成功');
        $this->assertEqual(Order::STATUS_PAID, $afterRecharge['order']['status']);
    }

    public function testRetryPaymentNonFrozenOrderShouldThrowException() {
        $createResult = $this->orderService->createOrder(1, 50, '用户1足余额订单');
        $orderId = $createResult['order']['id'];
        $this->assertTrue($createResult['success'], '用户1余额100应可直接支付');
        $this->assertEqual(Order::STATUS_PAID, $createResult['order']['status']);

        $this->assertException(
            function () use ($orderId) {
                $this->orderService->retryPayment($orderId, 1);
            },
            'OrderStateException',
            2501,
            '非冻结态retry应抛OrderStateException 2501'
        );
    }

    public function testMultipleFrozenOrdersSequentialRetry() {
        $o1 = $this->orderService->createOrder(3, 100, '订单A100');
        $o2 = $this->orderService->createOrder(3, 200, '订单B200');
        $o3 = $this->orderService->createOrder(3, 300, '订单C300');

        $this->assertTrue($o1['frozen']);
        $this->assertTrue($o2['frozen']);
        $this->assertTrue($o3['frozen']);

        $this->walletService->recharge(3, 600);

        $r1 = $this->orderService->retryPayment($o1['order']['id'], 3);
        $this->assertTrue($r1['success']);
        $this->assertEqual(Order::STATUS_PAID, $r1['order']['status']);

        $walletAfter1 = $this->walletService->getWalletInfo(3);
        $this->assertEqual(500, $walletAfter1['balance']);

        $r2 = $this->orderService->retryPayment($o2['order']['id'], 3);
        $this->assertTrue($r2['success']);

        $walletAfter2 = $this->walletService->getWalletInfo(3);
        $this->assertEqual(300, $walletAfter2['balance']);

        $r3 = $this->orderService->retryPayment($o3['order']['id'], 3);
        $this->assertTrue($r3['success']);

        $walletFinal = $this->walletService->getWalletInfo(3);
        $this->assertEqual(0, $walletFinal['balance'], '600-100-200-300=0');
    }

    public function testGetOrderDetailShowsCorrectShortageAndActions() {
        $createResult = $this->orderService->createOrder(2, 250, '250元订单');
        $orderId = $createResult['order']['id'];

        $detail1 = $this->orderService->getOrderDetail($orderId, 2);
        $this->assertEqual(250, $detail1['shortage']);
        $this->assertTrue($detail1['can_retry'], '冻结且不足时can_retry为true');
        $this->assertTrue($detail1['can_pay']);
        $this->assertTrue($detail1['can_cancel']);
        $this->assertFalse($detail1['can_complete']);
        $this->assertFalse($detail1['has_enough_balance']);

        $this->walletService->recharge(2, 250);

        $detail2 = $this->orderService->getOrderDetail($orderId, 2);
        $this->assertEqual(0, $detail2['shortage']);
        $this->assertFalse($detail2['can_retry'], '足额时can_retry应为false');
        $this->assertTrue($detail2['has_enough_balance']);
    }

    public function testFrozenSummaryAggregation() {
        $this->orderService->createOrder(3, 100, '订单100');
        $this->orderService->createOrder(3, 200.5, '订单200.5');
        $this->orderService->createOrder(3, 99.99, '订单99.99');

        $summary1 = $this->orderService->getFrozenSummary(3);
        $this->assertEqual(3, $summary1['total_count']);
        $this->assertEqual(3, $summary1['need_recharge_count']);
        $this->assertEqual(0, $summary1['payable_count']);
        $expectedTotal = 100 + 200.5 + 99.99;
        $this->assertEqual($expectedTotal, $summary1['total_amount']);
        $this->assertEqual($expectedTotal, $summary1['shortage']);

        $this->walletService->recharge(3, 150);

        $summary2 = $this->orderService->getFrozenSummary(3);
        $this->assertEqual(3, $summary2['total_count']);
        $this->assertGreaterThan(0, $summary2['payable_count'], '充150后至少1单可付');
        $this->assertLessThan(3, $summary2['need_recharge_count']);
    }
}
