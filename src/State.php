<?php
declare(strict_types=1);

namespace Chameloon\Space;

/**
 * server-data.json, in the same shape as the TypeScript server's. `origin` is the device that wrote a row,
 * or "server" for rows a bot wrote.
 * @internal
 */
final class State
{
    public int $seq = 0;
    /** @var array<string,true> op ids already taken (retries repeat them) */
    public array $seen = [];
    /** @var array<string,int> device => last sync, Unix ms */
    public array $devices = [];
    /** @var array<string,string> */
    public array $push = [];
    /** @var array<string,list<array{title:string,body:string}>> */
    public array $notes = [];
    /** @var array<string,array<string,array{row:array<string,mixed>,seq:int,origin:string}>> */
    public array $rows = [];

    public static function fromJson(string $text): self
    {
        $d = Json::decode($text);
        $d = is_array($d) ? $d : [];
        $s = new self();
        $s->seq = (int) ($d['seq'] ?? 0);
        $s->seen = array_fill_keys(array_map('strval', is_array($d['seen'] ?? null) ? $d['seen'] : []), true);
        // Older files kept a plain list of devices.
        $s->devices = is_array($d['devices'] ?? null) && !array_is_list($d['devices']) ? array_map('intval', $d['devices']) : [];
        $s->push = is_array($d['push'] ?? null) ? $d['push'] : [];
        $s->notes = is_array($d['notes'] ?? null) ? $d['notes'] : [];
        $s->rows = is_array($d['rows'] ?? null) ? $d['rows'] : [];
        return $s;
    }

    public function toJson(): string
    {
        // Maps become JSON objects even when empty or keyed by "0", "1", …
        $objects = static fn(array $m): \stdClass => (object) $m;
        return Json::encode([
            'seq' => $this->seq,
            'seen' => array_map('strval', array_keys($this->seen)),
            'devices' => $objects($this->devices),
            'push' => $objects($this->push),
            'notes' => $objects($this->notes),
            'rows' => $objects(array_map(static fn(array $t) => (object) $t, $this->rows)),
        ]);
    }
}
