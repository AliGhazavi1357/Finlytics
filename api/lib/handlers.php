<?php
declare(strict_types=1);

function handle_version(): void
{
    json_response([
        'app' => 'Finlytics',
        'api' => 'php',
        'version' => '1.0.0',
        'hosting' => 'shared-plesk',
        'ok' => true,
    ]);
}

function handle_login(PDO $pdo, array $body): void
{
    $phone = (string) ($body['phone'] ?? '');
    $password = (string) ($body['password'] ?? '');
    try {
        [$user, $token] = auth_login($pdo, $phone, $password);
    } catch (InvalidArgumentException $e) {
        json_error($e->getMessage(), 401);
    }
    json_response([
        'access_token' => $token,
        'token_type' => 'bearer',
        'full_name' => $user['full_name'],
        'phone' => $user['phone'],
    ]);
}

function handle_me(array $user): void
{
    json_response(['phone' => $user['phone'], 'full_name' => $user['full_name']]);
}

function handle_limits(): void
{
    json_response([
        'max_monthly_salary' => MAX_MONTHLY_SALARY,
        'min_monthly_salary' => MIN_MONTHLY_SALARY,
        'max_transaction_amount' => MAX_TRANSACTION_AMOUNT,
        'labels' => [
            'max_monthly_salary' => 'سقف حقوق ماهانه',
            'min_monthly_salary' => 'حداقل حقوق ماهانه',
            'max_transaction_amount' => 'سقف مبلغ تراکنش',
        ],
    ]);
}

function tx_out(array $t): array
{
    return [
        'id' => (int) $t['id'],
        'txn_date' => $t['txn_date'],
        'direction' => $t['direction'],
        'category' => localize_category($t['category']),
        'title' => $t['title'],
        'amount' => (float) $t['amount'],
        'source' => $t['source'],
        'source_label' => source_label($t['source']),
        'reference' => $t['reference'],
        'note' => $t['note'],
    ];
}

function product_out(array $p): array
{
    return [
        'id' => (int) $p['id'],
        'code' => $p['code'],
        'name' => $p['name'],
        'kind' => $p['kind'],
        'kind_label' => kind_label($p['kind']),
        'unit_price' => (float) $p['unit_price'],
        'unit_cost' => (float) $p['unit_cost'],
        'is_active' => (bool) $p['is_active'],
    ];
}

function sale_out(PDO $pdo, array $s): array
{
    $st = $pdo->prepare('SELECT name FROM products_services WHERE id = ?');
    $st->execute([$s['item_id']]);
    $name = (string) ($st->fetchColumn() ?: '');
    return [
        'id' => (int) $s['id'],
        'item_id' => (int) $s['item_id'],
        'item_name' => $name,
        'sale_date' => $s['sale_date'],
        'quantity' => (float) $s['quantity'],
        'unit_price' => (float) $s['unit_price'],
        'unit_cost' => (float) $s['unit_cost'],
        'revenue' => (float) $s['revenue'],
        'cost' => (float) $s['cost'],
        'profit' => (float) $s['profit'],
        'channel' => $s['channel'],
        'note' => $s['note'],
    ];
}

function employee_out(PDO $pdo, array $e): array
{
    $st = $pdo->prepare('SELECT name FROM departments WHERE id = ?');
    $st->execute([$e['department_id']]);
    $dep = (string) ($st->fetchColumn() ?: '');
    return [
        'id' => (int) $e['id'],
        'code' => $e['code'],
        'full_name' => $e['full_name'],
        'role' => $e['role'],
        'department' => $dep,
        'monthly_salary' => (float) $e['monthly_salary'],
        'hire_date' => $e['hire_date'],
        'is_active' => (bool) $e['is_active'],
    ];
}

function validate_amount_value(float $amount, string $field = 'مبلغ'): float
{
    if ($amount <= 0) {
        json_error($field . ' باید بزرگ‌تر از صفر باشد', 400);
    }
    if ($amount > MAX_TRANSACTION_AMOUNT) {
        json_error($field . ' از سقف مجاز بیشتر است', 400);
    }
    return $amount;
}

