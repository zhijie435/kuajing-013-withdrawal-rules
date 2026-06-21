<?php

require_once __DIR__ . '/BaseModel.php';

class Order extends BaseModel {
    protected $table = 'orders';
    const STATUS_PENDING = 'pending';
    const STATUS_FROZEN = 'frozen';
    const STATUS_PAID = 'paid';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    public function findByOrderNo($orderNo) {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE order_no = :order_no");
        $stmt->execute([':order_no' => $orderNo]);
        return $stmt->fetch();
    }

    public function getListByUserId($userId, $status = null) {
        $where = ['user_id' => $userId];
        if ($status) $where['status'] = $status;
        return $this->findAll($where, 'created_at DESC');
    }

    public function getFrozenOrders($userId) {
        return $this->getListByUserId($userId, self::STATUS_FROZEN);
    }

    public function generateOrderNo() {
        return 'ORD' . date('YmdHis') . mt_rand(1000, 9999);
    }

    public function updateStatus($orderId, $status, $extraData = []) {
        $data = array_merge($extraData, ['status' => $status]);
        if ($status === self::STATUS_PAID && !isset($data['paid_at'])) {
            $data['paid_at'] = date('Y-m-d H:i:s');
        }
        return $this->update($orderId, $data);
    }
}
