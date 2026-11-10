<?php
declare(strict_types=1);

namespace Chameloon\Space;

/**
 * Who may reach a space (space.json "auth": "open" | "token" | "device"), plus the invite and device bookkeeping
 * in <space>/auth.json. The file has the same shape as the TypeScript server's, so a space can move between servers.
 */
final class Auth
{
    private const SKEW_MS = 5 * 60 * 1000;
    private const REPLAY_MS = 10 * 60 * 1000;
    // SubjectPublicKeyInfo header for an uncompressed prime256v1 point: SEQUENCE { SEQUENCE { ecPublicKey, prime256v1 }, BIT STRING }
    private const SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public static function file(string $dir): string
    {
        return $dir . '/auth.json';
    }

    /** @return array<string,mixed> */
    public static function read(string $dir): array
    {
        $text = Disk::read(self::file($dir));
        $auth = $text === null ? null : Json::decode($text);
        return is_array($auth) ? $auth : [];
    }

    /** @param array<string,mixed> $auth */
    private static function encode(array $auth): string
    {
        return Json::encode($auth === [] ? new \stdClass() : $auth, true);
    }

    /** What a phone signs: METHOD, path?query, time and the SHA-256 of the body, one per line. */
    public static function canonical(string $method, string $pathAndQuery, string $time, string $body): string
    {
        return strtoupper($method) . "\n" . $pathAndQuery . "\n" . $time . "\n" . hash('sha256', $body);
    }

    /**
     * Checks a request against the space's access rule.
     * @param array<string,string> $headers lowercase names
     * @return array{int,string}|null [status, sentence] when refused, null when the request may pass
     */
    public static function check(string $dir, ?string $mode, string $method, string $pathAndQuery, array $headers, string $body): ?array
    {
        if ($mode === null || $mode === 'open') {
            return null;
        }
        $auth = self::read($dir);
        if ($mode === 'token') {
            $given = preg_replace('/^Bearer /', '', $headers['authorization'] ?? '');
            $token = $auth['token'] ?? '';
            return is_string($token) && $token !== '' && hash_equals($token, $given) ? null : [401, 'This space needs its token.'];
        }

        $key = $headers['x-chameloon-key'] ?? '';
        $time = $headers['x-chameloon-time'] ?? '';
        $sig = $headers['x-chameloon-signature'] ?? '';
        if ($key === '' || $time === '' || $sig === '') {
            return [401, 'This space needs a signed request from the Chameloon app.'];
        }
        $now = (int) (microtime(true) * 1000);
        if (!is_numeric($time) || !(abs($now - (float) $time) < self::SKEW_MS)) {
            return [401, "The phone's clock is off by more than 5 minutes."];
        }

        // One lock from check to remember, so two requests with the same signature can't both pass. Only requests that
        // got in are remembered: a refused one (no invite yet) may be sent again with the invite.
        return Disk::locked($dir . '/.chameloon-replay.json', function (string $raw, callable $write) use ($dir, $auth, $headers, $key, $sig, $time, $method, $pathAndQuery, $body, $now): ?array {
            $seen = Json::decode($raw ?: '{}');
            $seen = is_array($seen) ? array_filter($seen, static fn($t) => is_int($t) && $now - $t <= self::REPLAY_MS) : [];
            // The cache key is what was signed, not the signature: ECDSA signatures are malleable (s and n-s both verify).
            $canonical = self::canonical($method, $pathAndQuery, $time, $body);
            if (!self::verify($key, $sig, $canonical)) {
                return [401, "The signature doesn't match."];
            }
            $id = hash('sha256', $key . "\n" . $canonical);
            if (isset($seen[$id])) {
                $write(Json::encode($seen === [] ? new \stdClass() : $seen));
                return [401, 'This request was already used.'];
            }
            $denied = self::admit($dir, $auth, $key, $headers['x-chameloon-invite'] ?? '', $headers['x-chameloon-device'] ?? null);
            if ($denied === null) {
                $seen[$id] = $now;
                $write(Json::encode($seen));
            }
            return $denied;
        });
    }

