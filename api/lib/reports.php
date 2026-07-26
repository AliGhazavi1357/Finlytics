<?php
declare(strict_types=1);

const COGS_CATEGORIES = ['بهای تمام‌شده کالا', 'بهای تمام‌شده', 'هزینه کالای فروخته‌شده'];
const OPEX_CATEGORIES = [
    'هزینه‌های عملیاتی', 'اجاره و تاسیسات', 'بازاریابی', 'لوازم مصرفی', 'حمل‌ونقل', 'نگهداری تجهیزات',
];

function fin_period_bounds(string $period, ?string $ref = null): array
{
    $tz = new DateTimeZone('Asia/Tehran');
    $today = $ref ? new DateTime($ref, $tz) : new DateTime('today', $tz);
    if ($period === 'daily') {
        $d = $today->format('Y-m-d');
        return [$d, $d, 'گزارش روزانه ' . format_jalali($d)];
    }
    if ($period === 'monthly') {
        $start = (clone $today)->modify('first day of this month')->format('Y-m-d');
        $end = (clone $today)->modify('last day of this month')->format('Y-m-d');
        return [$start, $end, 'گزارش ماهانه ' . format_jalali($start)];
    }
    if ($period === 'yearly') {
        $y = (int) $today->format('Y');
        $start = sprintf('%04d-01-01', $y);
        $end = sprintf('%04d-12-31', $y);
        return [$start, $end, 'گزارش سال مالی ' . to_fa_digits((string) $y)];
    }
    throw new InvalidArgumentException('بازه نامعتبر');
}

function fin_sum_direction(PDO $pdo, string $start, string $end, string $direction): float
{
    $st = $pdo->prepare(
        'SELECT COALESCE(SUM(amount),0) FROM transactions WHERE txn_date BETWEEN ? AND ? AND direction = ?'
    );
    $st->execute([$start, $end, $direction]);
    return (float) $st->fetchColumn();
}

function fin_sum_categories(PDO $pdo, string $start, string $end, array $categories): float
{
    if (!$categories) {
        return 0.0;
    }
    $placeholders = implode(',', array_fill(0, count($categories), '?'));
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(amount),0) FROM transactions
         WHERE txn_date BETWEEN ? AND ? AND direction = 'expense' AND category IN ($placeholders)"
    );
    $st->execute(array_merge([$start, $end], $categories));
    return (float) $st->fetchColumn();
}

function fin_sales_stats(PDO $pdo, string $start, string $end): array
{
    $st = $pdo->prepare(
        'SELECT COALESCE(SUM(revenue),0), COALESCE(SUM(profit),0) FROM sales WHERE sale_date BETWEEN ? AND ?'
    );
    $st->execute([$start, $end]);
    $row = $st->fetch(PDO::FETCH_NUM);
    return [(float) $row[0], (float) $row[1]];
}

function fin_payroll_cost(PDO $pdo, string $start, string $end): float
{
    $st = $pdo->prepare('SELECT COALESCE(SUM(net_pay),0) FROM payrolls WHERE paid_on BETWEEN ? AND ?');
    $st->execute([$start, $end]);
    return (float) $st->fetchColumn();
}

function fin_pct_change(float $current, float $previous): ?float
{
    if ($previous == 0.0) {
        return $current == 0.0 ? null : 100.0;
    }
    return round((($current - $previous) / abs($previous)) * 100, 1);
}

function fin_previous_period(string $start, string $end): array
{
    $s = new DateTime($start);
    $e = new DateTime($end);
    $length = (int) $s->diff($e)->days + 1;
    $prevEnd = (clone $s)->modify('-1 day');
    $prevStart = (clone $prevEnd)->modify('-' . ($length - 1) . ' days');
    return [$prevStart->format('Y-m-d'), $prevEnd->format('Y-m-d')];
}

