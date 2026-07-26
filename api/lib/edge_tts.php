<?php
declare(strict_types=1);

/**
 * Minimal Microsoft Edge TTS client (fa-IR) for shared hosting PHP 7.4+.
 * Uses WebSocket read-aloud endpoint — no Composer required.
 */

const EDGE_TTS_TOKEN = '6A5AA1D4EAFF4E9FB37E23D68491D6F4';
const EDGE_TTS_GEC_VERSION = '1-143.0.3650.75';
const EDGE_TTS_VOICE = 'fa-IR-DilaraNeural';

/** @var float */
$GLOBALS['EDGE_TTS_CLOCK_SKEW'] = 0.0;

function edge_tts_sec_ms_gec(): string
{
    $ticks = (int) floor(time() + (float) $GLOBALS['EDGE_TTS_CLOCK_SKEW'] + 11644473600);
    $rounded = $ticks - ($ticks % 300);
    // Windows FILETIME: 100-nanosecond intervals
    $windowsTicks = $rounded * 10000000;
    return strtoupper(hash('sha256', (string) $windowsTicks . EDGE_TTS_TOKEN));
}

function edge_tts_adjust_clock_skew_from_date_header(string $headers): void
{
    if (!preg_match('/^Date:\s*(.+)$/mi', $headers, $m)) {
        return;
    }
    $serverTs = strtotime(trim($m[1]));
    if ($serverTs === false) {
        return;
    }
    $GLOBALS['EDGE_TTS_CLOCK_SKEW'] = (float) ($serverTs - time());
}

function edge_tts_uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    $hex = bin2hex($data);
    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

function edge_tts_xtime(): string
{
    return gmdate('D M d Y H:i:s') . ' GMT+0000';
}

function edge_tts_escape_xml(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function edge_tts_ssml(string $text, string $voice = EDGE_TTS_VOICE): string
{
    $escaped = edge_tts_escape_xml($text);
    return '<speak version="1.0" xmlns="http://www.w3.org/2001/10/synthesis" xml:lang="fa-IR">'
        . '<voice name="' . $voice . '">'
        . '<prosody rate="-5%" pitch="+0Hz">' . $escaped . '</prosody>'
        . '</voice></speak>';
}

function edge_tts_ws_send($fp, string $payload, int $opcode = 0x1): void
{
    $len = strlen($payload);
    $frame = chr(0x80 | ($opcode & 0x0f));
    $maskBit = 0x80;
    if ($len <= 125) {
        $frame .= chr($maskBit | $len);
    } elseif ($len <= 65535) {
        $frame .= chr($maskBit | 126) . pack('n', $len);
    } else {
        $frame .= chr($maskBit | 127) . pack('J', $len);
    }
    $mask = random_bytes(4);
    $frame .= $mask;
    for ($i = 0; $i < $len; $i++) {
        $frame .= $payload[$i] ^ $mask[$i % 4];
    }
    $written = fwrite($fp, $frame);
    if ($written === false) {
        throw new RuntimeException('WebSocket send failed');
    }
}

function edge_tts_ws_recv($fp): array
{
    $hdr = '';
    while (strlen($hdr) < 2) {
        $chunk = fread($fp, 2 - strlen($hdr));
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('WebSocket closed while reading header');
        }
        $hdr .= $chunk;
    }
    $b1 = ord($hdr[0]);
    $b2 = ord($hdr[1]);
    $opcode = $b1 & 0x0f;
    $masked = ($b2 & 0x80) !== 0;
    $len = $b2 & 0x7f;
    if ($len === 126) {
        $ext = fread($fp, 2);
        $len = unpack('n', $ext)[1];
    } elseif ($len === 127) {
        $ext = fread($fp, 8);
        $arr = unpack('J', $ext);
        $len = (int) $arr[1];
    }
    $mask = '';
    if ($masked) {
        $mask = fread($fp, 4);
    }
    $data = '';
    while (strlen($data) < $len) {
        $chunk = fread($fp, $len - strlen($data));
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('WebSocket closed while reading payload');
        }
        $data .= $chunk;
    }
    if ($masked) {
        for ($i = 0; $i < $len; $i++) {
            $data[$i] = $data[$i] ^ $mask[$i % 4];
        }
    }
    return [$opcode, $data];
}

function edge_tts_extract_audio(string $data): string
{
    $needle = "Path:audio\r\n\r\n";
    $pos = strpos($data, $needle);
    if ($pos !== false) {
        return substr($data, $pos + strlen($needle));
    }
    $needle2 = "Path:audio\r\n";
    $pos2 = strpos($data, $needle2);
    if ($pos2 !== false) {
        $rest = substr($data, $pos2 + strlen($needle2));
        $blank = strpos($rest, "\r\n\r\n");
        if ($blank !== false) {
            return substr($rest, $blank + 4);
        }
        return $rest;
    }
    // Raw MPEG frame
    if (strlen($data) > 100 && ord($data[0]) === 0xff && (ord($data[1]) & 0xe0) === 0xe0) {
        return $data;
    }
    return '';
}

