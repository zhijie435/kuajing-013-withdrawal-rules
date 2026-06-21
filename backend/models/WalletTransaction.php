<?php

require_once __DIR__ . '/BaseModel.php';

class WalletTransaction extends BaseModel {
    protected $table = 'wallet_transactions';
    const TYPE_RECHARGE = 'recharge';
    const TYPE_PAYMENT = 'payment';
    const TYPE_REFUND = 'refund';
    const TYPE_FREEZE = 'freeze';
    const TYPE_UNFREEZE = 'unfreeze';

    public function getListByUserId($userId, $limit = 20) {
        return $this->findAll(['user_id' => $userId], 'created_at DESC', $limit);
    }
}
