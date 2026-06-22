<?php
require_once __DIR__ . '/../includes/functions.php';

try {
    $db = get_db();
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $page = $_GET['page'] ?? 1;
        $pageSize = $_GET['page_size'] ?? 10;
        $status = $_GET['status'] ?? null;
        $userId = $_GET['user_id'] ?? null;

        $query = "SELECT a.*, u.username, u.real_name, r.rule_name " .
            "FROM withdrawal_applications a " .
            "LEFT JOIN users u ON a.user_id = u.id " .
            "LEFT JOIN withdrawal_rules r ON a.rule_id = r.id WHERE 1=1";
        $params = [];
        if ($status !== null && $status !== '') {
            $query .= " AND a.status = :status";
            $params[':status'] = $status;
        }
        if ($userId !== null && $userId !== '') {
            $query .= " AND a.user_id = :user_id";
            $params[':user_id'] = (int)$userId;
        }
        $query .= " ORDER BY a.id DESC";

        $result = paginate($query, $page, $pageSize, $db, $params);
        json_response(0, 'success', $result);
    } elseif ($method === 'POST') {
        $input = get_input();
        if (!validate_required($input, ['user_id', 'amount', 'bank_name', 'bank_account', 'account_name'])) {
            json_response(400, '参数缺失：用户ID、金额、银行信息为必填项');
        }
        $userId = (int)$input['user_id'];
        $amount = floatval($input['amount']);
        if ($amount <= 0) {
            json_response(400, '提现金额必须大于0');
        }

        $userStmt = $db->prepare("SELECT * FROM users WHERE id = :id AND status = 1");
        $userStmt->execute([':id' => $userId]);
        $user = $userStmt->fetch();
        if (!$user) {
            json_response(400, '用户不存在或已禁用');
        }
        if ($user['balance'] < $amount) {
            json_response(400, '用户余额不足');
        }

        $ruleStmt = $db->prepare("SELECT * FROM withdrawal_rules WHERE status = 1 AND min_amount <= :amount ORDER BY min_amount DESC LIMIT 1");
        $ruleStmt->execute([':amount' => $amount]);
        $rule = $ruleStmt->fetch();
        if (!$rule) {
            json_response(400, '没有匹配的提现规则');
        }
        if ($amount < $rule['min_amount'] || $amount > $rule['max_amount']) {
            json_response(400, '提现金额不在规则范围内');
        }
        if ($rule['daily_limit'] > 0) {
            $sumStmt = $db->prepare("SELECT COALESCE(SUM(amount),0) AS total FROM withdrawal_applications WHERE user_id = :uid AND DATE(created_at) = CURDATE() AND status != 'cancelled'");
            $sumStmt->execute([':uid' => $userId]);
            $todayTotal = floatval($sumStmt->fetchColumn());
            if ($todayTotal + $amount > $rule['daily_limit']) {
                json_response(400, '超过今日提现限额');
            }
        }

        $fee = $amount * floatval($rule['fee_rate']);
        if ($fee < floatval($rule['fee_min'])) {
            $fee = floatval($rule['fee_min']);
        }
        if ($rule['fee_max'] > 0 && $fee > floatval($rule['fee_max'])) {
            $fee = floatval($rule['fee_max']);
        }
        $actualAmount = $amount - $fee;

        $db->beginTransaction();
        $upStmt = $db->prepare("UPDATE users SET balance = balance - :amount WHERE id = :id");
        $upStmt->execute([':amount' => $amount, ':id' => $userId]);

        $insStmt = $db->prepare("INSERT INTO withdrawal_applications (user_id, amount, fee, actual_amount, bank_name, bank_account, account_name, rule_id, status) VALUES (:user_id, :amount, :fee, :actual_amount, :bank_name, :bank_account, :account_name, :rule_id, 'pending')");
        $insStmt->execute([
            ':user_id' => $userId,
            ':amount' => $amount,
            ':fee' => $fee,
            ':actual_amount' => $actualAmount,
            ':bank_name' => $input['bank_name'],
            ':bank_account' => $input['bank_account'],
            ':account_name' => $input['account_name'],
            ':rule_id' => $rule['id']
        ]);
        $db->commit();
        json_response(0, '申请提交成功', ['id' => $db->lastInsertId()]);
    } elseif ($method === 'PUT') {
        $input = get_input();
        if (!validate_required($input, ['id'])) {
            json_response(400, '参数缺失：id为必填项');
        }
        $id = (int)$input['id'];
        $appStmt = $db->prepare("SELECT * FROM withdrawal_applications WHERE id = :id");
        $appStmt->execute([':id' => $id]);
        $app = $appStmt->fetch();
        if (!$app) {
            json_response(400, '申请不存在');
        }
        if ($app['status'] !== 'pending') {
            json_response(400, '只有待审核的申请可以取消');
        }

        $db->beginTransaction();
        $upStmt = $db->prepare("UPDATE users SET balance = balance + :amount WHERE id = :id");
        $upStmt->execute([':amount' => $app['amount'], ':id' => $app['user_id']]);
        $cancelStmt = $db->prepare("UPDATE withdrawal_applications SET status = 'cancelled' WHERE id = :id");
        $cancelStmt->execute([':id' => $id]);
        $db->commit();
        json_response(0, '取消申请成功');
    } else {
        json_response(405, '不支持的请求方法');
    }
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    json_response(500, '服务器错误：' . $e->getMessage());
}
