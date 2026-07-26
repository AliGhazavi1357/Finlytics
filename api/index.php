<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

if (isset($_GET['path'])) {
    $path = trim((string) $_GET['path'], '/');
} else {
    $uri = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    if (preg_match('#/api/?(.*)$#', $uri, $m)) {
        $path = trim($m[1], '/');
    } else {
        $path = trim($uri, '/');
    }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
// IIS اغلب Authorization را حذف می‌کند؛ X-Auth-Token جایگزین مطمئن است
$authorization = $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? $_SERVER['HTTP_X_AUTH_TOKEN']
    ?? null;
if (!$authorization && function_exists('getallheaders')) {
    $headers = getallheaders();
    if (is_array($headers)) {
        foreach ($headers as $key => $value) {
            $k = strtolower((string) $key);
            if ($k === 'authorization' || $k === 'x-auth-token') {
                $authorization = $value;
                break;
            }
        }
    }
}
if ($authorization && stripos((string) $authorization, 'Bearer ') !== 0
    && strpos((string) $authorization, ' ') === false) {
    $authorization = 'Bearer ' . $authorization;
}

if (!is_public_route($path)) {
    $user = get_user_by_token($pdo, $authorization);
    if (!$user) {
        json_error('لطفاً وارد سامانه شوید', 401);
    }
} else {
    $user = null;
}

try {
    if ($method === 'GET' && $path === 'version') {
        handle_version();
    } elseif ($method === 'POST' && ($path === 'auth/login' || $path === 'login')) {
        handle_login($pdo, read_json_body());
    } elseif ($method === 'GET' && $path === 'me') {
        handle_me($user);
    } elseif ($method === 'GET' && $path === 'settings/limits') {
        handle_limits();
    } elseif ($method === 'GET' && $path === 'dashboard') {
        $period = (string) ($_GET['period'] ?? 'monthly');
        if (!in_array($period, ['daily', 'monthly', 'yearly'], true)) {
            json_error('بازه نامعتبر', 400);
        }
        json_response(fin_build_dashboard($pdo, $period));
    } elseif ($method === 'GET' && preg_match('#^reports/(daily|monthly|yearly)$#', $path, $m)) {
        json_response(fin_build_period_report($pdo, $m[1]));
    } elseif ($method === 'GET' && $path === 'transactions') {
        handle_list_transactions($pdo);
    } elseif ($method === 'POST' && $path === 'transactions') {
        handle_create_transaction($pdo, read_json_body());
    } elseif ($method === 'PUT' && preg_match('#^transactions/(\d+)$#', $path, $m)) {
        handle_update_transaction($pdo, (int) $m[1], read_json_body());
    } elseif ($method === 'DELETE' && preg_match('#^transactions/(\d+)$#', $path, $m)) {
        handle_delete_transaction($pdo, (int) $m[1]);
    } elseif ($method === 'GET' && $path === 'products') {
        handle_list_products($pdo);
    } elseif ($method === 'POST' && $path === 'products') {
        handle_create_product($pdo, read_json_body());
    } elseif ($method === 'PUT' && preg_match('#^products/(\d+)$#', $path, $m)) {
        handle_update_product($pdo, (int) $m[1], read_json_body());
    } elseif ($method === 'DELETE' && preg_match('#^products/(\d+)$#', $path, $m)) {
        handle_delete_product($pdo, (int) $m[1]);
    } elseif ($method === 'GET' && $path === 'sales') {
        handle_list_sales($pdo);
    } elseif ($method === 'POST' && $path === 'sales') {
        handle_create_sale($pdo, read_json_body());
    } elseif ($method === 'DELETE' && preg_match('#^sales/(\d+)$#', $path, $m)) {
        handle_delete_sale($pdo, (int) $m[1]);
    } elseif ($method === 'GET' && $path === 'employees') {
        handle_list_employees($pdo);
    } elseif ($method === 'PUT' && preg_match('#^employees/(\d+)$#', $path, $m)) {
        handle_update_employee($pdo, (int) $m[1], read_json_body());
    } elseif ($method === 'GET' && $path === 'excel/template') {
        handle_excel_template();
    } elseif ($method === 'POST' && $path === 'excel/import') {
        handle_excel_import($pdo);
    } elseif ($method === 'POST' && $path === 'voice/daily') {
        handle_voice_daily($pdo);
    } elseif ($method === 'GET' && preg_match('#^voice/audio/(\d+)$#', $path, $m)) {
        handle_voice_audio($pdo, (int) $m[1]);
    } elseif ($method === 'GET' && $path === 'ai/quota') {
        handle_ai_quota($pdo, $user);
    } elseif ($method === 'GET' && $path === 'ai/history') {
        handle_ai_history($pdo, $user);
    } elseif ($method === 'POST' && $path === 'ai/ask') {
        handle_ai_ask($pdo, $user, read_json_body());
    } else {
        json_error('مسیر یافت نشد: ' . $path, 404);
    }
} catch (InvalidArgumentException $e) {
    json_error($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('Finlytics API error: ' . $e->getMessage());
    json_error('خطای داخلی سرور: ' . $e->getMessage(), 500);
}
