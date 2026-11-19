<?php
declare(strict_types=1);

require_once __DIR__ . '/mail.php';

function coachingMfaBase32Encode(string $bytes): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $buffer = 0;
    $bits = 0;
    $encoded = '';
    for ($i = 0, $length = strlen($bytes); $i < $length; $i++) {
        $buffer = ($buffer << 8) | ord($bytes[$i]);
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $encoded .= $alphabet[($buffer >> $bits) & 31];
            $buffer &= (1 << $bits) - 1;
        }
    }
    if ($bits > 0) $encoded .= $alphabet[($buffer << (5 - $bits)) & 31];
    return $encoded;
}

function coachingMfaBase32Decode(string $encoded): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $encoded = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $encoded) ?? '');
    $buffer = 0;
    $bits = 0;
    $decoded = '';
    for ($i = 0, $length = strlen($encoded); $i < $length; $i++) {
        $value = strpos($alphabet, $encoded[$i]);
        if ($value === false) continue;
        $buffer = ($buffer << 5) | $value;
        $bits += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $decoded .= chr(($buffer >> $bits) & 255);
            $buffer &= (1 << $bits) - 1;
        }
    }
    return $decoded;
}

function coachingMfaNewSecret(): string
{
    return coachingMfaBase32Encode(random_bytes(20));
}

function coachingMfaTotp(string $secret, ?int $timestamp = null): string
{
    $key = coachingMfaBase32Decode($secret);
    if ($key === '') throw new RuntimeException('Der MFA-Schlüssel ist ungültig.');
    $counter = intdiv($timestamp ?? time(), 30);
    $message = pack('N2', intdiv($counter, 4294967296), $counter % 4294967296);
    $hash = hash_hmac('sha1', $message, $key, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
    $binary = ((ord($hash[$offset]) & 0x7f) << 24)
        | ((ord($hash[$offset + 1]) & 0xff) << 16)
        | ((ord($hash[$offset + 2]) & 0xff) << 8)
        | (ord($hash[$offset + 3]) & 0xff);
    return str_pad((string) ($binary % 1000000), 6, '0', STR_PAD_LEFT);
}

function coachingMfaVerifyTotp(string $secret, string $submitted, ?int $timestamp = null): bool
{
    $code = preg_replace('/\D/', '', $submitted) ?? '';
    if (strlen($code) !== 6) return false;
    $now = $timestamp ?? time();
    foreach ([-30, 0, 30] as $offset) {
        if (hash_equals(coachingMfaTotp($secret, $now + $offset), $code)) return true;
    }
    return false;
}

function coachingMfaEncryptionKey(): string
{
    return hash_hkdf('sha256', coachingMailEncryptionKey(), 32, 'coaching-tool-mfa-secrets-v1');
}

function coachingMfaEncrypt(string $plainText): string
{
    if (!function_exists('openssl_encrypt')) throw new RuntimeException('OpenSSL ist in PHP nicht aktiviert.');
    $nonce = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plainText, 'aes-256-gcm', coachingMfaEncryptionKey(), OPENSSL_RAW_DATA, $nonce, $tag, 'coaching-tool-mfa-v1', 16);
    if (!is_string($cipher)) throw new RuntimeException('Der MFA-Schlüssel konnte nicht geschützt werden.');
    return base64_encode($nonce . $tag . $cipher);
}

function coachingMfaDecrypt(?string $cipherText): string
{
    if (!is_string($cipherText) || $cipherText === '' || !function_exists('openssl_decrypt')) {
        throw new RuntimeException('Der geschützte MFA-Schlüssel ist nicht verfügbar.');
    }
    $raw = base64_decode($cipherText, true);
    if (!is_string($raw) || strlen($raw) < 29) throw new RuntimeException('Der gespeicherte MFA-Schlüssel ist ungültig.');
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', coachingMfaEncryptionKey(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'coaching-tool-mfa-v1');
    if (!is_string($plain)) throw new RuntimeException('Der MFA-Schlüssel kann nicht entschlüsselt werden. Prüfe mail_encryption_key in config.local.php.');
    return $plain;
}

function coachingMfaOtpAuthUri(string $secret, string $accountName): string
{
    $issuer = 'Coaching Tool';
    $label = rawurlencode($issuer . ':' . $accountName);
    return 'otpauth://totp/' . $label . '?secret=' . rawurlencode($secret)
        . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}

function coachingMfaCreateRecoveryCodes(int $count = 8): array
{
    $codes = [];
    $hashes = [];
    for ($i = 0; $i < $count; $i++) {
        $raw = strtoupper(bin2hex(random_bytes(6)));
        $code = substr($raw, 0, 4) . '-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 4);
        $codes[] = $code;
        $hashes[] = hash('sha256', str_replace('-', '', $code));
    }
    return ['codes' => $codes, 'hashes' => $hashes];
}

function coachingMfaConsumeRecoveryCode(string $submitted, array $hashes): ?array
{
    $normalized = strtoupper(preg_replace('/[^A-F0-9]/i', '', $submitted) ?? '');
    if (strlen($normalized) !== 12) return null;
    $candidate = hash('sha256', $normalized);
    foreach ($hashes as $index => $hash) {
        if (is_string($hash) && hash_equals($hash, $candidate)) {
            array_splice($hashes, (int) $index, 1);
            return $hashes;
        }
    }
    return null;
}
