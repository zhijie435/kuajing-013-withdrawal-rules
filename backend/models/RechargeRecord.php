<?php

require_once __DIR__ . '/BaseModel.php';

class RechargeRecord extends BaseModel {
    protected $table = 'recharge_records';
    const STATUS_PENDING = 'pending';
    const STATUS_SUCCESS = 'success';
    const STATUS_FAILED = 'failed';

    public function getListByUserId($userId, $limit = 20) {
        return $this->findAll(['user_id' => $userId], 'created_at DESC', $limit);
    }
}
