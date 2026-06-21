<?php
require_once __DIR__ . '/../includes/functions.php';

try {
    $db = get_db();
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $page = $_GET['page'] ?? 1;
        $pageSize = $_GET['page_size'] ?? 10;
        $status = $_GET['status'] ?? null;

        $query = "SELECT * FROM withdrawal_rules WHERE 1=1";
        $params = [];
        if ($status !== null && $status !== '') {
            $query .= " AND status = :status";
            $params[':status'] = (int)$status;
        }
        $query .= " ORDER BY id DESC";

        $result = paginate($query, $page, $pageSize, $db, $params);
        json_response(0, 'success', $result);
    } elseif ($method === 'POST') {
        $input = get_input();
        if (!validate_required($input, ['rule_name', 'min_amount', 'max_amount'])) {
            json_response(400, '参数缺失：规则名称、最小金额、最大金额为必填项');
        }
        $minAmount = floatval($input['min_amount']);
        $maxAmount = floatval($input['max_amount']);
        if ($minAmount >= $maxAmount) {
            json_response(400, '最小金额必须小于最大金额');
        }
        $feeRate = isset($input['fee_rate']) ? floatval($input['fee_rate']) : 0;
        if ($feeRate < 0 || $feeRate > 1) {
            json_response(400, '手续费率必须在0-1之间');
        }
        $sql = "INSERT INTO withdrawal_rules " .
            "(rule_name, min_amount, max_amount, daily_limit, fee_rate, fee_min, fee_max, status, description) " .
            "VALUES (:rule_name, :min_amount, :max_amount, :daily_limit, :fee_rate, :fee_min, :fee_max, :status, :description)";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':rule_name' => $input['rule_name'],
            ':min_amount' => $minAmount,
            ':max_amount' => $maxAmount,
            ':daily_limit' => isset($input['daily_limit']) ? floatval($input['daily_limit']) : 0,
            ':fee_rate' => $feeRate,
            ':fee_min' => isset($input['fee_min']) ? floatval($input['fee_min']) : 0,
            ':fee_max' => isset($input['fee_max']) ? floatval($input['fee_max']) : 0,
            ':status' => isset($input['status']) ? (int)$input['status'] : 1,
            ':description' => $input['description'] ?? ''
        ]);
        json_response(0, '创建规则成功', ['id' => $db->lastInsertId()]);
    } elseif ($method === 'PUT') {
        $input = get_input();
        if (!validate_required($input, ['id'])) {
            json_response(400, '参数缺失：id为必填项');
        }
        $id = (int)$input['id'];
        $minAmount = isset($input['min_amount']) ? floatval($input['min_amount']) : null;
        $maxAmount = isset($input['max_amount']) ? floatval($input['max_amount']) : null;
        if ($minAmount !== null && $maxAmount !== null && $minAmount >= $maxAmount) {
            json_response(400, '最小金额必须小于最大金额');
        }
        $feeRate = isset($input['fee_rate']) ? floatval($input['fee_rate']) : null;
        if ($feeRate !== null && ($feeRate < 0 || $feeRate > 1)) {
            json_response(400, '手续费率必须在0-1之间');
        }
        $allowed = ['rule_name', 'min_amount', 'max_amount', 'daily_limit', 'fee_rate', 'fee_min', 'fee_max', 'status', 'description'];
        $fields = [];
        $params = [':id' => $id];
        foreach ($allowed as $field) {
            if (isset($input[$field])) {
                $fields[] = "{$field} = :{$field}";
                $params[":{$field}"] = $input[$field];
            }
        }
        if (empty($fields)) {
            json_response(400, '没有需要更新的字段');
        }
        $sql = "UPDATE withdrawal_rules SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        json_response(0, '更新规则成功');
    } elseif ($method === 'DELETE') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
        if (!$id) {
            json_response(400, '参数缺失：id为必填项');
        }
        $stmt = $db->prepare("UPDATE withdrawal_rules SET status = 0 WHERE id = :id");
        $stmt->execute([':id' => $id]);
        json_response(0, '删除规则成功');
    } else {
        json_response(405, '不支持的请求方法');
    }
} catch (Exception $e) {
    json_response(500, '服务器错误：' . $e->getMessage());
}
