<?php
require_once __DIR__ . '/../includes/functions.php';

try {
    $db = get_db();
    $segments = parse_url_segments();
    $urlId = $segments['id'];
    $urlAction = $segments['action'];
    $method = $segments['method'];

    $currentUser = require_auth();

    if ($method === 'GET') {
        handle_get_request($db, $urlId, $currentUser);
    } elseif ($method === 'POST') {
        handle_post_request($db, $urlId, $urlAction, $currentUser);
    } elseif ($method === 'PUT') {
        handle_put_request($db, $urlId, $urlAction, $currentUser);
    } elseif ($method === 'DELETE') {
        json_error(405, '不支持的请求方法');
    } else {
        json_error(405, '不支持的请求方法');
    }
} catch (Exception $e) {
    handle_api_exception($e);
}

function handle_get_request($db, $urlId, $currentUser) {
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
            json_error(400, '申请不存在');
        }

        if (!can_view_application($detail)) {
            json_error(403, '权限不足，无法查看该申请');
        }

        json_response(0, 'success', $detail);
    }

    require_permission(can_view_all_applications() ? PERMISSION_VIEW_ALL_APPLICATIONS : PERMISSION_VIEW_OWN_APPLICATIONS);

    $page = $_GET['page'] ?? 1;
    $pageSize = $_GET['page_size'] ?? 10;
    $status = $_GET['status'] ?? null;
    $userId = $_GET['user_id'] ?? null;

    $query = "SELECT a.*, u.username, u.real_name, r.rule_name " .
        "FROM withdrawal_applications a " .
        "LEFT JOIN users u ON a.user_id = u.id " .
        "LEFT JOIN withdrawal_rules r ON a.rule_id = r.id WHERE 1=1";
    $params = [];

    if (!can_view_all_applications()) {
        $query .= " AND a.user_id = :current_user_id";
        $params[':current_user_id'] = (int)$currentUser['id'];
    } elseif ($userId !== null && $userId !== '') {
        $query .= " AND a.user_id = :user_id";
        $params[':user_id'] = (int)$userId;
    }

    if ($status !== null && $status !== '') {
        $query .= " AND a.status = :status";
        $params[':status'] = $status;
    }

    $query .= " ORDER BY a.id DESC";

    $result = paginate($query, $page, $pageSize, $db, $params);
    json_response(0, 'success', $result);
}

function handle_post_request($db, $urlId, $urlAction, $currentUser) {
    if ($urlId && $urlAction === 'cancel') {
        handle_cancel_application($db, $urlId, $currentUser);
        return;
    }

    require_permission(PERMISSION_CREATE_APPLICATION);

    $input = get_input();
    if (!validate_required($input, ['user_id', 'amount', 'bank_name', 'bank_account', 'account_name'])) {
        json_error(400, '参数缺失：用户ID、金额、银行信息为必填项');
    }

    $userId = (int)$input['user_id'];
    $amount = floatval($input['amount']);

    if ($amount <= 0) {
        json_error(400, '提现金额必须大于0');
    }

    if (!is_admin_or_auditor() && $userId !== (int)$currentUser['id']) {
        json_error(403, '权限不足，只能为自己创建提现申请');
    }

    $userStmt = $db->prepare("SELECT * FROM users WHERE id = :id AND status = 1");
    $userStmt->execute([':id' => $userId]);
    $user = $userStmt->fetch();

    if (!$user) {
        json_error(400, '用户不存在或已禁用');
    }

    if ($user['balance'] < $amount) {
        json_error(400, '用户余额不足');
    }

    $rule = get_matching_rule($db, $input, $amount);
    if (!$rule) {
        json_error(400, '没有匹配的提现规则');
    }

    if ($amount < $rule['min_amount'] || $amount > $rule['max_amount']) {
        json_error(400, '提现金额不在规则范围内');
    }

    if ($rule['daily_limit'] > 0) {
        $sumStmt = $db->prepare("SELECT COALESCE(SUM(amount),0) AS total FROM withdrawal_applications WHERE user_id = :uid AND DATE(created_at) = CURDATE() AND status != 'cancelled'");
        $sumStmt->execute([':uid' => $userId]);
        $todayTotal = floatval($sumStmt->fetchColumn());
        if ($todayTotal + $amount > $rule['daily_limit']) {
            json_error(400, '超过今日提现限额');
        }
    }

    $fee = calculate_fee($amount, $rule);
    $actualAmount = $amount - $fee;

    $result = transaction_execute($db, function($db) use ($userId, $amount, $fee, $actualAmount, $input, $rule) {
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

        return ['id' => $db->lastInsertId()];
    });

    json_response(0, '申请提交成功', $result);
}