function fin_breakdown(PDO $pdo, string $start, string $end, string $direction, int $limit = 8): array
{
    $st = $pdo->prepare(
        'SELECT category, SUM(amount) AS total FROM transactions
         WHERE txn_date BETWEEN ? AND ? AND direction = ?
         GROUP BY category ORDER BY total DESC LIMIT ' . (int) $limit
    );
    $st->execute([$start, $end, $direction]);
    $out = [];
    foreach ($st->fetchAll() as $row) {
        $out[] = ['category' => localize_category($row['category']), 'amount' => (float) $row['total']];
    }
    return $out;
}

function fin_build_trend(PDO $pdo, string $period, string $start, string $end): array
{
    $points = [];
    $tz = new DateTimeZone('Asia/Tehran');
    $cur = new DateTime($start, $tz);
    $last = new DateTime($end, $tz);

    if ($period === 'daily' || $period === 'monthly') {
        while ($cur <= $last) {
            $ds = $cur->format('Y-m-d');
            $inc = fin_sum_direction($pdo, $ds, $ds, 'income');
            $exp = fin_sum_direction($pdo, $ds, $ds, 'expense');
            $points[] = [
                'label' => $cur->format('m-d'),
                'income' => $inc,
                'expense' => $exp,
                'profit' => $inc - $exp,
            ];
            $cur->modify('+1 day');
        }
        if ($period === 'monthly' && count($points) > 16) {
            $compressed = [];
            $chunk = (int) ceil(count($points) / 12);
            for ($i = 0; $i < count($points); $i += $chunk) {
                $slice = array_slice($points, $i, $chunk);
                $inc = array_sum(array_column($slice, 'income'));
                $exp = array_sum(array_column($slice, 'expense'));
                $compressed[] = [
                    'label' => $slice[0]['label'],
                    'income' => $inc,
                    'expense' => $exp,
                    'profit' => $inc - $exp,
                ];
            }
            return $compressed;
        }
        return $points;
    }

    $cur->modify('first day of this month');
    while ($cur <= $last) {
        $ms = $cur->format('Y-m-01');
        $me = $cur->format('Y-m-t');
        if ($ms < $start) {
            $ms = $start;
        }
        if ($me > $end) {
            $me = $end;
        }
        $inc = fin_sum_direction($pdo, $ms, $me, 'income');
        $exp = fin_sum_direction($pdo, $ms, $me, 'expense');
        $points[] = [
            'label' => $cur->format('Y-m'),
            'income' => $inc,
            'expense' => $exp,
            'profit' => $inc - $exp,
        ];
        $cur->modify('first day of next month');
    }
    return $points;
}

function fin_shift_month(DateTime $d, int $months): DateTime
{
    $x = clone $d;
    $x->modify(($months >= 0 ? '+' : '') . $months . ' months');
    return $x;
}

