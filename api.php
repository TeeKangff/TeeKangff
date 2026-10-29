<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/OnePlayClient.php';
require_once __DIR__ . '/lib/AccountStore.php';

if (php_sapi_name() !== 'cli') {
    ini_set('display_errors', '0');
}

session_start();

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$input = array_merge($_GET, read_json_input());

function getActiveCode(array $input): string {
    $code = trim((string) ($input['activation_code'] ?? ($_SESSION['oneplay_activation'] ?? DEFAULT_ACTIVATION_CODE)));
    if ($code === '' && class_exists('AccountStore')) {
        $accs = AccountStore::list();
        if (!empty($accs[0]['activation_code'])) {
            $code = (string) $accs[0]['activation_code'];
        }
    }
    return $code;
}

try {
    switch ($action) {
        case 'accounts':
            $accounts = AccountStore::list();
            json_response([
                'status' => 'success',
                'accounts' => array_map(static function (array $row): array {
                    return [
                        'activation_code' => (string) ($row['activation_code'] ?? ''),
                        'owner' => (string) ($row['owner'] ?? ''),
                        'mcid' => (string) ($row['mcid'] ?? ''),
                        'merchant_name' => (string) ($row['merchant_name'] ?? ''),
                        'updated_at' => (string) ($row['updated_at'] ?? ''),
                    ];
                }, $accounts),
                'active' => (string) ($_SESSION['oneplay_activation'] ?? ''),
            ]);

        case 'activate':
            $code = trim((string) ($input['activation_code'] ?? ''));
            $client = new OnePlayClient($code);
            $result = $client->bootstrap();
            $code = OnePlayClient::normalizeActivation($code);
            $_SESSION['oneplay_activation'] = $code;
            $merchant = is_array($result['merchant'] ?? null) ? $result['merchant'] : [];
            AccountStore::upsert($code, [
                'owner' => (string) ($result['owner'] ?? ''),
                'mcid' => (string) ($result['mcid'] ?? ''),
                'merchant_name' => trim((string) ($merchant['merchantLaoName'] ?? $merchant['merchantName'] ?? '')),
            ]);
            json_response(['status' => 'success', 'data' => $result]);

        case 'history':
            $code = getActiveCode($input);
            if ($code === '') {
                json_response(['status' => 'error', 'message' => 'ກະລຸນາເປີດໃຊ້ງານລະຫັດ 12 ຫຼັກຜ່ານ index.php ກ່ອນ'], 400);
            }
            $client = new OnePlayClient($code);
            $history = $client->fetchHistory();
            $rows = is_array($history['histories'] ?? null) ? $history['histories'] : [];
            json_response([
                'status' => 'success',
                'owner' => $history['owner_name'] ?? OnePlayClient::merchantOwnerName($history['merchant'] ?? []),
                'mcid' => $history['mcid'] ?? '',
                'has_more' => (bool) ($history['hasMore'] ?? false),
                'histories' => $rows,
            ]);

        case 'qr':
            $code = getActiveCode($input);
            $amount = (float) str_replace(',', '', (string) ($input['amount'] ?? '0'));
            if ($code === '') {
                json_response(['status' => 'error', 'message' => 'ກະລຸນາເປີດໃຊ້ງານລະຫັດ 12 ຫຼັກຜ່ານ index.php ກ່ອນ'], 400);
            }
            $client = new OnePlayClient($code);
            $data = $client->buildQrPayload($amount);
            json_response(['status' => 'success', 'data' => $data]);

        case 'check_payment':
            $code = getActiveCode($input);
            $expectedAmount = (float) str_replace(',', '', (string) ($input['amount'] ?? '0'));
            if ($code === '' || $expectedAmount <= 0) {
                json_response(['status' => 'error', 'message' => 'ຂໍ້ມູນບໍ່ຄົບຖ້ວນ ຫຼື ຍັງບໍ່ມີ Activation Code'], 400);
            }

            $client = new OnePlayClient($code);
            $history = $client->fetchHistory();
            $rows = is_array($history['histories'] ?? null) ? $history['histories'] : [];

            $isPaid = false;
            $matchedTx = null;

            foreach ($rows as $tx) {
                $txAmt = (float) ($tx['amount'] ?? 0);
                $isVoid = (int) ($tx['isVoid'] ?? 0);
                if ($isVoid === 0 && abs($txAmt - $expectedAmount) < 0.01) {
                    $isPaid = true;
                    $matchedTx = $tx;
                    break;
                }
            }

            json_response([
                'status' => 'success',
                'paid' => $isPaid,
                'transaction' => $matchedTx
            ]);
            break;

        case 'logout':
            unset($_SESSION['oneplay_activation']);
            json_response(['status' => 'success', 'message' => 'ອອກຈາກລະບົບແລ້ວ']);

        case 'reset':
            $code = getActiveCode($input);
            if ($code !== '') {
                (new OnePlayClient($code))->resetSession();
                AccountStore::remove($code);
            }
            unset($_SESSION['oneplay_activation']);
            json_response(['status' => 'success', 'message' => 'ຣີເຊັດ session ແລ້ວ']);

        default:
            json_response(['status' => 'error', 'message' => 'ຄຳສັ່ງບໍ່ຖືກຕ້ອງ'], 400);
    }
} catch (Throwable $e) {
    json_response(['status' => 'error', 'message' => $e->getMessage()], 502);
}