/**
 * @return resource
 */
function edge_tts_open_socket(string $path)
{
    $key = base64_encode(random_bytes(16));
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLS_CLIENT,
        ],
    ]);
    $fp = @stream_socket_client(
        'ssl://speech.platform.bing.com:443',
        $errno,
        $errstr,
        30,
        STREAM_CLIENT_CONNECT,
        $context
    );
    if (!$fp) {
        throw new RuntimeException("Edge TTS connect failed: $errstr ($errno)");
    }
    stream_set_timeout($fp, 60);

    $handshake = "GET {$path} HTTP/1.1\r\n"
        . "Host: speech.platform.bing.com\r\n"
        . "Upgrade: websocket\r\n"
        . "Connection: Upgrade\r\n"
        . "Sec-WebSocket-Key: {$key}\r\n"
        . "Sec-WebSocket-Version: 13\r\n"
        . "Sec-WebSocket-Protocol: synthesize\r\n"
        . "Origin: chrome-extension://jdiccldimpdaibmpdkjnbmckianbfold\r\n"
        . "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36 Edg/143.0.0.0\r\n"
        . "\r\n";
    fwrite($fp, $handshake);
    $response = '';
    while (($line = fgets($fp)) !== false) {
        $response .= $line;
        if ($line === "\r\n" || $line === "\n") {
            break;
        }
    }
    if (stripos($response, '101') === false) {
        edge_tts_adjust_clock_skew_from_date_header($response);
        fclose($fp);
        throw new RuntimeException('Edge TTS handshake failed: ' . substr($response, 0, 240));
    }
    return $fp;
}

function edge_tts_build_path(string $connId): string
{
    $sec = edge_tts_sec_ms_gec();
    return '/consumer/speech/synthesize/readaloud/edge/v1'
        . '?TrustedClientToken=' . EDGE_TTS_TOKEN
        . '&ConnectionId=' . $connId
        . '&Sec-MS-GEC=' . $sec
        . '&Sec-MS-GEC-Version=' . rawurlencode(EDGE_TTS_GEC_VERSION);
}

function edge_tts_synthesize_once(string $text, string $voice = EDGE_TTS_VOICE): string
{
    $connId = edge_tts_uuid();
    $fp = edge_tts_open_socket(edge_tts_build_path($connId));

    $config = [
        'context' => [
            'synthesis' => [
                'audio' => [
                    'metadataoptions' => [
                        'sentenceBoundaryEnabled' => 'false',
                        'wordBoundaryEnabled' => 'false',
                    ],
                    'outputFormat' => 'audio-24khz-48kbitrate-mono-mp3',
                ],
            ],
        ],
    ];
    $configMsg = "X-Timestamp:" . edge_tts_xtime() . "\r\n"
        . "Content-Type:application/json; charset=utf-8\r\n"
        . "Path:speech.config\r\n\r\n"
        . json_encode($config);
    edge_tts_ws_send($fp, $configMsg);

    $ssml = edge_tts_ssml($text, $voice);
    $ssmlMsg = "X-RequestId:{$connId}\r\n"
        . "Content-Type:application/ssml+xml\r\n"
        . "X-Timestamp:" . edge_tts_xtime() . "\r\n"
        . "Path:ssml\r\n\r\n"
        . $ssml;
    edge_tts_ws_send($fp, $ssmlMsg);

    $audio = '';
    $deadline = time() + 90;
    while (time() < $deadline) {
        [$opcode, $data] = edge_tts_ws_recv($fp);
        if ($opcode === 0x8) {
            break;
        }
        if ($opcode === 0x1 || $opcode === 0x2) {
            if (strpos($data, 'Path:turn.end') !== false) {
                break;
            }
            $chunk = edge_tts_extract_audio($data);
            if ($chunk !== '') {
                $audio .= $chunk;
            }
        }
    }
    fclose($fp);

    if (strlen($audio) < 200) {
        throw new RuntimeException('Edge TTS returned empty audio');
    }
    return $audio;
}

function edge_tts_synthesize(string $text, string $voice = EDGE_TTS_VOICE): string
{
    try {
        return edge_tts_synthesize_once($text, $voice);
    } catch (Throwable $e) {
        // یک بار با اصلاح اختلاف ساعت سرور (Sec-MS-GEC) دوباره تلاش کن
        if (stripos($e->getMessage(), 'handshake') !== false) {
            return edge_tts_synthesize_once($text, $voice);
        }
        throw $e;
    }
}