function fin_predict_for_period(PDO $pdo, string $period, ?string $ref = null): array
{
    $tz = new DateTimeZone('Asia/Tehran');
    $today = $ref ? new DateTime($ref, $tz) : new DateTime('today', $tz);
    $refStr = $today->format('Y-m-d');

    if ($period === 'daily') {
        $incomes = [];
        $expenses = [];
        $weights = [];
        for ($i = 0; $i < 7; $i++) {
            $day = (clone $today)->modify("-{$i} days")->format('Y-m-d');
            $incomes[] = fin_sum_direction($pdo, $day, $day, 'income');
            $expenses[] = fin_sum_direction($pdo, $day, $day, 'expense');
            $weights[] = 7 - $i;
        }
        $wSum = array_sum($weights) ?: 1;
        $predInc = 0.0;
        $predExp = 0.0;
        foreach ($incomes as $i => $v) {
            $predInc += $v * $weights[$i];
            $predExp += $expenses[$i] * $weights[$i];
        }
        $predInc = round($predInc / $wSum);
        $predExp = round($predExp / $wSum);
        $target = (clone $today)->modify('+1 day')->format('Y-m-d');
        $profit = $predInc - $predExp;
        return [
            'period_type' => 'daily',
            'target_label' => 'فردا',
            'forecast_date' => $target,
            'forecast_start' => $target,
            'forecast_end' => $target,
            'predicted_income' => $predInc,
            'predicted_expense' => $predExp,
            'predicted_profit' => $profit,
            'confidence_note' => 'بر اساس میانگین وزنی ۷ روز اخیر با تعدیل روند کوتاه‌مدت',
            'method' => 'weighted_7d_trend',
            'narrative' => 'پیش‌بینی فردا (' . format_jalali($target) . '): درآمد حدود ' . format_fa_money($predInc)
                . '، هزینه حدود ' . format_fa_money($predExp) . ' و '
                . ($profit >= 0 ? 'سود خالص تقریبی ' : 'زیان تقریبی ') . format_fa_money(abs($profit)) . '.',
        ];
    }

    if ($period === 'monthly') {
        $incomes = [];
        $expenses = [];
        $cursor = (clone $today)->modify('first day of this month');
        for ($i = 0; $i < 6; $i++) {
            $m = fin_shift_month($cursor, -$i);
            $ms = $m->format('Y-m-01');
            $me = $m->format('Y-m-t');
            $incomes[] = fin_sum_direction($pdo, $ms, $me, 'income');
            $expenses[] = fin_sum_direction($pdo, $ms, $me, 'expense');
        }
        $weights = [6, 5, 4, 3, 2, 1];
        $wSum = array_sum($weights);
        $predInc = 0.0;
        $predExp = 0.0;
        foreach ($incomes as $i => $v) {
            $predInc += $v * $weights[$i];
            $predExp += $expenses[$i] * $weights[$i];
        }
        $predInc = round($predInc / $wSum);
        $predExp = round($predExp / $wSum);
        $next = fin_shift_month($cursor, 1);
        $fStart = $next->format('Y-m-01');
        $fEnd = $next->format('Y-m-t');
        $profit = $predInc - $predExp;
        return [
            'period_type' => 'monthly',
            'target_label' => 'ماه آینده',
            'forecast_date' => $fStart,
            'forecast_start' => $fStart,
            'forecast_end' => $fEnd,
            'predicted_income' => $predInc,
            'predicted_expense' => $predExp,
            'predicted_profit' => $profit,
            'confidence_note' => 'بر اساس میانگین وزنی ۶ ماه اخیر با تعدیل روند',
            'method' => 'weighted_6m_trend',
            'narrative' => 'پیش‌بینی ماه آینده (' . format_jalali($fStart) . '): درآمد حدود ' . format_fa_money($predInc)
                . '، هزینه حدود ' . format_fa_money($predExp) . ' و '
                . ($profit >= 0 ? 'سود خالص تقریبی ' : 'زیان تقریبی ') . format_fa_money(abs($profit)) . '.',
        ];
    }

    $incomes = [];
    $expenses = [];
    $y = (int) $today->format('Y');
    for ($i = 0; $i < 3; $i++) {
        $yy = $y - $i;
        $ys = sprintf('%04d-01-01', $yy);
        $ye = sprintf('%04d-12-31', $yy);
        $incomes[] = fin_sum_direction($pdo, $ys, $ye, 'income');
        $expenses[] = fin_sum_direction($pdo, $ys, $ye, 'expense');
    }
    $weights = [3, 2, 1];
    $wSum = 6;
    $predInc = round(($incomes[0] * 3 + $incomes[1] * 2 + $incomes[2]) / $wSum);
    $predExp = round(($expenses[0] * 3 + $expenses[1] * 2 + $expenses[2]) / $wSum);
    $nextY = $y + 1;
    $fStart = sprintf('%04d-01-01', $nextY);
    $fEnd = sprintf('%04d-12-31', $nextY);
    $profit = $predInc - $predExp;
    return [
        'period_type' => 'yearly',
        'target_label' => 'سال آینده',
        'forecast_date' => $fStart,
        'forecast_start' => $fStart,
        'forecast_end' => $fEnd,
        'predicted_income' => $predInc,
        'predicted_expense' => $predExp,
        'predicted_profit' => $profit,
        'confidence_note' => 'بر اساس میانگین وزنی ۳ سال اخیر',
        'method' => 'weighted_3y',
        'narrative' => 'پیش‌بینی سال آینده: درآمد حدود ' . format_fa_money($predInc)
            . '، هزینه حدود ' . format_fa_money($predExp) . ' و '
            . ($profit >= 0 ? 'سود خالص تقریبی ' : 'زیان تقریبی ') . format_fa_money(abs($profit)) . '.',
    ];
}

