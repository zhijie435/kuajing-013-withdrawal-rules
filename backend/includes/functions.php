<?php

function json_response($code, $msg, $data = null) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'code' => $code,
        'msg' => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function get_db() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = require __DIR__ . '/../config/database.php';
    }
    return $pdo;
}

function get_input() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $data = $_POST;
    }
    return $data;
}

function generate_transaction_no() {
    return 'TX' . date('YmdHis') . str_pad((string)mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
}

function paginate($query, $page, $pageSize, $db, $params = []) {
    $page = max(1, (int)$page);
    $pageSize = max(1, (int)$pageSize);
    $offset = ($page - 1) * $pageSize;

    $countQuery = "SELECT COUNT(*) FROM ({$query}) AS sub";
    $countStmt = $db->prepare($countQuery);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $listQuery = $query . " LIMIT " . (int)$offset . ", " . (int)$pageSize;
    $listStmt = $db->prepare($listQuery);
    $listStmt->execute($params);
    $list = $listStmt->fetchAll();
    return [
        'list' => $list,
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize
    ];
}

function validate_required($data, $fields) {
    foreach ($fields as $field) {
        if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
            return false;
        }
    }
    return true;
}
