<?php
declare(strict_types=1);

namespace Chameloon\Space;

/** What a bot gets: the rows the server holds, and a way to send rows and notifications to the phones. */
final class Store
{
    private const WEEK_MS = 7 * 24 * 3600 * 1000;

    /** @internal */
    public function __construct(private State $state)
    {
    }

    /** @return list<array<string,mixed>> */
    public function rows(string $table): array
    {
        return array_values(array_map(static fn($r) => $r['row'], $this->state->rows[$table] ?? []));
    }

    /** Saves a row and sends it to every phone on its next sync. @param array<string,mixed> $row needs an "id" */
    public function put(string $table, array $row): void
    {
        $this->state->rows[$table][(string) $row['id']] = ['row' => $row, 'seq' => ++$this->state->seq, 'origin' => 'server'];
    }

    /**
     * A notification for every phone that synced in the last 7 days, delivered with its next sync. A phone with a push
     * relay gets it there right away; if the relay fails, it waits for the next sync like the others (20 at most).
     */
    public function notify(string $title, string $body): void
    {
        $cutoff = (int) (microtime(true) * 1000) - self::WEEK_MS;
        foreach ($this->state->devices as $device => $at) {
            $device = (string) $device;
            if ($at < $cutoff) {
                unset($this->state->devices[$device], $this->state->notes[$device]);
                continue;
            }
            $url = $this->state->push[$device] ?? null;
            if ($url !== null && self::relay($url, ['title' => $title, 'body' => $body])) {
                continue;
            }
            unset($this->state->push[$device]);
            $queue = $this->state->notes[$device] ?? [];
            $queue[] = ['title' => $title, 'body' => $body];
            $this->state->notes[$device] = array_slice($queue, -20);
        }
    }

    /** @param array<string,string> $payload */
    private static function relay(string $url, array $payload): bool
    {
        $json = Json::encode($payload);
        if (function_exists('curl_init')) {
            $c = curl_init($url);
            curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
            curl_exec($c);
            $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
            return $status >= 200 && $status < 300;
        }
        $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $json, 'timeout' => 5, 'ignore_errors' => true]]);
        if (@file_get_contents($url, false, $context) === false) {
            return false;
        }
        return preg_match('{^HTTP/\S+ 2\d\d}', $http_response_header[0] ?? '') === 1;
    }
}