function fin_build_dashboard(PDO $pdo, string $period = 'monthly'): array
{
    [$start, $end, $label] = fin_period_bounds($period);
    // Cap open periods to today for current-month/year views
    $today = today_str();
    if ($end > $today) {
        $end = $today;
    }
    $income = fin_sum_direction($pdo, $start, $end, 'income');
    $expense = fin_sum_direction($pdo, $start, $end, 'expense');
    $profit = $income - $expense;
    [$salesRev, $salesProfit] = fin_sales_stats($pdo, $start, $end);
    $payroll = fin_payroll_cost($pdo, $start, $end);
    $forecast = fin_predict_for_period($pdo, $period);

    [$pStart, $pEnd] = fin_previous_period($start, $end);
    $prevIncome = fin_sum_direction($pdo, $pStart, $pEnd, 'income');
    $prevExpense = fin_sum_direction($pdo, $pStart, $pEnd, 'expense');
    $prevProfit = $prevIncome - $prevExpense;

    $st = $pdo->prepare(
        'SELECT p.name, SUM(s.revenue) AS revenue, SUM(s.profit) AS profit, SUM(s.quantity) AS qty
         FROM sales s JOIN products_services p ON p.id = s.item_id
         WHERE s.sale_date BETWEEN ? AND ?
         GROUP BY p.id ORDER BY revenue DESC LIMIT 5'
    );
    $st->execute([$start, $end]);
    $topProducts = [];
    foreach ($st->fetchAll() as $row) {
        $topProducts[] = [
            'name' => $row['name'],
            'revenue' => (float) $row['revenue'],
            'profit' => (float) $row['profit'],
            'quantity' => (float) $row['qty'],
        ];
    }

    $st = $pdo->prepare(
        'SELECT * FROM transactions WHERE txn_date BETWEEN ? AND ?
         ORDER BY txn_date DESC, id DESC LIMIT 12'
    );
    $st->execute([$start, $end]);
    $recent = [];
    foreach ($st->fetchAll() as $t) {
        $recent[] = [
            'id' => (int) $t['id'],
            'txn_date' => $t['txn_date'],
            'direction' => $t['direction'],
            'category' => localize_category($t['category']),
            'title' => $t['title'],
            'amount' => (float) $t['amount'],
            'source' => $t['source'],
            'source_label' => source_label($t['source']),
        ];
    }

    $tLabel = $forecast['target_label'];
    $cards = [
        ['label' => 'کل درآمد', 'value' => $income, 'change_pct' => fin_pct_change($income, $prevIncome), 'tone' => 'positive'],
        ['label' => 'کل هزینه', 'value' => $expense, 'change_pct' => fin_pct_change($expense, $prevExpense), 'tone' => 'warning'],
        ['label' => 'سود خالص', 'value' => $profit, 'change_pct' => fin_pct_change($profit, $prevProfit), 'tone' => 'accent'],
        ['label' => 'درآمد فروش', 'value' => $salesRev, 'change_pct' => null, 'tone' => 'positive'],
        ['label' => 'سود فروش', 'value' => $salesProfit, 'change_pct' => null, 'tone' => 'accent'],
        ['label' => 'هزینه حقوق', 'value' => $payroll, 'change_pct' => null, 'tone' => 'warning'],
        ['label' => "پیش‌بینی درآمد {$tLabel}", 'value' => $forecast['predicted_income'], 'change_pct' => null, 'tone' => 'positive'],
        ['label' => "پیش‌بینی هزینه {$tLabel}", 'value' => $forecast['predicted_expense'], 'change_pct' => null, 'tone' => 'warning'],
        ['label' => "پیش‌بینی سود {$tLabel}", 'value' => $forecast['predicted_profit'], 'change_pct' => null, 'tone' => 'accent'],
    ];

    return [
        'period' => $label,
        'cards' => $cards,
        'trend' => fin_build_trend($pdo, $period, $start, $end),
        'expense_breakdown' => fin_breakdown($pdo, $start, $end, 'expense'),
        'income_breakdown' => fin_breakdown($pdo, $start, $end, 'income'),
        'top_products' => $topProducts,
        'recent_transactions' => $recent,
        'tomorrow' => $forecast,
        'forecast' => $forecast,
    ];
}