    /** A known phone passes; a new one needs an invite code, which is used up by one. @return array{int,string}|null */
    private static function admit(string $dir, array $auth, string $key, string $code, ?string $device): ?array
    {
        if (self::knows($auth, $key)) {
            return null;
        }
        $ok = Disk::locked(self::file($dir), function (string $raw, callable $write) use ($code, $key, $device): bool {
            $auth = Json::decode($raw ?: '{}');
            $auth = is_array($auth) ? $auth : [];
            if (self::knows($auth, $key)) {
                return true;
            }
            $invites = $auth['invites'] ?? [];
            foreach ($invites as $i => $invite) {
                $uses = $invite['uses'] ?? null;
                if ($code !== '' && hash_equals((string) ($invite['code'] ?? ''), $code) && ($uses === null || $uses > 0)) {
                    if ($uses !== null) {
                        $invites[$i]['uses'] = $uses - 1;
                    }
                    $auth['invites'] = array_values(array_filter($invites, static fn($v) => ($v['uses'] ?? null) === null || $v['uses'] > 0));
                    $auth['devices'] = [...($auth['devices'] ?? []), array_filter([
                        'key' => $key,
                        'device' => $device,
                        'added' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
                        'note' => $invite['note'] ?? null,
                    ], static fn($v) => $v !== null)];
                    $write(self::encode($auth));
                    return true;
                }
            }
            return false;
        });
        return $ok ? null : [401, 'This space needs an invite code.'];
    }

    /** @param array<string,mixed> $auth */
    private static function knows(array $auth, string $key): bool
    {
        foreach ($auth['devices'] ?? [] as $d) {
            if (($d['key'] ?? null) === $key) {
                return true;
            }
        }
        return false;
    }

    /** ECDSA P-256 / SHA-256. The key is X9.63 (65 bytes) and the signature raw r||s (64 bytes), both base64. */
    public static function verify(string $keyB64, string $sigB64, string $data): bool
    {
        $point = base64_decode($keyB64, true);
        $raw = base64_decode($sigB64, true);
        if ($point === false || $raw === false || strlen($point) !== 65 || $point[0] !== "\x04" || strlen($raw) !== 64) {
            return false;
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin(self::SPKI_PREFIX) . $point), 64, "\n") . "-----END PUBLIC KEY-----\n";
        // openssl wants the signature as DER: SEQUENCE { INTEGER r, INTEGER s }
        $der = self::derInt(substr($raw, 0, 32)) . self::derInt(substr($raw, 32));
        $der = "\x30" . chr(strlen($der)) . $der;
        return @openssl_verify($data, $der, $pem, OPENSSL_ALGO_SHA256) === 1;
    }

    /** A minimal positive ASN.1 INTEGER: no leading zeros, one 0x00 in front when the high bit is set. */
    private static function derInt(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || ord($bytes[0]) >= 0x80) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . chr(strlen($bytes)) . $bytes;
    }

    /** A new invite code like "K7QM-3XRP" (no 0/O/1/I). */
    public static function newInvite(string $dir, ?int $uses = 1, ?string $note = null): string
    {
        $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $raw = '';
        for ($i = 0; $i < 8; $i++) {
            $raw .= $abc[random_int(0, strlen($abc) - 1)];
        }
        $code = substr($raw, 0, 4) . '-' . substr($raw, 4);
        Disk::locked(self::file($dir), function (string $text, callable $write) use ($code, $uses, $note): void {
            $auth = Json::decode($text ?: '{}');
            $auth = is_array($auth) ? $auth : [];
            $auth['invites'] = [...($auth['invites'] ?? []), ['code' => $code, 'uses' => $uses] + ($note ? ['note' => $note] : [])];
            $write(self::encode($auth));
        });
        return $code;
    }

    /** Removes the phones whose key or phone id starts with $prefix; returns how many. */
    public static function revoke(string $dir, string $prefix): int
    {
        return Disk::locked(self::file($dir), function (string $text, callable $write) use ($prefix): int {
            $auth = Json::decode($text ?: '{}');
            $auth = is_array($auth) ? $auth : [];
            $before = $auth['devices'] ?? [];
            $after = array_values(array_filter($before, static fn($d) => !(str_starts_with($d['key'] ?? '', $prefix) || (isset($d['device']) && str_starts_with($d['device'], $prefix)))));
            $auth['devices'] = $after;
            $write(self::encode($auth));
            return count($before) - count($after);
        });
    }
}