function handle_put_request($db, $urlId, $urlAction, $currentUser) {
    $input = get_input();
    $id = $urlId ? $urlId : (isset($input['id']) ? (int)$input['id'] : 0);

    if (!$id) {
        json_error(400, '参数缺失：id为必填项');
    }

    $appStmt = $db->prepare("SELECT * FROM withdrawal_applications WHERE id = :id");
    $appStmt->execute([':id' => $id]);
    $app = $appStmt->fetch();

    if (!$app) {
        json_error(400, '申请不存在');
    }

    if ($urlAction === 'approve' || $urlAction === 'reject') {
        handle_review_application($db, $app, $urlAction, $input, $currentUser);
        return;
    }

    if ($urlAction === 'cancel') {
        handle_cancel_application($db, $id, $currentUser);
        return;
    }

    json_error(400, '无效的操作或当前状态不允许变更');
}

function handle_review_application($db, $app, $action, $input, $currentUser) {
    require_permission(PERMISSION_REVIEW_APPLICATION);

    if ($app['status'] !== 'pending' && $app['status'] !== 'reviewing') {
        json_error(400, '该申请当前状态不允许审核');
    }

    $reviewerId = (int)$currentUser['id'];
    $remark = $input['review_remark'] ?? ($input['remark'] ?? '');

    if ($action === 'reject' && trim($remark) === '') {
        json_error(400, '审核拒绝必须填写拒绝原因');
    }

    $result = transaction_execute($db, function($db) use ($app, $action, $reviewerId, $remark) {
        $status = $action === 'approve' ? 'approved' : 'rejected';

        $upStmt = $db->prepare("UPDATE withdrawal_applications SET status = :status, reviewer_id = :rid, reviewed_at = NOW(), review_remark = :remark WHERE id = :id");
        $upStmt->execute([':status' => $status, ':rid' => $reviewerId, ':remark' => $remark, ':id' => $app['id']]);

        $logStmt = $db->prepare("INSERT INTO review_logs (application_id, reviewer_id, action, remark) VALUES (:aid, :rid, :action, :remark)");
        $logStmt->execute([':aid' => $app['id'], ':rid' => $reviewerId, ':action' => $action, ':remark' => $remark]);

        if ($action === 'approve') {
            $txnNo = generate_transaction_no();
            $recStmt = $db->prepare("INSERT INTO withdrawal_records (application_id, transaction_no, amount, status) VALUES (:aid, :tno, :amount, 'processing')");
            $recStmt->execute([':aid' => $app['id'], ':tno' => $txnNo, ':amount' => $app['actual_amount']]);
            return ['record_id' => $db->lastInsertId(), 'transaction_no' => $txnNo];
        } else {
            $backStmt = $db->prepare("UPDATE users SET balance = balance + :amount WHERE id = :id");
            $backStmt->execute([':amount' => $app['amount'], ':id' => $app['user_id']]);
            return null;
        }
    });

    $message = $action === 'approve' ? '审核通过成功' : '审核拒绝成功';
    json_response(0, $message, $result);
}

function handle_cancel_application($db, $id, $currentUser) {
    $appStmt = $db->prepare("SELECT * FROM withdrawal_applications WHERE id = :id");
    $appStmt->execute([':id' => $id]);
    $app = $appStmt->fetch();

    if (!$app) {
        json_error(400, '申请不存在');
    }

    if (!is_admin_or_auditor() && (int)$app['user_id'] !== (int)$currentUser['id']) {
        json_error(403, '权限不足，只能取消自己的申请');
    }

    if ($app['status'] !== 'pending') {
        json_error(400, '只有待审核的申请可以取消');
    }

    transaction_execute($db, function($db) use ($app, $id) {
        $upStmt = $db->prepare("UPDATE users SET balance = balance + :amount WHERE id = :id");
        $upStmt->execute([':amount' => $app['amount'], ':id' => $app['user_id']]);

        $cancelStmt = $db->prepare("UPDATE withdrawal_applications SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $cancelStmt->execute([':id' => $id]);
    });

    json_response(0, '取消申请成功');
}

function get_matching_rule($db, $input, $amount) {
    $ruleId = isset($input['rule_id']) ? (int)$input['rule_id'] : 0;

    if ($ruleId > 0) {
        $ruleStmt = $db->prepare("SELECT * FROM withdrawal_rules WHERE id = :rid AND status = 1");
        $ruleStmt->execute([':rid' => $ruleId]);
        $rule = $ruleStmt->fetch();
        if ($rule) {
            return $rule;
        }
    }

    $ruleStmt = $db->prepare("SELECT * FROM withdrawal_rules WHERE status = 1 AND min_amount <= :amount ORDER BY min_amount DESC LIMIT 1");
    $ruleStmt->execute([':amount' => $amount]);
    return $ruleStmt->fetch() ?: null;
}

function calculate_fee($amount, $rule) {
    $fee = $amount * floatval($rule['fee_rate']);
    if (floatval($rule['fee_min']) > 0 && $fee < floatval($rule['fee_min'])) {
        $fee = floatval($rule['fee_min']);
    }
    if (floatval($rule['fee_max']) > 0 && $fee > floatval($rule['fee_max'])) {
        $fee = floatval($rule['fee_max']);
    }
    return $fee;
}