function validate_salary_value(float $salary): float
{
    if ($salary < MIN_MONTHLY_SALARY || $salary > MAX_MONTHLY_SALARY) {
        json_error('حقوق ماهانه خارج از بازه مجاز است', 400);
    }
    return $salary;
}

function handle_list_transactions(PDO $pdo): void
{
    $direction = $_GET['direction'] ?? null;
    $limit = max(1, min(500, (int) ($_GET['limit'] ?? 100)));
    $sql = 'SELECT * FROM transactions';
    $params = [];
    if (in_array($direction, ['income', 'expense'], true)) {
        $sql .= ' WHERE direction = ?';
        $params[] = $direction;
    }
    $sql .= ' ORDER BY txn_date DESC, id DESC LIMIT ' . $limit;
    $st = $pdo->prepare($sql);
    $st->execute($params);
    json_response(array_map('tx_out', $st->fetchAll()));
}

function handle_create_transaction(PDO $pdo, array $body): void
{
    $direction = (string) ($body['direction'] ?? '');
    if (!in_array($direction, ['income', 'expense'], true)) {
        json_error('نوع تراکنش نامعتبر است', 400);
    }
    $category = trim((string) ($body['category'] ?? ''));
    $title = trim((string) ($body['title'] ?? ''));
    if ($category === '' || $title === '') {
        json_error('دسته و عنوان الزامی است', 400);
    }
    $amount = validate_amount_value(parse_number($body['amount'] ?? '', 'مبلغ'));
    $txnDate = (string) ($body['txn_date'] ?? today_str());
    $st = $pdo->prepare(
        'INSERT INTO transactions (txn_date, direction, category, title, amount, source, reference, note)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([
        $txnDate,
        $direction,
        $category,
        $title,
        $amount,
        $body['source'] ?? 'manual',
        $body['reference'] ?? null,
        $body['note'] ?? null,
    ]);
    $id = (int) $pdo->lastInsertId();
    $row = $pdo->query("SELECT * FROM transactions WHERE id = {$id}")->fetch();
    json_response(tx_out($row));
}

function handle_update_transaction(PDO $pdo, int $id, array $body): void
{
    $row = $pdo->query("SELECT * FROM transactions WHERE id = {$id}")->fetch();
    if (!$row) {
        json_error('تراکنش یافت نشد', 404);
    }
    $direction = $body['direction'] ?? $row['direction'];
    if (!in_array($direction, ['income', 'expense'], true)) {
        json_error('نوع تراکنش نامعتبر است', 400);
    }
    $category = array_key_exists('category', $body) ? trim((string) $body['category']) : $row['category'];
    $title = array_key_exists('title', $body) ? trim((string) $body['title']) : $row['title'];
    $amount = array_key_exists('amount', $body)
        ? validate_amount_value(parse_number($body['amount'], 'مبلغ'))
        : (float) $row['amount'];
    $txnDate = $body['txn_date'] ?? $row['txn_date'];
    $note = array_key_exists('note', $body) ? $body['note'] : $row['note'];
    $st = $pdo->prepare(
        'UPDATE transactions SET txn_date=?, direction=?, category=?, title=?, amount=?, note=? WHERE id=?'
    );
    $st->execute([$txnDate, $direction, $category, $title, $amount, $note, $id]);
    $row = $pdo->query("SELECT * FROM transactions WHERE id = {$id}")->fetch();
    json_response(tx_out($row));
}

function handle_delete_transaction(PDO $pdo, int $id): void
{
    $st = $pdo->prepare('DELETE FROM transactions WHERE id = ?');
    $st->execute([$id]);
    if ($st->rowCount() === 0) {
        json_error('تراکنش یافت نشد', 404);
    }
    json_response(['ok' => true]);
}

function handle_list_products(PDO $pdo): void
{
    $activeOnly = isset($_GET['active_only']) && $_GET['active_only'] !== 'false' && $_GET['active_only'] !== '0';
    $sql = 'SELECT * FROM products_services';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY code ASC';
    json_response(array_map('product_out', $pdo->query($sql)->fetchAll()));
}

