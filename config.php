<?php
declare(strict_types=1);

const DEFAULT_ACTIVATION_CODE = ''; // ໃສ່ລະຫັດ 12 ຫຼັກ ຫຼື ປ່ອຍວ່າງເພື່ອດຶງຈາກ AccountStore

const ONEPLAY_BASE_URL = 'https://bcel.la:8667';
const ONEPLAY_USER_AGENT = 'jvix-oneplay/1.0.0 (sdk_gphone64_arm64; android 14; byjvix/1.0.0)';
const ONEPLAY_REQUESTED_WITH = 'com.bcel.onepayshop';

const SESSION_DIR = __DIR__ . '/sessions';

if (!is_dir(SESSION_DIR)) {
    mkdir(SESSION_DIR, 0755, true);
}

function json_response(array $data, int $code = 200): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        exit;
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function read_json_input(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return $_POST;
}

function openssl_config_path(): ?string
{
    static $path = null;
    if ($path !== null) {
        return $path !== '' ? $path : null;
    }
    $candidates = array_filter([
        getenv('OPENSSL_CONF') ?: null,
        defined('OPENSSL_CONF_PATH') ? OPENSSL_CONF_PATH : null,
        __DIR__ . '/openssl.cnf',
    ]);
    foreach ($candidates as $candidate) {
        $candidate = str_replace('\\', '/', $candidate);
        if (is_file($candidate)) {
            $path = $candidate;
            return $path;
        }
    }
    $path = '';
    return null;
}

function openssl_ec_config(): array
{
    $config = [
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ];
    $opensslConf = openssl_config_path();
    if ($opensslConf !== null) {
        $config['config'] = $opensslConf;
    }
    return $config;
}
