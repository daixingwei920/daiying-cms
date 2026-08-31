<?php

declare(strict_types=1);

namespace Cms\Core\Auth;

use Cms\Core\Config\Settings;
use PDO;

final class AdminPasskeyService
{
    public function __construct(private readonly PDO $pdo, private readonly Settings $settings)
    {
    }

    /** @return array<string,mixed> */
    public function registrationOptions(int $adminId, string $email, string $displayName): array
    {
        $challenge = $this->randomB64();
        $_SESSION['admin_passkey_registration'] = ['admin_id' => $adminId, 'challenge' => $challenge, 'issued_at' => time()];
        $rpId = $this->rpId();

        return [
            'challenge' => $challenge,
            'rp' => ['name' => (string) ($this->settings->get('site.name', 'Daiying CMS') ?: 'Daiying CMS'), 'id' => $rpId],
            'user' => ['id' => self::b64((string) $adminId), 'name' => $email, 'displayName' => $displayName],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7]],
            'authenticatorSelection' => ['userVerification' => 'required', 'residentKey' => 'preferred'],
            'timeout' => 60000,
            'attestation' => 'none',
        ];
    }

    /** @param array<string,mixed> $data */
    public function register(int $adminId, array $data, string $label): void
    {
        $session = $_SESSION['admin_passkey_registration'] ?? null;
        if (!is_array($session) || (int) ($session['admin_id'] ?? 0) !== $adminId || (int) ($session['issued_at'] ?? 0) < time() - 600) {
            throw new \RuntimeException('Passkey 注册已过期，请重新开始。');
        }
        $clientData = $this->jsonBytes((string) ($data['clientDataJSON'] ?? ''));
        $client = json_decode($clientData, true);
        if (!is_array($client) || (string) ($client['type'] ?? '') !== 'webauthn.create' || !hash_equals((string) $session['challenge'], (string) ($client['challenge'] ?? '')) || !$this->validOrigin((string) ($client['origin'] ?? ''))) {
            throw new \RuntimeException('Passkey 注册挑战无效。');
        }
        $attestation = $this->cbor($this->b64d((string) ($data['attestationObject'] ?? '')));
        $authData = $attestation['authData'] ?? null;
        if (!is_string($authData) || strlen($authData) < 55 || !hash_equals(hash('sha256', $this->rpId(), true), substr($authData, 0, 32))) {
            throw new \RuntimeException('Passkey 注册数据无效。');
        }
        $flags = ord($authData[32]);
        if (($flags & 0x01) !== 0x01 || ($flags & 0x40) !== 0x40) {
            throw new \RuntimeException('Passkey 注册未包含有效凭据。');
        }
        $offset = 37 + 16;
        $length = unpack('n', substr($authData, $offset, 2))[1];
        $offset += 2;
        $credentialId = substr($authData, $offset, $length);
        $publicKeyCose = substr($authData, $offset + $length);
        $this->coseToPem($publicKeyCose);

        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_admin_passkeys (admin_id, credential_id, public_key_cose, sign_count, label, created_at)
             VALUES (:admin_id, :credential_id, :public_key_cose, :sign_count, :label, :created_at)'
        );
        $stmt->execute([
            ':admin_id' => $adminId,
            ':credential_id' => self::b64($credentialId),
            ':public_key_cose' => self::b64($publicKeyCose),
            ':sign_count' => unpack('N', substr($authData, 33, 4))[1],
            ':label' => $this->cleanLabel($label),
            ':created_at' => gmdate('c'),
        ]);
        unset($_SESSION['admin_passkey_registration']);
    }

    public function hasPasskey(int $adminId): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM cms_admin_passkeys WHERE admin_id = :admin_id');
        $stmt->execute([':admin_id' => $adminId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** @return list<array<string,mixed>> */
    public function listForAdmin(int $adminId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, label, created_at, last_used_at FROM cms_admin_passkeys WHERE admin_id = :admin_id ORDER BY id DESC');
        $stmt->execute([':admin_id' => $adminId]);

        return $stmt->fetchAll();
    }

    /** @return array<string,mixed> */
    public function authenticationOptions(int $adminId): array
    {
        $stmt = $this->pdo->prepare('SELECT credential_id FROM cms_admin_passkeys WHERE admin_id = :admin_id ORDER BY id DESC');
        $stmt->execute([':admin_id' => $adminId]);
        $credentials = array_map(static fn (string $id): array => ['type' => 'public-key', 'id' => $id], $stmt->fetchAll(PDO::FETCH_COLUMN));
        if ($credentials === []) {
            throw new \RuntimeException('当前管理员没有可用 Passkey。');
        }
        $challenge = $this->randomB64();
        $_SESSION['admin_passkey_authentication'] = ['admin_id' => $adminId, 'challenge' => $challenge, 'issued_at' => time()];

        return [
            'challenge' => $challenge,
            'rpId' => $this->rpId(),
            'allowCredentials' => $credentials,
            'userVerification' => 'required',
            'timeout' => 60000,
        ];
    }

    /** @param array<string,mixed> $data */
    public function verifyAuthentication(int $adminId, array $data): bool
    {
        $session = $_SESSION['admin_passkey_authentication'] ?? null;
        if (!is_array($session) || (int) ($session['admin_id'] ?? 0) !== $adminId || (int) ($session['issued_at'] ?? 0) < time() - 600) {
            return false;
        }
        $credentialId = (string) ($data['id'] ?? '');
        $stmt = $this->pdo->prepare('SELECT * FROM cms_admin_passkeys WHERE admin_id = :admin_id AND credential_id = :credential_id LIMIT 1');
        $stmt->execute([':admin_id' => $adminId, ':credential_id' => $credentialId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return false;
        }
        $clientData = $this->jsonBytes((string) ($data['clientDataJSON'] ?? ''));
        $client = json_decode($clientData, true);
        if (!is_array($client) || (string) ($client['type'] ?? '') !== 'webauthn.get' || !hash_equals((string) $session['challenge'], (string) ($client['challenge'] ?? '')) || !$this->validOrigin((string) ($client['origin'] ?? ''))) {
            return false;
        }
        $authData = $this->b64d((string) ($data['authenticatorData'] ?? ''));
        if (strlen($authData) < 37 || !hash_equals(hash('sha256', $this->rpId(), true), substr($authData, 0, 32)) || ((ord($authData[32]) & 0x01) !== 0x01)) {
            return false;
        }
        $pem = $this->coseToPem($this->b64d((string) $row['public_key_cose']));
        $signature = $this->b64d((string) ($data['signature'] ?? ''));
        $signed = $authData . hash('sha256', $clientData, true);
        $ok = openssl_verify($signed, $signature, $pem, OPENSSL_ALGO_SHA256) === 1;
        if (!$ok) {
            return false;
        }
        $count = unpack('N', substr($authData, 33, 4))[1];
        $update = $this->pdo->prepare('UPDATE cms_admin_passkeys SET sign_count = :sign_count, last_used_at = :last_used_at WHERE id = :id');
        $update->execute([':id' => (int) $row['id'], ':sign_count' => max((int) $row['sign_count'], $count), ':last_used_at' => gmdate('c')]);
        unset($_SESSION['admin_passkey_authentication']);

        return true;
    }

    public function delete(int $adminId, int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM cms_admin_passkeys WHERE admin_id = :admin_id AND id = :id');
        $stmt->execute([':admin_id' => $adminId, ':id' => $id]);
    }

    public static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function b64d(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        if (!is_string($decoded)) {
            throw new \RuntimeException('Passkey 数据编码无效。');
        }

        return $decoded;
    }

    private function randomB64(): string
    {
        return self::b64(random_bytes(32));
    }

    private function jsonBytes(string $value): string
    {
        $bytes = $this->b64d($value);
        json_decode($bytes, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Passkey 客户端数据无效。');
        }

        return $bytes;
    }

    /** @return array<mixed,mixed> */
    private function cbor(string $bytes): array
    {
        $offset = 0;
        $value = $this->readCbor($bytes, $offset);
        if (!is_array($value)) {
            throw new \RuntimeException('Passkey CBOR 数据无效。');
        }

        return $value;
    }

    private function readCbor(string $bytes, int &$offset): mixed
    {
        $first = ord($bytes[$offset++] ?? "\0");
        $major = $first >> 5;
        $add = $first & 31;
        $len = $this->cborLength($bytes, $offset, $add);
        if ($major === 0) {
            return $len;
        }
        if ($major === 1) {
            return -1 - $len;
        }
        if ($major === 2 || $major === 3) {
            $chunk = substr($bytes, $offset, $len);
            $offset += $len;
            return $chunk;
        }
        if ($major === 4) {
            $items = [];
            for ($i = 0; $i < $len; $i++) {
                $items[] = $this->readCbor($bytes, $offset);
            }
            return $items;
        }
        if ($major === 5) {
            $map = [];
            for ($i = 0; $i < $len; $i++) {
                $map[$this->readCbor($bytes, $offset)] = $this->readCbor($bytes, $offset);
            }
            return $map;
        }
        if ($major === 7) {
            return match ($add) {
                20 => false,
                21 => true,
                22 => null,
                default => null,
            };
        }
        throw new \RuntimeException('Unsupported CBOR value.');
    }

    private function cborLength(string $bytes, int &$offset, int $add): int
    {
        if ($add < 24) {
            return $add;
        }
        if ($add === 24) {
            return ord($bytes[$offset++] ?? "\0");
        }
        if ($add === 25) {
            $value = unpack('n', substr($bytes, $offset, 2))[1];
            $offset += 2;
            return $value;
        }
        if ($add === 26) {
            $value = unpack('N', substr($bytes, $offset, 4))[1];
            $offset += 4;
            return $value;
        }
        throw new \RuntimeException('Unsupported CBOR length.');
    }

    private function coseToPem(string $cose): string
    {
        $key = $this->cbor($cose);
        if (($key[1] ?? null) !== 2 || ($key[3] ?? null) !== -7 || ($key[-1] ?? null) !== 1 || !is_string($key[-2] ?? null) || !is_string($key[-3] ?? null)) {
            throw new \RuntimeException('只支持 ES256 Passkey。');
        }
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d03010703420004') . $key[-2] . $key[-3];
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private function rpId(): string
    {
        $host = (string) parse_url((string) $this->settings->get('site.url', ''), PHP_URL_HOST);
        return strtolower($host !== '' ? $host : (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    }

    private function validOrigin(string $origin): bool
    {
        $site = rtrim((string) $this->settings->get('site.url', ''), '/');
        if ($site === '') {
            return true;
        }
        $scheme = strtolower((string) parse_url($site, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($site, PHP_URL_HOST));
        $port = parse_url($site, PHP_URL_PORT);
        if (!in_array($scheme, ['https', 'http'], true) || $host === '') {
            return false;
        }
        $expected = $scheme . '://' . $host . ($port !== null ? ':' . (int) $port : '');

        return hash_equals($expected, rtrim($origin, '/'));
    }

    private function cleanLabel(string $label): string
    {
        $label = trim(preg_replace('/\s+/u', ' ', strip_tags($label)) ?: '');
        return $label !== '' ? (function_exists('mb_substr') ? mb_substr($label, 0, 80, 'UTF-8') : substr($label, 0, 80)) : 'Passkey';
    }
}