function handle_create_product(PDO $pdo, array $body): void
{
    $code = trim((string) ($body['code'] ?? ''));
    $name = trim((string) ($body['name'] ?? ''));
    $kind = (string) ($body['kind'] ?? '');
    if ($code === '' || $name === '' || !in_array($kind, ['product', 'service'], true)) {
        json_error('کد، نام و نوع الزامی است', 400);
    }
    $price = parse_number($body['unit_price'] ?? 0, 'قیمت فروش');
    $cost = parse_number($body['unit_cost'] ?? 0, 'بهای تمام‌شده واحد');
    if ($cost > $price) {
        json_error('بهای تمام‌شده نباید از قیمت فروش بیشتر باشد', 400);
    }
    $exists = $pdo->prepare('SELECT id FROM products_services WHERE code = ?');
    $exists->execute([$code]);
    if ($exists->fetch()) {
        json_error('کد محصول/خدمت تکراری است', 400);
    }
    $active = array_key_exists('is_active', $body) ? ((int) (bool) $body['is_active']) : 1;
    $st = $pdo->prepare(
        'INSERT INTO products_services (code, name, kind, unit_price, unit_cost, is_active) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $st->execute([$code, $name, $kind, $price, $cost, $active]);
    $id = (int) $pdo->lastInsertId();
    json_response(product_out($pdo->query("SELECT * FROM products_services WHERE id = {$id}")->fetch()));
}

function handle_update_product(PDO $pdo, int $id, array $body): void
{
    $row = $pdo->query("SELECT * FROM products_services WHERE id = {$id}")->fetch();
    if (!$row) {
        json_error('آیتم یافت نشد', 404);
    }
    $code = array_key_exists('code', $body) ? trim((string) $body['code']) : $row['code'];
    $name = array_key_exists('name', $body) ? trim((string) $body['name']) : $row['name'];
    $kind = $body['kind'] ?? $row['kind'];
    $price = array_key_exists('unit_price', $body) ? parse_number($body['unit_price'], 'قیمت فروش') : (float) $row['unit_price'];
    $cost = array_key_exists('unit_cost', $body) ? parse_number($body['unit_cost'], 'بهای تمام‌شده واحد') : (float) $row['unit_cost'];
    $active = array_key_exists('is_active', $body) ? (int) (bool) $body['is_active'] : (int) $row['is_active'];
    if ($cost > $price) {
        json_error('بهای تمام‌شده نباید از قیمت فروش بیشتر باشد', 400);
    }
    if ($code !== $row['code']) {
        $exists = $pdo->prepare('SELECT id FROM products_services WHERE code = ?');
        $exists->execute([$code]);
        if ($exists->fetch()) {
            json_error('کد محصول/خدمت تکراری است', 400);
        }
    }
    $st = $pdo->prepare(
        'UPDATE products_services SET code=?, name=?, kind=?, unit_price=?, unit_cost=?, is_active=? WHERE id=?'
    );
    $st->execute([$code, $name, $kind, $price, $cost, $active, $id]);
    json_response(product_out($pdo->query("SELECT * FROM products_services WHERE id = {$id}")->fetch()));
}

function handle_delete_product(PDO $pdo, int $id): void
{
    $row = $pdo->query("SELECT * FROM products_services WHERE id = {$id}")->fetch();
    if (!$row) {
        json_error('آیتم یافت نشد', 404);
    }
    $has = $pdo->prepare('SELECT id FROM sales WHERE item_id = ? LIMIT 1');
    $has->execute([$id]);
    if ($has->fetch()) {
        $pdo->prepare('UPDATE products_services SET is_active = 0 WHERE id = ?')->execute([$id]);
        json_response(['ok' => true, 'soft_deleted' => true, 'message' => 'به‌خاطر سابقه فروش، غیرفعال شد']);
    }
    $pdo->prepare('DELETE FROM products_services WHERE id = ?')->execute([$id]);
    json_response(['ok' => true, 'soft_deleted' => false]);
}

function handle_list_sales(PDO $pdo): void
{
    $limit = max(1, min(500, (int) ($_GET['limit'] ?? 100)));
    $rows = $pdo->query("SELECT * FROM sales ORDER BY sale_date DESC, id DESC LIMIT {$limit}")->fetchAll();
    $out = [];
    foreach ($rows as $s) {
        $out[] = sale_out($pdo, $s);
    }
    json_response($out);
}

function sync_sale_transactions(PDO $pdo, array $sale, array $item): void
{
    $ref = $item['code'];
    $pdo->prepare("DELETE FROM transactions WHERE source IN ('sales','cogs') AND reference = ? AND txn_date = ?")
        ->execute([$ref, $sale['sale_date']]);
    $ins = $pdo->prepare(
        'INSERT INTO transactions (txn_date, direction, category, title, amount, source, reference)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $ins->execute([
        $sale['sale_date'], 'income',
        $item['kind'] === 'product' ? 'فروش محصول' : 'ارائه خدمت',
        'فروش ' . $item['name'], $sale['revenue'], 'sales', $ref,
    ]);
    $ins->execute([
        $sale['sale_date'], 'expense', 'بهای تمام‌شده کالا',
        'هزینه کالای فروخته‌شده — ' . $item['name'], $sale['cost'], 'cogs', $ref,
    ]);
}

