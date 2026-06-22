<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = trim($path, '/');
$segments = explode('/', $path);

$apiIndex = array_search('api', $segments);
$route = ($apiIndex !== false && isset($segments[$apiIndex + 1])) ? $segments[$apiIndex + 1] : '';

$routes = [
    'rules' => __DIR__ . '/rules.php',
    'applications' => __DIR__ . '/applications.php',
    'reviews' => __DIR__ . '/reviews.php',
    'records' => __DIR__ . '/records.php'
];

if (isset($routes[$route])) {
    require $routes[$route];
} else {
    http_response_code(404);
    echo json_encode(['code' => 404, 'msg' => '接口不存在', 'data' => null], JSON_UNESCAPED_UNICODE);
    exit;
}
