<?php
require_once __DIR__ . '/../includes/functions.php';

try {
    $db = get_db();
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $page = $_GET['page'] ?? 1;
        $pageSize = $_GET['page_size'] ?? 10;
        $status = $_GET['status'] ?? 'pending';

        $query = "SELECT a.*, u.username, u.real_name, r.rule_name " .
            "FROM withdrawal_applications a " .
            "LEFT JOIN users u ON a.user_id = u.id " .
            "LEFT JOIN withdrawal_rules r ON a.rule_id = r.id WHERE 1=1";
        $params = [];
        if ($status !== null && $status !== '') {
            $query .= " AND a.status = :status";
            $params[':status'] = $status;
        }
        $query .= " ORDER BY a.id DESC";

        $result = paginate($query, $page, $pageSize, $db, $params);
        json_response(0, 'success', $result);
    } elseif ($method === 'POST') {
        $input = get_input();
        if (!validate_required($input, ['application_id', 'reviewer_id', 'action'])) {
            json_response(400, '参数缺失：申请ID、审核人ID、操作为必填项');
        }
        $appId = (int)$input['application_id'];
        $reviewerId = (int)$input['reviewer_id'];
        $action = $input['action'];
        $remark = $input['remark'] ?? '';

        if (!in_array($action, ['approve', 'reject'], true)) {
            json_response(400, '操作类型无效');
        }

        $appStmt = $db->prepare("SELECT * FROM withdrawal_applications WHERE id = :id");
        $appStmt->execute([':id' => $appId]);
        $app = $appStmt->fetch();
        if (!$app) {
            json_response(400, '申请不存在');
        }
        if ($app['status'] !== 'pending' && $app['status'] !== 'reviewing') {
            json_response(400, '该申请当前状态不允许审核');
        }

        $db->beginTransaction();
        if ($action === 'approve') {
            $upStmt = $db->prepare("UPDATE withdrawal_applications SET status = 'approved', reviewer_id = :rid, reviewed_at = NOW(), review_remark = :remark WHERE id = :id");
            $upStmt->execute([':rid' => $reviewerId, ':remark' => $remark, ':id' => $appId]);

            $logStmt = $db->prepare("INSERT INTO review_logs (application_id, reviewer_id, action, remark) VALUES (:aid, :rid, 'approve', :remark)");
            $logStmt->execute([':aid' => $appId, ':rid' => $reviewerId, ':remark' => $remark]);

            $txnNo = generate_transaction_no();
            $recStmt = $db->prepare("INSERT INTO withdrawal_records (application_id, transaction_no, amount, status) VALUES (:aid, :tno, :amount, 'processing')");
            $recStmt->execute([':aid' => $appId, ':tno' => $txnNo, ':amount' => $app['actual_amount']]);
        } else {
            $upStmt = $db->prepare("UPDATE withdrawal_applications SET status = 'rejected', reviewer_id = :rid, reviewed_at = NOW(), review_remark = :remark WHERE id = :id");
            $upStmt->execute([':rid' => $reviewerId, ':remark' => $remark, ':id' => $appId]);

            $logStmt = $db->prepare("INSERT INTO review_logs (application_id, reviewer_id, action, remark) VALUES (:aid, :rid, 'reject', :remark)");
            $logStmt->execute([':aid' => $appId, ':rid' => $reviewerId, ':remark' => $remark]);

            $backStmt = $db->prepare("UPDATE users SET balance = balance + :amount WHERE id = :id");
            $backStmt->execute([':amount' => $app['amount'], ':id' => $app['user_id']]);
        }
        $db->commit();
        json_response(0, '审核完成');
    } else {
        json_response(405, '不支持的请求方法');
    }
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    json_response(500, '服务器错误：' . $e->getMessage());
}
