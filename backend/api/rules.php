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
