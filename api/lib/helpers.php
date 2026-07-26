<?php
declare(strict_types=1);

const MAX_MONTHLY_SALARY = 200000000.0;
const MIN_MONTHLY_SALARY = 5000000.0;
const MAX_TRANSACTION_AMOUNT = 5000000000.0;
const AI_DAILY_QUESTION_LIMIT = 10;

const AI_SUGGESTED_QUESTIONS = [
    'سود امروز چقدر است؟',
    'وضعیت مالی ماه جاری چگونه است؟',
    'بیشترین هزینه مربوط به چیست؟',
    'پیش‌بینی فردا برای درآمد و هزینه چیست؟',
    'پیش‌بینی ماه آینده چیست؟',
];

// سازگاری با PHP 7.4 هاست اشتراکی
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle)
    {
        return $needle === '' || strpos((string) $haystack, (string) $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle)
    {
        $needle = (string) $needle;
        return $needle === '' || strpos((string) $haystack, $needle) === 0;
    }
}

function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $detail, int $status = 400): void
{
    json_response(['detail' => $detail], $status);
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_error('بدنه درخواست JSON نامعتبر است', 400);
    }
    return $data;
}

function to_en_digits(string $value): string
{
    $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $en = ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'];
    return str_replace($fa, $en, $value);
}

function to_fa_digits($value): string
{
    $en = ['0','1','2','3','4','5','6','7','8','9'];
    $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    return str_replace($en, $fa, (string) $value);
}

function format_fa_money($value): string
{
    $n = (int) round((float) $value);
    return to_fa_digits(number_format($n, 0, '.', ',')) . ' ریال';
}

function format_fa_pct($value): string
{
    return to_fa_digits((string) $value) . '٪';
}

function parse_number($raw, string $field = 'مبلغ'): float
{
    if (is_int($raw) || is_float($raw)) {
        return (float) $raw;
    }
    $text = to_en_digits(trim((string) $raw));
    $text = str_replace(['٫', ',', '٬', ' ', "'"], ['.', '', '', '', ''], $text);
    if ($text === '' || !is_numeric($text)) {
        json_error($field . ' باید عدد معتبر باشد', 400);
    }
    return (float) $text;
}

function today_str(): string
{
    return (new DateTime('now', new DateTimeZone('Asia/Tehran')))->format('Y-m-d');
}

function utc_now(): string
{
    return gmdate('Y-m-d\TH:i:s');
}

function is_public_route(string $path): bool
{
    $path = trim($path, '/');
    return in_array($path, ['version', 'auth/login', 'login'], true);
}

function localize_category(?string $category): string
{
    $map = [
        'cogs' => 'بهای تمام‌شده کالا',
        'ops' => 'هزینه‌های عملیاتی',
        'payroll' => 'حقوق و دستمزد',
    ];
    $c = trim((string) $category);
    return $map[$c] ?? ($c !== '' ? $c : 'عمومی');
}

function source_label(?string $source): string
{
    $map = [
        'manual' => 'ثبت دستی',
        'sales' => 'فروش',
        'cogs' => 'بهای تمام‌شده کالا',
        'ops' => 'هزینه‌های عملیاتی',
        'payroll' => 'حقوق و دستمزد',
        'excel' => 'ورود از اکسل',
    ];
    $s = (string) $source;
    return $map[$s] ?? ($s !== '' ? $s : 'ثبت دستی');
}

function kind_label(?string $kind): string
{
    return $kind === 'service' ? 'خدمت' : 'محصول';
}

function gregorian_to_jalali(int $gy, int $gm, int $gd): array
{
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $jy = $gy <= 1600 ? 0 : 979;
    $gy -= $gy <= 1600 ? 621 : 1600;
    $gy2 = $gm > 2 ? $gy + 1 : $gy;
    $days = 365 * $gy + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) - 80 + $gd + $g_d_m[$gm - 1];
    $jy += 33 * intdiv($days, 12053);
    $days %= 12053;
    $jy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $jy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + intdiv($days, 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + intdiv($days - 186, 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return [$jy, $jm, $jd];
}

function format_jalali(string $date): string
{
    $parts = explode('-', substr($date, 0, 10));
    if (count($parts) < 3) {
        return to_fa_digits($date);
    }
    [$jy, $jm, $jd] = gregorian_to_jalali((int) $parts[0], (int) $parts[1], (int) $parts[2]);
    return to_fa_digits(sprintf('%04d/%02d/%02d', $jy, $jm, $jd));
}
