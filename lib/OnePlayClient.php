<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

final class OnePlayClient
{
    private array $session;
    private $privateKey;
    private string $sessionFile;

    public function __construct(private string $activationCode)
    {
        $this->activationCode = self::normalizeActivation($activationCode);
        $this->sessionFile = SESSION_DIR . '/ac_' . hash('sha256', $this->activationCode) . '.json';
        $this->session = $this->loadSession();
        $this->privateKey = $this->loadOrCreatePrivateKey();
    }

    public static function normalizeActivation(string $code): string
    {
        $digits = preg_replace('/\D+/', '', $code) ?? '';
        if (strlen($digits) !== 12) throw new RuntimeException('ລະຫັດເປີດໃຊ້ງານຕ້ອງເປັນຕົວເລກ 12 ຫຼັກ');
        return $digits;
    }

    private function loadSession(): array
    {
        if (!is_file($this->sessionFile)) {
            return [
                'activation_code_hash' => hash('sha256', $this->activationCode),
                'language' => 'LA',
                'device_type' => 'ANDROID',
                'loadapp_version' => 2,
                'push_id' => bin2hex(random_bytes(8)),
            ];
        }
        $data = json_decode((string) file_get_contents($this->sessionFile), true);
        return is_array($data) ? $data : [];
    }