function handle_create_sale(PDO $pdo, array $body): void
{
    $itemId = (int) ($body['item_id'] ?? 0);
    $item = $pdo->query("SELECT * FROM products_services WHERE id = {$itemId}")->fetch();
    if (!$item || !(int) $item['is_active']) {
        json_error('محصول/خدمت فعال یافت نشد', 404);
    }
    $qty = parse_number($body['quantity'] ?? 0, 'مقدار');
    if ($qty <= 0) {
        json_error('مقدار باید بزرگ‌تر از صفر باشد', 400);
    }
    $unitPrice = array_key_exists('unit_price', $body) && $body['unit_price'] !== null
        ? parse_number($body['unit_price'], 'قیمت فروش')
        : (float) $item['unit_price'];
    $unitCost = array_key_exists('unit_cost', $body) && $body['unit_cost'] !== null
        ? parse_number($body['unit_cost'], 'بهای تمام‌شده واحد')
        : (float) $item['unit_cost'];
    $revenue = round($qty * $unitPrice);
    $cost = round($qty * $unitCost);
    $st = $pdo->prepare(
        'INSERT INTO sales (item_id, sale_date, quantity, unit_price, unit_cost, revenue, cost, profit, channel, note)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $saleDate = (string) ($body['sale_date'] ?? today_str());
    $st->execute([
        $itemId, $saleDate, $qty, $unitPrice, $unitCost, $revenue, $cost, $revenue - $cost,
        $body['channel'] ?? 'فروش مستقیم', $body['note'] ?? null,
    ]);
    $id = (int) $pdo->lastInsertId();
    $sale = $pdo->query("SELECT * FROM sales WHERE id = {$id}")->fetch();
    $sync = !array_key_exists('sync_transactions', $body) || $body['sync_transactions'];
    if ($sync) {
        sync_sale_transactions($pdo, $sale, $item);
    }
    json_response(sale_out($pdo, $sale));
}

function handle_delete_sale(PDO $pdo, int $id): void
{
    $st = $pdo->prepare('DELETE FROM sales WHERE id = ?');
    $st->execute([$id]);
    if ($st->rowCount() === 0) {
        json_error('فروش یافت نشد', 404);
    }
    json_response(['ok' => true]);
}

function handle_list_employees(PDO $pdo): void
{
    $rows = $pdo->query('SELECT * FROM employees ORDER BY code ASC')->fetchAll();
    $out = [];
    foreach ($rows as $e) {
        $out[] = employee_out($pdo, $e);
    }
    json_response($out);
}

function handle_update_employee(PDO $pdo, int $id, array $body): void
{
    $row = $pdo->query("SELECT * FROM employees WHERE id = {$id}")->fetch();
    if (!$row) {
        json_error('پرسنل یافت نشد', 404);
    }
    $name = array_key_exists('full_name', $body) ? trim((string) $body['full_name']) : $row['full_name'];
    $role = array_key_exists('role', $body) ? trim((string) $body['role']) : $row['role'];
    $salary = array_key_exists('monthly_salary', $body)
        ? validate_salary_value(parse_number($body['monthly_salary'], 'حقوق'))
        : (float) $row['monthly_salary'];
    $active = array_key_exists('is_active', $body) ? (int) (bool) $body['is_active'] : (int) $row['is_active'];
    $pdo->prepare('UPDATE employees SET full_name=?, role=?, monthly_salary=?, is_active=? WHERE id=?')
        ->execute([$name, $role, $salary, $active, $id]);
    json_response(employee_out($pdo, $pdo->query("SELECT * FROM employees WHERE id = {$id}")->fetch()));
}

function ai_quota_for(PDO $pdo, array $user): array
{
    $today = today_str();
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM ai_questions WHERE user_id = ? AND substr(created_at, 1, 10) = ?'
    );
    $st->execute([$user['id'], $today]);
    $used = (int) $st->fetchColumn();
    $limit = AI_DAILY_QUESTION_LIMIT;
    return [
        'limit' => $limit,
        'used' => $used,
        'remaining' => max(0, $limit - $used),
    ];
}

