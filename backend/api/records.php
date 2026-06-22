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
    if ($apiIndex !== false && isset($segments[$apiIndex + 2])) {
        $urlId = (int)$segments[$apiIndex + 2];
    }

    if ($method === 'GET') {
        $page = $_GET['page'] ?? 1;
        $pageSize = $_GET['page_size'] ?? 10;
        $status = $_GET['status'] ?? null;
        $transactionNo = $_GET['transaction_no'] ?? null;
        $startDate = $_GET['start_date'] ?? null;
        $endDate = $_GET['end_date'] ?? null;

        if ($urlId) {
            $query = "SELECT r.*, a.user_id, a.amount AS app_amount, a.fee AS app_fee, a.actual_amount AS app_actual_amount,
                             a.bank_name, a.bank_account, a.account_name, a.status AS app_status,
                             a.review_remark, a.reviewer_id, a.reviewed_at,
                             u.username, u.real_name
                      FROM withdrawal_records r
                      LEFT JOIN withdrawal_applications a ON r.application_id = a.id
                      LEFT JOIN users u ON a.user_id = u.id
                      WHERE r.id = :id";
            $stmt = $db->prepare($query);
            $stmt->execute([':id' => $urlId]);
            $detail = $stmt->fetch();
            if (!$detail) {
                json_response(400, '记录不存在');
            }
            json_response(0, 'success', $detail);
        }

        $query = "SELECT r.*, a.user_id, u.username, u.real_name, a.bank_name, a.bank_account, a.account_name " .
            "FROM withdrawal_records r " .
            "LEFT JOIN withdrawal_applications a ON r.application_id = a.id " .
            "LEFT JOIN users u ON a.user_id = u.id WHERE 1=1";
        $params = [];
        if ($status !== null && $status !== '') {
            $query .= " AND r.status = :status";
            $params[':status'] = $status;
        }
        if ($transactionNo !== null && $transactionNo !== '') {
            $query .= " AND r.transaction_no LIKE :tno";
            $params[':tno'] = '%' . $transactionNo . '%';
        }
        if ($startDate !== null && $startDate !== '') {
            $query .= " AND DATE(r.created_at) >= :start_date";
            $params[':start_date'] = $startDate;
        }
        if ($endDate !== null && $endDate !== '') {
            $query .= " AND DATE(r.created_at) <= :end_date";
            $params[':end_date'] = $endDate;
        }
        $query .= " ORDER BY r.id DESC";

        $result = paginate($query, $page, $pageSize, $db, $params);
        json_response(0, 'success', $result);
    } elseif ($method === 'PUT') {
        $input = get_input();
        $id = $urlId ? $urlId : (isset($input['id']) ? (int)$input['id'] : 0);
        if (!$id) {
            json_response(400, '参数缺失：记录ID为必填项');
        }
        if (!isset($input['status'])) {
            json_response(400, '参数缺失：状态为必填项');
        }
        $status = $input['status'];

        if (!in_array($status, ['success', 'failed'], true)) {
            json_response(400, '状态值无效');
        }

        $db->beginTransaction();

        $recStmt = $db->prepare("SELECT * FROM withdrawal_records WHERE id = :id FOR UPDATE");
        $recStmt->execute([':id' => $id]);
        $rec = $recStmt->fetch();
        if (!$rec) {
            $db->rollBack();
            json_response(400, '记录不存在');
        }
        if ($rec['status'] !== 'processing') {
            $db->rollBack();
            json_response(400, '只有处理中的记录可以更新');
        }

        $appStmt = $db->prepare("SELECT * FROM withdrawal_applications WHERE id = :aid FOR UPDATE");
        $appStmt->execute([':aid' => $rec['application_id']]);
        $app = $appStmt->fetch();
        if (!$app) {
            $db->rollBack();
            json_response(400, '关联的提现申请不存在');
        }

        if ($status === 'success') {
            $upRecStmt = $db->prepare("UPDATE withdrawal_records SET status = 'success', arrived_at = NOW(), updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $upRecStmt->execute([':id' => $id]);

            $upAppStmt = $db->prepare("UPDATE withdrawal_applications SET status = 'completed', updated_at = CURRENT_TIMESTAMP WHERE id = :aid");
            $upAppStmt->execute([':aid' => $rec['application_id']]);
        } else {
            if (!validate_required($input, ['fail_reason'])) {
                $db->rollBack();
                json_response(400, '参数缺失：失败原因为必填项');
            }
            $upRecStmt = $db->prepare("UPDATE withdrawal_records SET status = 'failed', fail_reason = :fail_reason, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $upRecStmt->execute([':id' => $id, ':fail_reason' => $input['fail_reason']]);

            $upAppStmt = $db->prepare("UPDATE withdrawal_applications SET status = 'failed', review_remark = CONCAT(IFNULL(review_remark,''), ' | 到账失败：', :reason), updated_at = CURRENT_TIMESTAMP WHERE id = :aid");
            $upAppStmt->execute([':aid' => $rec['application_id'], ':reason' => $input['fail_reason']]);

            $refundStmt = $db->prepare("UPDATE users SET balance = balance + :amount WHERE id = :uid");
            $refundStmt->execute([':amount' => $app['amount'], ':uid' => $app['user_id']]);
        }

        $db->commit();
        json_response(0, '更新记录成功');
    } else {
        json_response(405, '不支持的请求方法');
    }
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    json_response(500, '服务器错误：' . $e->getMessage());
}
