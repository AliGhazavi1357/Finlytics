<?php
declare(strict_types=1);

function seed_demo_data(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $deps = ['تولید', 'فروش و بازاریابی', 'مالی و اداری', 'فناوری اطلاعات', 'خدمات مشتریان'];
    $insDep = $pdo->prepare('INSERT INTO departments (name) VALUES (?)');
    foreach ($deps as $d) {
        $insDep->execute([$d]);
    }

    $depIds = $pdo->query('SELECT id, name FROM departments')->fetchAll();
    $depMap = [];
    foreach ($depIds as $row) {
        $depMap[$row['name']] = (int) $row['id'];
    }

    $employees = [
        ['EMP001', 'رضا محمدی', 'سرپرست خط', 'تولید', 48000000],
        ['EMP002', 'سارا احمدی', 'اپراتور تولید', 'تولید', 32000000],
        ['EMP003', 'محمد کریمی', 'کنترل کیفیت', 'تولید', 36000000],
        ['EMP004', 'نرگس رضایی', 'مدیر فروش', 'فروش و بازاریابی', 55000000],
        ['EMP005', 'امیر حسینی', 'کارشناس فروش', 'فروش و بازاریابی', 38000000],
        ['EMP006', 'زهرا موسوی', 'حسابدار', 'مالی و اداری', 40000000],
        ['EMP007', 'حسین جعفری', 'توسعه‌دهنده', 'فناوری اطلاعات', 52000000],
        ['EMP008', 'مینا نوری', 'کارشناس پشتیبانی', 'خدمات مشتریان', 31000000],
    ];
    $insEmp = $pdo->prepare(
        'INSERT INTO employees (code, full_name, role, department_id, monthly_salary, hire_date, is_active)
         VALUES (?, ?, ?, ?, ?, ?, 1)'
    );
    foreach ($employees as $e) {
        $insEmp->execute([$e[0], $e[1], $e[2], $depMap[$e[3]], $e[4], date('Y-m-d', strtotime('-400 days'))]);
    }

    $catalog = [
        ['PRD-001', 'بسته نرم‌افزار مالی سازمانی', 'product', 85000000, 28000000],
        ['PRD-002', 'ماژول گزارش‌گیری پیشرفته', 'product', 42000000, 12000000],
        ['PRD-003', 'لایسنس داشبورد هوش تجاری', 'product', 28000000, 7500000],
        ['SRV-001', 'پیاده‌سازی و استقرار سامانه', 'service', 95000000, 45000000],
        ['SRV-002', 'پشتیبانی ماهانه طلایی', 'service', 18000000, 6000000],
        ['SRV-003', 'آموزش تخصصی تیم مالی', 'service', 24000000, 8000000],
    ];
    $insItem = $pdo->prepare(
        'INSERT INTO products_services (code, name, kind, unit_price, unit_cost, is_active) VALUES (?, ?, ?, ?, ?, 1)'
    );
    foreach ($catalog as $c) {
        $insItem->execute($c);
    }
    $items = $pdo->query('SELECT * FROM products_services')->fetchAll();

    $insSale = $pdo->prepare(
        'INSERT INTO sales (item_id, sale_date, quantity, unit_price, unit_cost, revenue, cost, profit, channel)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insTx = $pdo->prepare(
        'INSERT INTO transactions (txn_date, direction, category, title, amount, source, reference)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $insPay = $pdo->prepare(
        'INSERT INTO payrolls (employee_id, period_year, period_month, base_salary, bonus, deductions, net_pay, paid_on)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $today = new DateTime('today', new DateTimeZone('Asia/Tehran'));
    $start = (clone $today)->modify('-120 days');
    $rng = 42;
    $pseudo = function () use (&$rng) {
        $rng = (1103515245 * $rng + 12345) & 0x7fffffff;
        return $rng / 0x7fffffff;
    };

    for ($d = clone $start; $d <= $today; $d->modify('+1 day')) {
        $ds = $d->format('Y-m-d');
        $dow = (int) $d->format('N');
        foreach ($items as $item) {
            if ($dow >= 6 && $pseudo() < 0.55) {
                continue;
            }
            $qty = $item['kind'] === 'product'
                ? [0, 0, 1, 1, 2][(int) floor($pseudo() * 5)]
                : [0, 0, 1, 1][(int) floor($pseudo() * 4)];
            if ($qty <= 0) {
                continue;
            }
            $price = (float) $item['unit_price'] * (0.95 + $pseudo() * 0.13);
            $cost = (float) $item['unit_cost'] * (0.97 + $pseudo() * 0.08);
            $revenue = round($qty * $price);
            $totalCost = round($qty * $cost);
            $insSale->execute([
                $item['id'], $ds, $qty, round($price), round($cost), $revenue, $totalCost, $revenue - $totalCost,
                'فروش مستقیم',
            ]);
            $cat = $item['kind'] === 'product' ? 'فروش محصول' : 'ارائه خدمت';
            $insTx->execute([$ds, 'income', $cat, 'فروش ' . $item['name'], $revenue, 'sales', $item['code']]);
            $insTx->execute([$ds, 'expense', 'بهای تمام‌شده کالا', 'هزینه ' . $item['name'], $totalCost, 'cogs', $item['code']]);
        }
        if ($dow < 6 && $pseudo() < 0.4) {
            $ops = [
                ['اجاره و تاسیسات', 8000000],
                ['بازاریابی', 4500000],
                ['لوازم مصرفی', 1500000],
                ['حمل‌ونقل', 2200000],
            ];
            $pick = $ops[(int) floor($pseudo() * count($ops))];
            $insTx->execute([$ds, 'expense', $pick[0], $pick[0], $pick[1], 'ops', null]);
        }
    }

    $emps = $pdo->query('SELECT id, code, full_name, monthly_salary FROM employees')->fetchAll();
    $y = (int) $today->format('Y');
    $m = (int) $today->format('n');
    for ($i = 0; $i < 6; $i++) {
        $yy = $y;
        $mm = $m - $i;
        while ($mm <= 0) {
            $mm += 12;
            $yy -= 1;
        }
        $paid = sprintf('%04d-%02d-28', $yy, $mm);
        if ($paid > $today->format('Y-m-d')) {
            continue;
        }
        foreach ($emps as $emp) {
            $bonus = $pseudo() < 0.7 ? 0 : round($emp['monthly_salary'] * 0.08);
            $ded = round($emp['monthly_salary'] * 0.09);
            $net = $emp['monthly_salary'] + $bonus - $ded;
            $insPay->execute([$emp['id'], $yy, $mm, $emp['monthly_salary'], $bonus, $ded, $net, $paid]);
            $insTx->execute([
                $paid, 'expense', 'حقوق و دستمزد',
                'حقوق ' . $emp['full_name'] . " - {$mm}/{$yy}",
                $net, 'payroll', $emp['code'],
            ]);
        }
    }
}