function handle_ai_quota(PDO $pdo, array $user): void
{
    json_response(ai_quota_for($pdo, $user));
}

function handle_ai_history(PDO $pdo, array $user): void
{
    $st = $pdo->prepare(
        'SELECT id, question, answer, mode, created_at FROM ai_questions
         WHERE user_id = ? ORDER BY id DESC LIMIT 10'
    );
    $st->execute([$user['id']]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = [
            'id' => (int) $r['id'],
            'question' => $r['question'],
            'answer' => $r['answer'],
            'mode' => $r['mode'],
            'created_at' => $r['created_at'],
        ];
    }
    json_response($out);
}

function handle_ai_ask(PDO $pdo, array $user, array $body): void
{
    $question = trim((string) ($body['question'] ?? ''));
    if (mb_strlen($question, 'UTF-8') < 3) {
        json_error('سؤال خیلی کوتاه است', 400);
    }
    $quota = ai_quota_for($pdo, $user);
    if ($quota['remaining'] <= 0) {
        json_error('سقف پرسش روزانه به پایان رسیده است', 429);
    }
    $answer = fin_answer_question($pdo, $question);
    $pdo->prepare('INSERT INTO ai_questions (user_id, question, answer, mode, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$user['id'], $question, $answer, 'rules', today_str() . 'T' . date('H:i:s')]);
    $id = (int) $pdo->lastInsertId();
    $quota = ai_quota_for($pdo, $user);
    json_response([
        'id' => $id,
        'question' => $question,
        'answer' => $answer,
        'mode' => 'rules',
        'quota' => $quota,
    ]);
}

function handle_voice_daily(PDO $pdo): void
{
    $script = fin_voice_script($pdo);
    $today = today_str();
    $pdo->prepare(
        'INSERT INTO voice_reports (report_date, script_text, audio_path, duration_hint, generation_mode)
         VALUES (?, ?, NULL, ?, ?)'
    )->execute([$today, $script, '~60s', 'php-text']);
    $id = (int) $pdo->lastInsertId();
    json_response([
        'id' => $id,
        'report_date' => $today,
        'script_text' => $script,
        'audio_url' => null,
        'duration_hint' => '~60s',
        'generation_mode' => 'php-text',
        'created_at' => utc_now(),
    ]);
}

function handle_excel_template(): void
{
    $headers = ['تاریخ', 'نوع', 'دسته', 'عنوان', 'مبلغ', 'مرجع', 'توضیح'];
    $rows = [
        ['2026-07-20', 'income', 'فروش محصول', 'فروش لایسنس داشبورد', '28000000', 'PRD-003', 'نمونه'],
        ['2026-07-20', 'expense', 'بازاریابی', 'تبلیغات اینستاگرام', '3500000', '', 'نمونه'],
        ['2026-07-21', 'income', 'ارائه خدمت', 'پشتیبانی ماهانه', '18000000', 'SRV-002', 'نمونه'],
    ];
    $lines = [];
    $lines[] = implod_csv($headers);
    foreach ($rows as $r) {
        $lines[] = implod_csv($r);
    }
    $csv = "\xEF\xBB\xBF" . implode("\r\n", $lines);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=finlytics_transactions_template.csv');
    echo $csv;
    exit;
}

function implod_csv(array $cols): string
{
    $out = [];
    foreach ($cols as $c) {
        $c = (string) $c;
        if (str_contains($c, ',') || str_contains($c, '"') || str_contains($c, "\n")) {
            $c = '"' . str_replace('"', '""', $c) . '"';
        }
        $out[] = $c;
    }
    return implode(',', $out);
}

function normalize_direction_php(string $value): ?string
{
    $v = mb_strtolower(trim($value), 'UTF-8');
    $map = [
        'income' => 'income', 'expense' => 'expense',
        'درآمد' => 'income', 'ورودی' => 'income',
        'هزینه' => 'expense', 'خروجی' => 'expense',
    ];
    return $map[$v] ?? null;
}

function handle_excel_import(PDO $pdo): void
{
    if (empty($_FILES['file']['tmp_name'])) {
        json_error('فایل ارسال نشده است', 400);
    }
    $name = (string) ($_FILES['file']['name'] ?? '');
    $tmp = $_FILES['file']['tmp_name'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'txt', 'xlsx'], true)) {
        json_error('فقط فایل CSV یا XLSX پذیرفته می‌شود. روی هاست اشتراکی قالب CSV را دانلود کنید.', 400);
    }
    if ($ext === 'xlsx') {
        json_error('روی این هاست فایل CSV را آپلود کنید (قالب را دوباره دانلود کنید).', 400);
    }
    $raw = file_get_contents($tmp);
    if ($raw === false) {
        json_error('خواندن فایل ناموفق بود', 400);
    }
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }
    $lines = preg_split('/\r\n|\n|\r/', $raw) ?: [];
    $imported = 0;
    $skipped = 0;
    $errors = [];
    $ins = $pdo->prepare(
        'INSERT INTO transactions (txn_date, direction, category, title, amount, source, reference, note)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($lines as $i => $line) {
        if ($i === 0 || trim($line) === '') {
            if ($i === 0) {
                continue;
            }
            continue;
        }
        $cols = str_getcsv($line);
        if (count($cols) < 5) {
            $skipped++;
            $errors[] = 'سطر ' . ($i + 1) . ': ستون‌ها ناکافی';
            continue;
        }
        $dir = normalize_direction_php((string) $cols[1]);
        if (!$dir) {
            $skipped++;
            $errors[] = 'سطر ' . ($i + 1) . ': نوع نامعتبر';
            continue;
        }
        $amountRaw = to_en_digits(trim((string) $cols[4]));
        $amountRaw = str_replace(['٫', ',', '٬', ' ', "'"], ['.', '', '', '', ''], $amountRaw);
        if ($amountRaw === '' || !is_numeric($amountRaw)) {
            $skipped++;
            $errors[] = 'سطر ' . ($i + 1) . ': مبلغ نامعتبر';
            continue;
        }
        $amount = (float) $amountRaw;
        if ($amount <= 0 || $amount > MAX_TRANSACTION_AMOUNT) {
            $skipped++;
            $errors[] = 'سطر ' . ($i + 1) . ': مبلغ خارج از محدوده';
            continue;
        }
        $date = to_en_digits(trim((string) $cols[0]));
        $date = str_replace('/', '-', $date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $skipped++;
            $errors[] = 'سطر ' . ($i + 1) . ': تاریخ نامعتبر';
            continue;
        }
        $ins->execute([
            $date, $dir, trim((string) $cols[2]), trim((string) $cols[3]), $amount,
            'excel', trim((string) ($cols[5] ?? '')) ?: null, trim((string) ($cols[6] ?? '')) ?: null,
        ]);
        $imported++;
    }
    json_response(['imported' => $imported, 'skipped' => $skipped, 'errors' => array_slice($errors, 0, 20)]);
}