    private function saveSession(): void
    {
        $this->session['activation_code'] = $this->activationCode;
        $this->session['activation_code_hash'] = hash('sha256', $this->activationCode);
        $this->session['updated_at'] = date('Y-m-d H:i:s');
        file_put_contents($this->sessionFile, json_encode($this->session, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function loadOrCreatePrivateKey()
    {
        if (!empty($this->session['private_key_pem'])) {
            $key = openssl_pkey_get_private($this->session['private_key_pem']);
            if ($key !== false) return $key;
        }
        $config = openssl_ec_config();
        $key = openssl_pkey_new($config);
        if ($key === false) throw new RuntimeException('ສ້າງ EC key ບໍ່ໄດ້');
        $exportConfig = openssl_config_path() !== null ? ['config' => openssl_config_path()] : [];
        if (!openssl_pkey_export($key, $pem, null, $exportConfig)) throw new RuntimeException('ສົ່ງອອກ EC key ບໍ່ໄດ້');
        $this->session['private_key_pem'] = $pem;
        $this->saveSession();
        return $key;
    }

    private function publicKeySpkiHex(): string
    {
        $details = openssl_pkey_get_details($this->privateKey);
        $b64 = preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/', '', $details['key']);
        $der = base64_decode((string) $b64, true);
        return bin2hex($der);
    }

    private function compactJson(array $body): string
    {
        return json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function signHex(string $payload): string
    {
        $signature = '';
        if (!openssl_sign($payload, $signature, $this->privateKey, OPENSSL_ALGO_SHA256)) throw new RuntimeException('ລົງນາມ request ບໍ່ໄດ້');
        return bin2hex($signature);
    }

    private function postSigned(string $path, array $body): mixed
    {
        $payload = $this->compactJson($body);
        $deviceId = (string) ($this->session['device_id'] ?? '');
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json, text/plain, */*',
            'User-Agent: ' . ONEPLAY_USER_AGENT,
            'X-Device-Id: ' . $deviceId,
            'X-Device-Signature: ' . $this->signHex($payload),
            'X-Requested-With: ' . ONEPLAY_REQUESTED_WITH,
        ];
        $ch = curl_init(ONEPLAY_BASE_URL . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new RuntimeException('ເຊື່ອມຕໍ່ BCEL ບໍ່ໄດ້: ' . $err);
        $decoded = json_decode($raw, true);
        if ($status < 200 || $status >= 300) {
            $msg = is_array($decoded) ? ($decoded['message'] ?? $raw) : $raw;
            throw new RuntimeException("HTTP {$status}: {$msg}");
        }
        return $decoded;
    }

    private function ensureRegistered(): void
    {
        if (!empty($this->session['device_id']) && !empty($this->session['device_token'])) return;
        $data = $this->postSigned('/device/register', [
            'publicKey' => $this->publicKeySpkiHex(),
            'deviceName' => ONEPLAY_USER_AGENT,
            'deviceType' => $this->session['device_type'] ?? 'ANDROID',
            'pushId' => $this->session['push_id'] ?? bin2hex(random_bytes(8)),
        ]);
        $this->session['device_id'] = (string) $data['deviceId'];
        $this->session['device_token'] = (string) $data['token'];
        $this->saveSession();
    }

    private function ensureActivated(): void
    {
        if (!empty($this->session['activation_completed'])) return;
        $this->postSigned('/app/activatebycode', [
            'activationcode' => $this->activationCode,
            'devicetoken' => (string) ($this->session['device_token'] ?? ''),
        ]);
        $this->session['activation_completed'] = true;
        $this->saveSession();
    }

    private function ensureActiveShop(): void
    {
        if (!empty($this->session['mcid']) && !empty($this->session['active_merchant'])) return;
        $data = $this->postSigned('/app/loadapp', [
            'pushId' => $this->session['push_id'] ?? null,
            'language' => $this->session['language'] ?? 'LA',
            'version' => (int) ($this->session['loadapp_version'] ?? 2),
        ]);
        $chosen = null;
        foreach ($data as $row) {
            if (is_array($row) && (int) ($row['active'] ?? 0) === 1) {
                $chosen = $row;
                break;
            }
        }
        if ($chosen === null && isset($data[0]) && is_array($data[0])) $chosen = $data[0];
        $this->session['mcid'] = (string) $chosen['mcid'];
        $this->session['active_merchant'] = $chosen;
        $this->saveSession();
    }

    public function bootstrap(): array
    {
        $this->ensureRegistered();
        $this->ensureActivated();
        $this->ensureActiveShop();
        $merchant = $this->session['active_merchant'] ?? [];
        return [
            'ok' => true,
            'mcid' => $this->session['mcid'] ?? '',
            'merchant' => $merchant,
            'owner' => self::merchantOwnerName($merchant),
            'device_id' => $this->session['device_id'] ?? '',
        ];
    }

    public function fetchHistory(): array
    {
        $this->bootstrap();
        $history = $this->postSigned('/app/paymenthistory', ['mcid' => (string) ($this->session['mcid'] ?? '')]);
        $merchant = $this->session['active_merchant'] ?? [];
        $owner = self::merchantOwnerName($merchant);
        if ($owner !== '') $history['owner_name'] = $owner;
        $this->saveSession();
        return $history;
    }

    public function buildQrPayload(float $amount): array
    {
        $this->bootstrap();
        $merchant = is_array($this->session['active_merchant'] ?? null) ? $this->session['active_merchant'] : [];
        $mcid = (string) ($this->session['mcid'] ?? '');
        $payload = self::buildTlvTree(self::buildPayloadObj($amount, $merchant, $mcid));
        return [
            'payload' => $payload,
            'mcid' => $mcid,
            'merchant' => $merchant,
            'owner' => self::merchantOwnerName($merchant),
        ];
    }

    public static function merchantOwnerName(array $merchant): string
    {
        foreach (['merchantEnglishName', 'staffName', 'merchantLaoName', 'merchantName'] as $k) {
            $v = trim((string) ($merchant[$k] ?? ''));
            if ($v !== '') return $v;
        }
        return '';
    }

    private static function q8(int|string $v): string { return str_pad((string)$v, 2, '0', STR_PAD_LEFT); }
    private static function tlv(int|string $tag, string $value): string { return self::q8($tag) . self::q8(strlen($value)) . $value; }

    private static function crc16Ccitt(string $payload): string
    {
        $crc = 0xFFFF;
        $len = strlen($payload);
        for ($i = 0; $i < $len; $i++) {
            $crc ^= ord($payload[$i]) << 8;
            for ($j = 0; $j < 8; $j++) {
                if ($crc & 0x8000) $crc = (($crc << 1) ^ 0x1021) & 0xFFFF;
                else $crc = ($crc << 1) & 0xFFFF;
            }
        }
        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }

    private static function buildPayloadObj(float $amount, array $merchant, string $mcidFallback): array
    {
        $mcid = trim((string) ($merchant['mcid'] ?? $mcidFallback));
        $nameEn = substr(trim((string) ($merchant['merchantEnglishName'] ?? '')), 0, 25);
        $mcc = trim((string) ($merchant['mcc'] ?? ''));
        $terminalId = trim((string) ($merchant['terminalId'] ?? ''));
        $staffName = trim((string) ($merchant['staffName'] ?? ''));
        $id07 = $terminalId !== '' ? str_replace(' ', '_', $terminalId) : ($staffName !== '' ? str_replace(' ', '_', $staffName) : '');

        $obj = [
            0 => '01', 1 => '11',
            38 => ['00' => 'A005266284662577', '01' => '27710418', '02' => '002', '03' => $mcid],
            52 => $mcc, 53 => '418',
            54 => number_format($amount, 2, '.', ''),
            58 => 'LA', 59 => $nameEn !== '' ? $nameEn : 'ONEPAY SHOP', 60 => 'VTE',
        ];
        if ($id07 !== '') $obj[62] = ['07' => $id07];
        return $obj;
    }

    private static function buildTlvTree(array $obj, bool $nested = false): string
    {
        $out = '';
        foreach ($obj as $key => $val) {
            if ($val === null || $val === '' || $val === 0) continue;
            $inner = is_array($val) ? self::buildTlvTree($val, true) : (string) $val;
            $out .= self::tlv($key, $inner);
        }
        if (!$nested) {
            $suffix = '6304';
            $out .= $suffix . self::crc16Ccitt($out . $suffix);
        }
        return $out;
    }

    public function resetSession(): void
    {
        if (is_file($this->sessionFile)) unlink($this->sessionFile);
        $this->session = [];
        $this->privateKey = $this->loadOrCreatePrivateKey();
    }
}