function fin_build_period_report(PDO $pdo, string $period): array
{
    [$start, $end, $label] = fin_period_bounds($period);
    $today = today_str();
    if ($end > $today) {
        $end = $today;
    }
    $income = fin_sum_direction($pdo, $start, $end, 'income');
    $expense = fin_sum_direction($pdo, $start, $end, 'expense');
    $profit = $income - $expense;
    [$salesRev, $salesProfit] = fin_sales_stats($pdo, $start, $end);
    $payroll = fin_payroll_cost($pdo, $start, $end);
    $cogs = fin_sum_categories($pdo, $start, $end, COGS_CATEGORIES);
    $opex = fin_sum_categories($pdo, $start, $end, OPEX_CATEGORIES);
    $days = max(1, (new DateTime($start))->diff(new DateTime($end))->days + 1);
    $margin = $income ? round(($profit / $income) * 100, 1) : 0.0;
    $forecast = fin_predict_for_period($pdo, $period);

    $status = $profit >= 0
        ? 'عملکرد مالی در وضعیت مطلوب و سودآور قرار دارد'
        : 'عملکرد مالی زیان‌ده بوده و نیازمند بازنگری هزینه است';

    $narrative = "{$label}. {$status}. "
        . 'جمع درآمد ' . format_fa_money($income) . ' و جمع هزینه ' . format_fa_money($expense) . ' ثبت شده است. '
        . 'سود خالص برابر ' . format_fa_money($profit) . ' با حاشیه سود ' . format_fa_pct($margin) . ' است. '
        . 'درآمد حاصل از فروش محصول و خدمت ' . format_fa_money($salesRev) . ' و سود فروش ' . format_fa_money($salesProfit) . ' بوده است. '
        . 'بهای تمام‌شده کالا ' . format_fa_money($cogs) . ' و هزینه‌های عملیاتی ' . format_fa_money($opex) . ' بوده است. '
        . 'هزینه حقوق و دستمزد در این بازه ' . format_fa_money($payroll) . ' محاسبه شده است. '
        . $forecast['narrative'];

    return [
        'period_type' => $period,
        'label' => $label,
        'start_date' => $start,
        'end_date' => $end,
        'total_income' => $income,
        'total_expense' => $expense,
        'net_profit' => $profit,
        'sales_revenue' => $salesRev,
        'sales_profit' => $salesProfit,
        'payroll_cost' => $payroll,
        'cogs_cost' => $cogs,
        'opex_cost' => $opex,
        'margin_pct' => $margin,
        'daily_avg_income' => round($income / $days),
        'daily_avg_expense' => round($expense / $days),
        'narrative' => $narrative,
        'trend' => fin_build_trend($pdo, $period, $start, $end),
        'tomorrow' => $forecast,
        'forecast' => $forecast,
    ];
}

