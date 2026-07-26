<?php
declare(strict_types=1);

/**
 * تبدیل متن گزارش به گفتار قابل‌خواندن برای TTS فارسی
 * و ساخت فایل MP3 (Google Translate TTS).
 */

function tts_three_digit_words(int $n): string
{
    $ones = ['', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه', 'ده', 'یازده', 'دوازده', 'سیزده', 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده'];
    $tens = ['', '', 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود'];
    $hundreds = ['', 'یکصد', 'دویست', 'سیصد', 'چهارصد', 'پانصد', 'ششصد', 'هفتصد', 'هشتصد', 'نهصد'];
    if ($n === 0) {
        return '';
    }
    if ($n < 20) {
        return $ones[$n];
    }
    if ($n < 100) {
        $t = intdiv($n, 10);
        $o = $n % 10;
        return $o ? ($tens[$t] . ' و ' . $ones[$o]) : $tens[$t];
    }
    $h = intdiv($n, 100);
    $rest = $n % 100;
    $base = $hundreds[$h];
    if ($rest === 0) {
        return $base;
    }
    return $base . ' و ' . tts_three_digit_words($rest);
}

function tts_number_to_persian_words($value): string
{
    $n = (int) round(abs((float) $value));
    if ($n === 0) {
        return 'صفر';
    }
    $scales = [
        1000000000000 => 'تریلیون',
        1000000000 => 'میلیارد',
        1000000 => 'میلیون',
        1000 => 'هزار',
    ];
    $parts = [];
    foreach ($scales as $unit => $label) {
        if ($n >= $unit) {
            $q = intdiv($n, $unit);
            $n = $n % $unit;
            $parts[] = tts_three_digit_words($q) . ' ' . $label;
        }
    }
    if ($n > 0) {
        $parts[] = tts_three_digit_words($n);
    }
    return implode(' و ', array_filter($parts));
}

/** متن نمایشی را برای خوانش صوتی فارسی آماده می‌کند */
function tts_make_speakable(string $text): string
{
    // تاریخ‌های جلالی مثل ۱۴۰۵/۰۵/۰۵
    $text = preg_replace_callback('/([۰-۹0-9]{4})[\/\-]([۰-۹0-9]{1,2})[\/\-]([۰-۹0-9]{1,2})/u', function ($m) {
        $y = to_en_digits($m[1]);
        $mo = to_en_digits($m[2]);
        $d = to_en_digits($m[3]);
        return ' روز ' . tts_number_to_persian_words($d) . ' ماه ' . tts_number_to_persian_words($mo) . ' سال ' . tts_number_to_persian_words($y) . ' ';
    }, $text);

    // مبالغ با جداکننده هزارگان + ریال
    $text = preg_replace_callback('/([۰-۹0-9][۰-۹0-9,\.٬]*)\s*ریال/u', function ($m) {
        $raw = to_en_digits($m[1]);
        $raw = str_replace([',', '٬', ' ', '٫'], ['', '', '', '.'], $raw);
        if (!is_numeric($raw)) {
            return $m[0];
        }
        return tts_number_to_persian_words($raw) . ' ریال';
    }, $text);

    // درصد
    $text = preg_replace_callback('/([۰-۹0-9]+(?:[.,][۰-۹0-9]+)?)\s*[٪%]/u', function ($m) {
        $raw = to_en_digits($m[1]);
        $raw = str_replace(',', '.', $raw);
        return tts_number_to_persian_words($raw) . ' درصد';
    }, $text);

    // بقیه اعداد باقی‌مانده
    $text = preg_replace_callback('/[۰-۹0-9][۰-۹0-9,\.٬]*/u', function ($m) {
        $raw = to_en_digits($m[0]);
        $raw = str_replace([',', '٬', ' '], '', $raw);
        if ($raw === '' || !is_numeric($raw)) {
            return '';
        }
        return tts_number_to_persian_words($raw);
    }, $text);

    // علائم مشکل‌ساز برای TTS انگلیسی/مختلط
    $text = str_replace(
        ['/', '\\', '|', '_', '*', '#', '@', '&', '=', '+', '—', '–', '…', '...', '؛', ';', ':', '«', '»', '"', "'"],
        [' ', ' ', ' ', ' ', ' ', ' ', ' ', ' و ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' '],
        $text
    );
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim((string) $text);
}

function tts_split_chunks(string $text, int $maxLen = 140): array
{
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    if ($text === '') {
        return [];
    }
    $parts = preg_split('/(?<=[.!؟،])\s+/u', $text) ?: [$text];
    $chunks = [];
    $buf = '';
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        if ($buf === '') {
            $buf = $part;
            continue;
        }
        if (mb_strlen($buf . ' ' . $part, 'UTF-8') <= $maxLen) {
            $buf .= ' ' . $part;
        } else {
            $chunks[] = $buf;
            $buf = $part;
        }
    }
    if ($buf !== '') {
        $chunks[] = $buf;
    }
    $final = [];
    foreach ($chunks as $c) {
        while (mb_strlen($c, 'UTF-8') > $maxLen) {
            $final[] = mb_substr($c, 0, $maxLen, 'UTF-8');
            $c = mb_substr($c, $maxLen, null, 'UTF-8');
        }
        if ($c !== '') {
            $final[] = $c;
        }
    }
    return $final;
}

function tts_http_get(string $url): string
{
    $hdr = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Accept: */*',
        'Accept-Language: fa-IR,fa;q=0.9',
        'Referer: https://translate.google.com/',
    ];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => $hdr,
        ]);
        $bin = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($bin === false || $code >= 400 || strlen((string) $bin) < 80) {
            throw new RuntimeException('TTS HTTP failed: ' . ($err ?: ('HTTP ' . $code)));
        }
        return (string) $bin;
    }
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $hdr),
            'timeout' => 60,
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $bin = @file_get_contents($url, false, $context);
    if ($bin === false || strlen($bin) < 80) {
        throw new RuntimeException('TTS file_get_contents failed');
    }
    return $bin;
}

function tts_google_fa_chunk(string $chunk): string
{
    $url = 'https://translate.google.com/translate_tts?ie=UTF-8&client=tw-ob&tl=fa&q=' . rawurlencode($chunk);
    return tts_http_get($url);
}

function synthesize_persian_voice(string $text, string $outPath): string
{
    $dir = dirname($outPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    $speakable = tts_make_speakable($text);
    if ($speakable === '') {
        throw new RuntimeException('متن خالی برای تبدیل به گفتار');
    }

    // اولویت: Edge Neural فارسی (Dilara) — کیفیت خوب و برای پلیر MP3
    try {
        $chunks = tts_split_chunks($speakable, 450);
        $bin = '';
        foreach ($chunks as $i => $chunk) {
            if ($i > 0) {
                usleep(200000);
            }
            $bin .= edge_tts_synthesize($chunk, EDGE_TTS_VOICE);
        }
        if (strlen($bin) < 200) {
            throw new RuntimeException('Edge audio too short');
        }
        if (@file_put_contents($outPath, $bin) === false) {
            throw new RuntimeException('نوشتن فایل صوتی ناموفق بود');
        }
        return 'edge-dilara-fa';
    } catch (Throwable $e) {
        error_log('Edge TTS failed: ' . $e->getMessage());
    }

    // Fallback: Google (گاهی برای fa مسدود است)
    $chunks = tts_split_chunks($speakable, 140);
    $bin = '';
    foreach ($chunks as $i => $chunk) {
        if ($i > 0) {
            usleep(150000);
        }
        $bin .= tts_google_fa_chunk($chunk);
    }
    if (strlen($bin) < 200) {
        throw new RuntimeException('خروجی صوتی خیلی کوتاه است');
    }
    if (@file_put_contents($outPath, $bin) === false) {
        throw new RuntimeException('نوشتن فایل صوتی ناموفق بود');
    }
    return 'google-fa';
}

function flavor_isfahani_ceo_script(string $script): string
{
    $prefix = 'سلام علیکم، وقتتون بخیر. خدمت شما عرض ادب دارم. ';
    if (mb_strpos($script, 'سلام علیکم', 0, 'UTF-8') === 0
        || mb_strpos($script, 'با سلام', 0, 'UTF-8') === 0) {
        return $script;
    }
    $replaced = preg_replace('/^با سلام و احترام،\s*/u', $prefix, $script, 1);
    return $replaced !== null ? $replaced : ($prefix . $script);
}
