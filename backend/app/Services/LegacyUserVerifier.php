<?php
namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Opt-in verification against legacy Usuarios. Never imports passwords, grants
 * roles, registers accounts or works over unverified MySQL connections.
 */
final class LegacyUserVerifier
{
    public function verify(User $user, string $password): bool
    {
        if (!filter_var(env('CIS_LEGACY_LOGIN_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }
        if (!$user->active || trim((string) $user->legacy_username) === '') {
            return false;
        }
        $ca = (string) env('LEGACY_DB_SSL_CA', '');
        if ($ca === '' || !is_readable($ca)) {
            return false;
        }
        // Identifiers must be explicitly configured. Never interpolate user input.
        $table = (string) env('LEGACY_AUTH_TABLE', 'Usuarios');
        $nameColumn = (string) env('LEGACY_AUTH_USERNAME_COLUMN', '');
        $hashColumn = (string) env('LEGACY_AUTH_PASSWORD_HASH_COLUMN', '');
        foreach ([$table, $nameColumn, $hashColumn] as $identifier) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $identifier)) {
                return false;
            }
        }
        if ($nameColumn === $hashColumn) {
            return false;
        }

        try {
            $db = DB::connection('mysql_legacy');
            // The connection sets PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT=true
            // with a configured CA; verify encryption actually negotiated.
            $tls = $db->selectOne("SHOW SESSION STATUS LIKE 'Ssl_cipher'");
            $cipher = (string) (array_values((array) $tls)[1] ?? '');
            if (trim($cipher) === '') {
                return false;
            }
            $q = static fn (string $name): string => '`'.$name.'`';
            $records = $db->select(
                'SELECT '.$q($hashColumn).' AS legacy_hash FROM '.$q($table).
                ' WHERE '.$q($nameColumn).' = ? LIMIT 2',
                [$user->legacy_username]
            );
            if (count($records) !== 1) {
                return false;
            }
            $stored = (string) ($records[0]->legacy_hash ?? '');
            $info = password_get_info($stored);
            // Never accept plaintext, MD5 or SHA1 legacy passwords.
            if (!in_array($info['algoName'] ?? 'unknown', ['bcrypt','argon2i','argon2id'], true)) {
                return false;
            }
            return password_verify($password, $stored);
        } catch (\Throwable $exception) {
            // Do not include connection errors or sensitive database data in responses.
            report(new \RuntimeException('Legacy auth verification failed: '.get_class($exception)));
            return false;
        }
    }
}