function fin_answer_question(PDO $pdo, string $question): string
{
    $q = mb_strtolower($question, 'UTF-8');
    $m = fin_build_period_report($pdo, 'monthly');
    if (str_contains($q, 'سود') || str_contains($q, 'profit')) {
        return 'سود ماه جاری حدود ' . format_fa_money($m['net_profit']) . ' است. حاشیه سود ' . format_fa_pct($m['margin_pct']) . ' محاسبه شده است.';
    }
    if (str_contains($q, 'هزینه') || str_contains($q, 'خرج')) {
        return 'هزینه ماه جاری حدود ' . format_fa_money($m['total_expense']) . ' است. بهای تمام‌شده کالا ' . format_fa_money($m['cogs_cost']) . ' و هزینه عملیاتی ' . format_fa_money($m['opex_cost']) . ' بوده است.';
    }
    if (str_contains($q, 'درآمد') || str_contains($q, 'فروش')) {
        return 'درآمد ماه جاری حدود ' . format_fa_money($m['total_income']) . ' و درآمد فروش ' . format_fa_money($m['sales_revenue']) . ' است.';
    }
    if (str_contains($q, 'پرسنل') || str_contains($q, 'کارمند') || str_contains($q, 'حقوق')) {
        $active = (int) $pdo->query('SELECT COUNT(*) FROM employees WHERE is_active = 1')->fetchColumn();
        return 'تعداد پرسنل فعال: ' . to_fa_digits((string) $active) . ' نفر. هزینه حقوق ماه جاری حدود ' . format_fa_money($m['payroll_cost']) . ' است.';
    }
    return 'بر اساس داده‌های فعلی: درآمد ماه ' . format_fa_money($m['total_income'])
        . '، هزینه ماه ' . format_fa_money($m['total_expense'])
        . ' و سود ماه ' . format_fa_money($m['net_profit']) . '. برای جزئیات بیشتر از بخش گزارش‌ها استفاده کنید.';
}

function fin_voice_script(PDO $pdo): string
{
    $daily = fin_build_period_report($pdo, 'daily');
    $monthly = fin_build_period_report($pdo, 'monthly');
    $f = $daily['forecast'];
    $today = today_str();
    $st = $pdo->prepare(
        'SELECT p.name FROM sales s JOIN products_services p ON p.id = s.item_id
         WHERE s.sale_date = ? GROUP BY p.id ORDER BY SUM(s.revenue) DESC LIMIT 2'
    );
    $st->execute([$today]);
    $names = array_column($st->fetchAll(), 'name');
    $topLine = $names ? implode('، ', $names) : 'بدون فروش برجسته';
    $outlook = $daily['net_profit'] >= 0
        ? 'پیشنهاد می‌شود تمرکز روی خطوط سودآور و کنترل هزینه‌های عملیاتی ادامه یابد.'
        : 'پیشنهاد می‌شود هزینه‌های غیرضروری امروز بررسی و فروش روزهای آینده تقویت شود.';

    return 'با سلام و احترام، گزارش عملکرد مالی روز ' . format_jalali($today) . ' خدمت مدیرعامل محترم ارائه می‌شود. '
        . 'امروز مجموع درآمد ' . format_fa_money($daily['total_income']) . ' و مجموع هزینه‌ها ' . format_fa_money($daily['total_expense']) . ' بوده است. '
        . 'نتیجه خالص روز برابر ' . format_fa_money($daily['net_profit']) . ' ثبت شده است. '
        . 'درآمد فروش محصول و خدمت امروز ' . format_fa_money($daily['sales_revenue']) . ' و سود فروش ' . format_fa_money($daily['sales_profit']) . ' بوده است. '
        . 'بهای تمام‌شده کالا امروز ' . format_fa_money($daily['cogs_cost']) . ' و هزینه‌های عملیاتی ' . format_fa_money($daily['opex_cost']) . ' بوده است. '
        . 'اقلام پرفروش امروز شامل ' . $topLine . ' می‌باشد. '
        . 'در مقیاس ماه جاری، درآمد تجمیعی ' . format_fa_money($monthly['total_income']) . '، هزینه ' . format_fa_money($monthly['total_expense'])
        . ' و سود خالص ' . format_fa_money($monthly['net_profit']) . ' با حاشیه سود ' . format_fa_pct($monthly['margin_pct']) . ' است. '
        . 'هزینه حقوق در ماه جاری حدود ' . format_fa_money($monthly['payroll_cost']) . ' برآورد شده است. '
        . $f['narrative'] . ' ' . $outlook
        . ' پایان گزارش.';
}
