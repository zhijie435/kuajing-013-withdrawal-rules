<?php
require_once __DIR__ . '/../includes/functions.php';

try {
    $db = get_db();
    $method = $_SERVER['REQUEST_METHOD'];
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path = trim($path, '/');
    $segments = explode('/', $path);
    $apiIndex = array_search('api', $segments);
    $urlId = null;
    $urlAction = null;
    if ($apiIndex !== false) {
        if (isset($segments[$apiIndex + 2])) {
            $urlId = (int)$segments[$apiIndex + 2];
        }
        if (isset($segments[$apiIndex + 3])) {
            $urlAction = $segments[$apiIndex + 3];
        }
    }

    if ($method === 'GET') {
        if ($urlId) {
            $query = "SELECT a.*, u.username, u.real_name, r.rule_name, r.min_amount, r.max_amount, r.daily_limit, r.fee_rate, r.fee_min, r.fee_max
                      FROM withdrawal_applications a
                      LEFT JOIN users u ON a.user_id = u.id
                      LEFT JOIN withdrawal_rules r ON a.rule_id = r.id
                      WHERE a.id = :id";
            $stmt = $db->prepare($query);
            $stmt->execute([':id' => $urlId]);
            $detail = $stmt->fetch();
            if (!$detail) {
                json_response(400, '申请不存在');
            }
            json_response(0, 'success', $detail);
        }

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
        if ($urlId && $urlAction === 'cancel') {
            $id = $urlId;
        } else {
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

            $ruleId = isset($input['rule_id']) ? (int)$input['rule_id'] : 0;
            if ($ruleId > 0) {
                $ruleStmt = $db->prepare("SELECT * FROM withdrawal_rules WHERE id = :rid AND status = 1");
                $ruleStmt->execute([':rid' => $ruleId]);
                $rule = $ruleStmt->fetch();
            }
            if (!$ruleId || !$rule) {
                $ruleStmt = $db->prepare("SELECT * FROM withdrawal_rules WHERE status = 1 AND min_amount <= :amount ORDER BY min_amount DESC LIMIT 1");
                $ruleStmt->execute([':amount' => $amount]);
                $rule = $ruleStmt->fetch();
            }
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
            if (floatval($rule['fee_min']) > 0 && $fee < floatval($rule['fee_min'])) {
                $fee = floatval($rule['fee_min']);
            }
            if (floatval($rule['fee_max']) > 0 && $fee > floatval($rule['fee_max'])) {
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
        }
    } elseif ($method === 'PUT') {
        $input = get_input();

        if ($urlId && in_array($urlAction, ['approve', 'reject', 'cancel'], true)) {
            $id = $urlId;
        } else {
            $id = $urlId ? $urlId : (isset($input['id']) ? (int)$input['id'] : 0);
        }
        if (!$id) {
            json_response(400, '参数缺失：id为必填项');
        }

        $appStmt = $db->prepare("SELECT * FROM withdrawal_applications WHERE id = :id");
        $appStmt->execute([':id' => $id]);
        $app = $appStmt->fetch();
        if (!$app) {
            json_response(400, '申请不存在');
        }

        if ($urlAction === 'approve') {
            if ($app['status'] !== 'pending' && $app['status'] !== 'reviewing') {
                json_response(400, '该申请当前状态不允许审核通过');
            }
            $reviewerId = isset($input['reviewer_id']) ? (int)$input['reviewer_id'] : 0;
            $remark = $input['review_remark'] ?? ($input['remark'] ?? '');

            $db->beginTransaction();
            $upStmt = $db->prepare("UPDATE withdrawal_applications SET status = 'approved', reviewer_id = :rid, reviewed_at = NOW(), review_remark = :remark WHERE id = :id");
            $upStmt->execute([':rid' => $reviewerId, ':remark' => $remark, ':id' => $id]);

            $logStmt = $db->prepare("INSERT INTO review_logs (application_id, reviewer_id, action, remark) VALUES (:aid, :rid, 'approve', :remark)");
            $logStmt->execute([':aid' => $id, ':rid' => $reviewerId, ':remark' => $remark]);

            $txnNo = generate_transaction_no();
            $recStmt = $db->prepare("INSERT INTO withdrawal_records (application_id, transaction_no, amount, status) VALUES (:aid, :tno, :amount, 'processing')");
            $recStmt->execute([':aid' => $id, ':tno' => $txnNo, ':amount' => $app['actual_amount']]);
            $db->commit();
            json_response(0, '审核通过成功', ['record_id' => $db->lastInsertId(), 'transaction_no' => $txnNo]);
        }

        if ($urlAction === 'reject') {
            if ($app['status'] !== 'pending' && $app['status'] !== 'reviewing') {
                json_response(400, '该申请当前状态不允许审核拒绝');
            }
            $reviewerId = isset($input['reviewer_id']) ? (int)$input['reviewer_id'] : 0;
            $remark = $input['review_remark'] ?? ($input['remark'] ?? '');

            $db->beginTransaction();
            $upStmt = $db->prepare("UPDATE withdrawal_applications SET status = 'rejected', reviewer_id = :rid, reviewed_at = NOW(), review_remark = :remark WHERE id = :id");
            $upStmt->execute([':rid' => $reviewerId, ':remark' => $remark, ':id' => $id]);

            $logStmt = $db->prepare("INSERT INTO review_logs (application_id, reviewer_id, action, remark) VALUES (:aid, :rid, 'reject', :remark)");
            $logStmt->execute([':aid' => $id, ':rid' => $reviewerId, ':remark' => $remark]);

            $backStmt = $db->prepare("UPDATE users SET balance = balance + :amount WHERE id = :id");
            $backStmt->execute([':amount' => $app['amount'], ':id' => $app['user_id']]);
            $db->commit();
            json_response(0, '审核拒绝成功');
        }

        if ($urlAction === 'cancel' || (!$urlAction && $app['status'] === 'pending')) {
            if ($app['status'] !== 'pending') {
                json_response(400, '只有待审核的申请可以取消');
            }

            $db->beginTransaction();
            $upStmt = $db->prepare("UPDATE users SET balance = balance + :amount WHERE id = :id");
            $upStmt->execute([':amount' => $app['amount'], ':id' => $app['user_id']]);
            $cancelStmt = $db->prepare("UPDATE withdrawal_applications SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $cancelStmt->execute([':id' => $id]);
            $db->commit();
            json_response(0, '取消申请成功');
        }

        json_response(400, '无效的操作或当前状态不允许变更');
    } else {
        json_response(405, '不支持的请求方法');
    }
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    json_response(500, '服务器错误：' . $e->getMessage());
}
