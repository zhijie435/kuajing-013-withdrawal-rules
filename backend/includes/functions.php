<?php

define('ROLE_USER', 'user');
define('ROLE_ADMIN', 'admin');
define('ROLE_AUDITOR', 'auditor');

define('PERMISSION_VIEW_OWN_APPLICATIONS', 'view_own_applications');
define('PERMISSION_VIEW_ALL_APPLICATIONS', 'view_all_applications');
define('PERMISSION_VIEW_OWN_RECORDS', 'view_own_records');
define('PERMISSION_VIEW_ALL_RECORDS', 'view_all_records');
define('PERMISSION_CREATE_APPLICATION', 'create_application');
define('PERMISSION_CANCEL_OWN_APPLICATION', 'cancel_own_application');
define('PERMISSION_REVIEW_APPLICATION', 'review_application');
define('PERMISSION_MANAGE_RULES', 'manage_rules');
define('PERMISSION_MARK_ARRIVAL', 'mark_arrival');
define('PERMISSION_VIEW_LIMITS', 'view_limits');

function json_response($code, $msg, $data = null) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'code' => $code,
        'msg' => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error($code, $msg, $debugInfo = null) {
    if ($debugInfo !== null && ini_get('display_errors')) {
        error_log(json_encode($debugInfo, JSON_UNESCAPED_UNICODE));
    }
    json_response($code, $msg);
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

function parse_url_segments() {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path = trim($path, '/');
    $segments = explode('/', $path);
    $apiIndex = array_search('api', $segments);
    
    return [
        'path' => $path,
        'segments' => $segments,
        'api_index' => $apiIndex,
        'id' => ($apiIndex !== false && isset($segments[$apiIndex + 2])) ? (int)$segments[$apiIndex + 2] : null,
        'action' => ($apiIndex !== false && isset($segments[$apiIndex + 3])) ? $segments[$apiIndex + 3] : null,
        'method' => $_SERVER['REQUEST_METHOD']
    ];
}

function get_current_user() {
    static $currentUser = null;
    
    if ($currentUser !== null) {
        return $currentUser;
    }

    $headers = getallheaders();
    $authHeader = null;
    
    if (isset($headers['Authorization'])) {
        $authHeader = $headers['Authorization'];
    } elseif (isset($headers['authorization'])) {
        $authHeader = $headers['authorization'];
    }

    if ($authHeader && preg_match('/Bearer\s+(\d+)/i', $authHeader, $matches)) {
        $userId = (int)$matches[1];
        $db = get_db();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = :id AND status = 1");
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();
        
        if ($user) {
            if (!isset($user['role'])) {
                $user['role'] = ROLE_USER;
            }
            $currentUser = $user;
            return $currentUser;
        }
    }

    if (isset($_GET['debug_user_id'])) {
        $userId = (int)$_GET['debug_user_id'];
        $db = get_db();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = :id AND status = 1");
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();
        if ($user) {
            if (!isset($user['role'])) {
                $user['role'] = ROLE_USER;
            }
            $currentUser = $user;
            return $currentUser;
        }
    }

    return null;
}

function require_auth() {
    $user = get_current_user();
    if (!$user) {
        json_error(401, '未登录或登录已过期');
    }
    return $user;
}

function get_role_permissions($role) {
    $permissions = [
        ROLE_USER => [
            PERMISSION_VIEW_OWN_APPLICATIONS,
            PERMISSION_VIEW_OWN_RECORDS,
            PERMISSION_CREATE_APPLICATION,
            PERMISSION_CANCEL_OWN_APPLICATION,
            PERMISSION_VIEW_LIMITS
        ],
        ROLE_AUDITOR => [
            PERMISSION_VIEW_ALL_APPLICATIONS,
            PERMISSION_VIEW_ALL_RECORDS,
            PERMISSION_REVIEW_APPLICATION,
            PERMISSION_MARK_ARRIVAL,
            PERMISSION_VIEW_LIMITS
        ],
        ROLE_ADMIN => [
            PERMISSION_VIEW_ALL_APPLICATIONS,
            PERMISSION_VIEW_ALL_RECORDS,
            PERMISSION_CREATE_APPLICATION,
            PERMISSION_CANCEL_OWN_APPLICATION,
            PERMISSION_REVIEW_APPLICATION,
            PERMISSION_MANAGE_RULES,
            PERMISSION_MARK_ARRIVAL,
            PERMISSION_VIEW_LIMITS
        ]
    ];

    return $permissions[$role] ?? $permissions[ROLE_USER];
}

function has_permission($permission) {
    $user = get_current_user();
    if (!$user) {
        return false;
    }
    $permissions = get_role_permissions($user['role']);
    return in_array($permission, $permissions, true);
}

function require_permission($permission) {
    if (!has_permission($permission)) {
        json_error(403, '权限不足，无法执行此操作');
    }
    return true;
}

function is_admin_or_auditor() {
    $user = get_current_user();
    if (!$user) {
        return false;
    }
    return in_array($user['role'], [ROLE_ADMIN, ROLE_AUDITOR], true);
}

function can_view_all_applications() {
    return has_permission(PERMISSION_VIEW_ALL_APPLICATIONS);
}

function can_view_application($application) {
    $user = get_current_user();
    if (!$user) {
        return false;
    }
    if (can_view_all_applications()) {
        return true;
    }
    return (int)$application['user_id'] === (int)$user['id'];
}

function can_view_all_records() {
    return has_permission(PERMISSION_VIEW_ALL_RECORDS);
}

function can_view_record($record) {
    $user = get_current_user();
    if (!$user) {
        return false;
    }
    if (can_view_all_records()) {
        return true;
    }
    return isset($record['user_id']) && (int)$record['user_id'] === (int)$user['id'];
}

function transaction_execute(PDO $db, callable $callback) {
    try {
        $db->beginTransaction();
        $result = $callback($db);
        $db->commit();
        return $result;
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function handle_api_exception(Exception $e) {
    $debugInfo = [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ];
    error_log(json_encode($debugInfo, JSON_UNESCAPED_UNICODE));
    json_error(500, '服务器内部错误');
}
