<?php
declare(strict_types=1);
require_once __DIR__ . '/OnePlayClient.php';

final class AccountStore
{
    private const INDEX_FILE = SESSION_DIR . '/accounts.json';

    public static function list(): array
    {
        self::patchSessionCodes();
        self::syncFromSessions();
        if (!is_file(self::INDEX_FILE)) return [];
        $data = json_decode((string) file_get_contents(self::INDEX_FILE), true);
        if (!is_array($data)) return [];
        usort($data, static fn(array $a, array $b): int => strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? '')));
        return $data;
    }

    public static function upsert(string $activationCode, array $meta): void
    {
        $code = OnePlayClient::normalizeActivation($activationCode);
        $accounts = self::readIndex();
        $found = false;
        foreach ($accounts as &$row) {
            if (($row['activation_code'] ?? '') === $code) {
                $row = array_merge($row, $meta, ['activation_code' => $code, 'updated_at' => date('Y-m-d H:i:s')]);
                $found = true;
                break;
            }
        }
        unset($row);
        if (!$found) {
            $accounts[] = array_merge($meta, ['activation_code' => $code, 'added_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        }
        self::writeIndex($accounts);
    }

    public static function remove(string $activationCode): void
    {
        $code = OnePlayClient::normalizeActivation($activationCode);
        $accounts = array_values(array_filter(self::readIndex(), static fn(array $row): bool => ($row['activation_code'] ?? '') !== $code));
        self::writeIndex($accounts);
    }

    public static function syncFromSessions(): void
    {
        $files = glob(SESSION_DIR . '/ac_*.json') ?: [];
        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data) || empty($data['activation_code'])) continue;
            $merchant = is_array($data['active_merchant'] ?? null) ? $data['active_merchant'] : [];
            self::upsert((string) $data['activation_code'], [
                'owner' => OnePlayClient::merchantOwnerName($merchant),
                'mcid' => (string) ($data['mcid'] ?? ''),
                'merchant_name' => trim((string) ($merchant['merchantLaoName'] ?? $merchant['merchantName'] ?? '')),
            ]);
        }
    }

    private static function patchSessionCodes(): void
    {
        foreach (self::readIndex() as $row) {
            $code = (string) ($row['activation_code'] ?? '');
            if ($code === '') continue;
            $file = SESSION_DIR . '/ac_' . hash('sha256', $code) . '.json';
            if (!is_file($file)) continue;
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data) || !empty($data['activation_code'])) continue;
            $data['activation_code'] = $code;
            file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
    }

    private static function readIndex(): array
    {
        if (!is_file(self::INDEX_FILE)) return [];
        $data = json_decode((string) file_get_contents(self::INDEX_FILE), true);
        return is_array($data) ? $data : [];
    }

    private static function writeIndex(array $accounts): void
    {
        file_put_contents(self::INDEX_FILE, json_encode(array_values($accounts), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}
