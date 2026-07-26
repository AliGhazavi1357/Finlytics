<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tehran');

define('API_ROOT', dirname(__DIR__));
define('PROJECT_ROOT', dirname(API_ROOT));

function resolve_data_dir(): string
{
    $candidates = [
        PROJECT_ROOT . DIRECTORY_SEPARATOR . 'data',
        API_ROOT . DIRECTORY_SEPARATOR . 'data',
        PROJECT_ROOT . DIRECTORY_SEPARATOR . 'tmp_data',
        sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'finlytics_data',
    ];
    $errors = [];
    foreach ($candidates as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            $errors[] = $dir . ' (mkdir failed)';
            continue;
        }
        $probe = $dir . DIRECTORY_SEPARATOR . '.write_test';
        if (@file_put_contents($probe, 'ok') === false) {
            $errors[] = $dir . ' (not writable)';
            continue;
        }
        @unlink($probe);
        foreach (['uploads', 'voice'] as $sub) {
            $p = $dir . DIRECTORY_SEPARATOR . $sub;
            if (!is_dir($p)) {
                @mkdir($p, 0777, true);
            }
        }
        return $dir;
    }
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'detail' => 'پوشه data قابل نوشتن نیست. در File Manager روی data دسترسی Modify/Write بدهید.',
        'tried' => $errors,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

define('DATA_DIR', resolve_data_dir());
define('UPLOADS_DIR', DATA_DIR . DIRECTORY_SEPARATOR . 'uploads');
define('VOICE_DIR', DATA_DIR . DIRECTORY_SEPARATOR . 'voice');
define('DB_PATH', DATA_DIR . DIRECTORY_SEPARATOR . 'finlytics.db');

try {
    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
} catch (Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'detail' => 'ساخت دیتابیس ممکن نشد.',
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  phone TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  full_name TEXT NOT NULL DEFAULT 'مدیر سیستم',
  is_active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS auth_tokens (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  token TEXT NOT NULL UNIQUE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS departments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE
);
CREATE TABLE IF NOT EXISTS employees (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE,
  full_name TEXT NOT NULL,
  role TEXT NOT NULL,
  department_id INTEGER NOT NULL REFERENCES departments(id),
  monthly_salary REAL NOT NULL,
  hire_date TEXT NOT NULL,
  is_active INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE IF NOT EXISTS products_services (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  kind TEXT NOT NULL,
  unit_price REAL NOT NULL,
  unit_cost REAL NOT NULL,
  is_active INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE IF NOT EXISTS sales (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  item_id INTEGER NOT NULL REFERENCES products_services(id),
  sale_date TEXT NOT NULL,
  quantity REAL NOT NULL,
  unit_price REAL NOT NULL,
  unit_cost REAL NOT NULL,
  revenue REAL NOT NULL,
  cost REAL NOT NULL,
  profit REAL NOT NULL,
  channel TEXT NOT NULL DEFAULT 'فروش مستقیم',
  note TEXT
);
CREATE TABLE IF NOT EXISTS transactions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  txn_date TEXT NOT NULL,
  direction TEXT NOT NULL,
  category TEXT NOT NULL,
  title TEXT NOT NULL,
  amount REAL NOT NULL,
  source TEXT NOT NULL DEFAULT 'manual',
  reference TEXT,
  note TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS payrolls (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  employee_id INTEGER NOT NULL REFERENCES employees(id),
  period_year INTEGER NOT NULL,
  period_month INTEGER NOT NULL,
  base_salary REAL NOT NULL,
  bonus REAL NOT NULL DEFAULT 0,
  deductions REAL NOT NULL DEFAULT 0,
  net_pay REAL NOT NULL,
  paid_on TEXT
);
CREATE TABLE IF NOT EXISTS voice_reports (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  report_date TEXT NOT NULL,
  script_text TEXT NOT NULL,
  audio_path TEXT,
  duration_hint TEXT NOT NULL DEFAULT '~60s',
  generation_mode TEXT NOT NULL DEFAULT 'php-text',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS ai_questions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id),
  question TEXT NOT NULL,
  answer TEXT NOT NULL,
  mode TEXT NOT NULL DEFAULT 'rules',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_tx_date ON transactions(txn_date);
CREATE INDEX IF NOT EXISTS idx_sales_date ON sales(sale_date);
SQL);

require __DIR__ . '/helpers.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/reports.php';
require __DIR__ . '/edge_tts.php';
require __DIR__ . '/tts.php';
require __DIR__ . '/handlers.php';
require __DIR__ . '/seed.php';

seed_default_user($pdo);
seed_demo_data($pdo);
ensure_today_financial_data($pdo);
