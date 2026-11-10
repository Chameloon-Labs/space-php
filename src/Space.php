<?php
declare(strict_types=1);

namespace Chameloon\Space;

/** One space folder: its package, its sync and its hook. @internal */
final class Space
{
    public function __construct(private string $dir, private Bot $bot)
    {
    }

    /** The space's access rule from space.json (read each time, so changing it needs no restart). */
    public function auth(): ?string
    {
        $auth = $this->meta()->auth ?? null;
        return is_string($auth) ? $auth : null;
    }

    private function meta(): \stdClass
    {
        $meta = json_decode((string) file_get_contents($this->dir . '/space.json'));
        return $meta instanceof \stdClass ? $meta : new \stdClass();
    }

    /** space.json with `pages` filled in from pages/*.oui; $schema and pageScroll are left out. */
    public function pkg(): \stdClass
    {
        $pkg = $this->meta();
        $scroll = (array) ($pkg->pageScroll ?? []);
        unset($pkg->{'$schema'}, $pkg->pageScroll);
        $pages = [];
        $files = glob($this->dir . '/pages/*') ?: [];
        sort($files);
        foreach ($files as $file) {
            $name = basename($file, '.oui');
            $ui = (string) file_get_contents($file);
            $pages[$name] = !empty($scroll[$name]) ? ['ui' => $ui, 'scroll' => $scroll[$name]] : $ui;
        }
        $pkg->pages = (object) $pages;
        return $pkg;
    }

    /** @return mixed the hook's answer, or null if the bot has no hook */
    public function hook(array $body, array $headers): mixed
    {
        if (!$this->bot->hook) {
            return null;
        }
        return $this->locked(function (State $state, Store $store) use ($body, $headers) {
            return ($this->bot->hook)($body, $store, $headers) ?? ['ok' => true];
        });
    }

    /**
     * @param list<array<string,mixed>> $ops
     * @return array<string,mixed>
     */
    public function sync(string $device, array $ops, int|float $cursor, ?string $push, int|float|null $phoneVersion): array
    {
        return $this->locked(function (State $data, Store $store) use ($device, $ops, $cursor, $push, $phoneVersion) {
            $data->devices[$device] = (int) (microtime(true) * 1000);
            // A real server only posts to the relay it trusts, not to any URL a client sends.
            if ($push !== null && preg_match('{^https?://[^/\s]+/push/[\w-]+\z}', $push)) {
                $data->push[$device] = $push;
            } else {
                unset($data->push[$device]); // notifications off for this space on that phone
            }
            if ($cursor > $data->seq) {
                $cursor = 0; // the server lost its data (or was reset): send everything again
            }
            $done = [];
            foreach ($ops as $o) {
                $id = (string) ($o['id'] ?? '');
                $done[] = $id;
                if (isset($data->seen[$id]) || !is_array($o['row'] ?? null)) {
                    continue; // retries repeat op ids
                }
                $data->seen[$id] = true;
                if (($o['op'] ?? '') === 'event') {
                    $this->bot->onEvent && ($this->bot->onEvent)((string) ($o['table'] ?? ''), $o['row'], $store, $device);
                    continue;
                }
                $table = (string) ($o['table'] ?? '');
                $rowId = (string) ($o['row']['id'] ?? '');
                if (($o['op'] ?? '') === 'delete') {
                    unset($data->rows[$table][$rowId]);
                } else {
                    $data->rows[$table][$rowId] = ['row' => $o['row'], 'seq' => ++$data->seq, 'origin' => $device];
                }
                $this->bot->onOp && ($this->bot->onOp)($o, $store, $device);
            }
            $this->bot->tick && ($this->bot->tick)($store);
            // What changed since this device's cursor, written by the bot or by the user's other devices. Never its own rows.
            $pull = [];
            foreach ($data->rows as $t => $rows) {
                $fresh = [];
                foreach ($rows as $r) {
                    if ($r['origin'] !== $device && $r['seq'] > $cursor) {
                        $fresh[] = $r['row'];
                    }
                }
                if ($fresh) {
                    $pull[$t] = $fresh;
                }
            }
            $notify = $data->notes[$device] ?? [];
            unset($data->notes[$device]);
            $version = $this->meta()->version ?? null;
            $extra = $notify ? ['notify' => $notify] : [];
            // A phone on an older version may not have the columns these rows have yet: it updates first (it syncs
            // again right after) and gets everything from the same cursor then. Its changes were taken above either way.
            if ($phoneVersion !== null && is_numeric($version) && $phoneVersion < $version) {
                return ['done' => $done, 'pull' => new \stdClass(), 'cursor' => $cursor, 'version' => $version] + $extra;
            }
            return ['done' => $done, 'pull' => (object) $pull, 'cursor' => $data->seq, 'version' => $version] + $extra;
        });
    }

    /** Runs $fn with the space's state under a lock held until the state is saved, so requests never interleave. */
    private function locked(callable $fn): mixed
    {
        return Disk::locked($this->dir . '/server-data.json', function (string $raw, callable $write) use ($fn) {
            $state = State::fromJson($raw);
            $result = $fn($state, new Store($state));
            $write($state->toJson());
            return $result;
        });
    }
}
