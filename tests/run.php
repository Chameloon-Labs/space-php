<?php
// Plain test script (no PHPUnit needed): php tests/run.php. Exits non-zero when a test fails.
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use Chameloon\Space\Auth;
use Chameloon\Space\Server;
use Chameloon\Space\Store;

$failed = 0;
function test(string $name, callable $fn): void
{
    global $failed;
    try {
        $fn();
        echo "  ok   $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  FAIL $name\n       {$e->getMessage()}\n";
    }
}
function eq(mixed $actual, mixed $expected, string $what): void
{
    if ($actual !== $expected) {
        throw new Exception("$what: expected " . json_encode($expected) . ', got ' . json_encode($actual));
    }
}

/** A fresh root with an open, a token and a device space. */
function root(): string
{
    $root = sys_get_temp_dir() . '/chameloon-php-' . bin2hex(random_bytes(4));
    foreach (['open' => 'open', 'shared' => 'token', 'locked' => 'device'] as $name => $auth) {
        mkdir("$root/$name/pages", 0777, true);
        file_put_contents("$root/$name/space.json", json_encode(['name' => $name, 'version' => 3, 'auth' => $auth, '$schema' => 'x', 'pageScroll' => ['chat' => 'bottom']]));
        file_put_contents("$root/$name/pages/home.oui", "home\n");
        file_put_contents("$root/$name/pages/chat.oui", "chat\n");
    }
    file_put_contents("$root/shared/auth.json", '{"token":"s3cret"}');
    file_put_contents("$root/locked/auth.json", '{"invites":[{"code":"ONCE-ONCE","uses":1},{"code":"MANY-MANY","uses":null,"note":"anyone"}]}');
    return $root;
}

/** A phone with its own P-256 key, signing like the app (openssl gives DER; the app sends raw r||s). */
final class Phone
{
    public string $key;
    private $private;
    public function __construct(public string $device = 'phone-1')
    {
        $this->private = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ec = openssl_pkey_get_details($this->private)['ec'];
        $this->key = base64_encode("\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT));
    }

    /** @return array<string,string> */
    public function headers(string $method, string $path, string $body = '', ?string $invite = null, ?int $time = null): array
    {
        usleep(2000); // the same request in the same millisecond is a replay
        $time = (string) ($time ?? (int) (microtime(true) * 1000));
        openssl_sign(Auth::canonical($method, $path, $time, $body), $der, $this->private, OPENSSL_ALGO_SHA256);
        $h = ['X-Chameloon-Key' => $this->key, 'X-Chameloon-Time' => $time, 'X-Chameloon-Signature' => base64_encode(self::raw($der)), 'X-Chameloon-Device' => $this->device];
        return $invite ? $h + ['X-Chameloon-Invite' => $invite] : $h;
    }

    private static function raw(string $der): string
    {
        $r = $s = null;
        $offset = 4; // SEQUENCE header, INTEGER tag
        $len = ord($der[3]);
        $r = substr($der, $offset, $len);
        $s = substr($der, $offset + $len + 2, ord($der[$offset + $len + 1]));
        return str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    }
}

function sync(Server $s, string $space, string $device, array $ops = [], mixed $cursor = 0, array $extra = []): array
{
    $r = $s->handle('POST', "/$space/sync", [], json_encode(['device' => $device, 'ops' => $ops, 'cursor' => $cursor] + $extra));
    eq($r->status, 200, 'sync status');
    return json_decode($r->body, true);
}
function op(string $table, array $row, string $kind = 'upsert'): array
{
    return ['id' => bin2hex(random_bytes(6)), 'op' => $kind, 'table' => $table, 'row' => $row, 'at' => gmdate('c')];
}

echo "auth\n";
test('token space', function () {
    $s = new Server(root());
    eq($s->handle('GET', '/shared/space', [], '')->status, 401, 'no token');
    eq($s->handle('GET', '/shared/space', ['authorization' => 'Bearer nope'], '')->status, 401, 'wrong token');
    eq($s->handle('GET', '/shared/space', ['Authorization' => 'Bearer s3cret'], '')->status, 200, 'right token');
});
test('server-wide token', function () {
    $s = new Server(root(), 'abc');
    eq($s->handle('GET', '/', [], '')->status, 401, 'none');
    eq($s->handle('GET', '/', ['Authorization' => 'Bearer abc'], '')->status, 200, 'right');
});
test('device space: invite once, then key alone', function () {
    $s = new Server($root = root());
    $p = new Phone();
    $get = fn(?string $invite = null) => $s->handle('GET', '/locked/space', $p->headers('GET', '/locked/space', '', $invite), '');
    $none = $get();
    eq($none->status, 401, 'no invite');
    eq(json_decode($none->body, true)['error'], 'This space needs an invite code.', 'sentence');
    eq($get('WRONG-CODE')->status, 401, 'wrong invite');
    eq($get('ONCE-ONCE')->status, 200, 'invite');
    eq($get()->status, 200, 'known now');
    eq((new Server($root))->handle('GET', '/locked/space', (new Phone())->headers('GET', '/locked/space', '', 'ONCE-ONCE'), '')->status, 401, 'used-up invite');
    $auth = Auth::read("$root/locked");
    eq(count($auth['invites']), 1, 'used-up invite removed');
    eq($auth['devices'][0]['key'], $p->key, 'device stored');
    eq($auth['devices'][0]['device'], 'phone-1', 'device id stored');
});
test('device space: replay, forgery, tampering, clock, query', function () {
    $s = new Server(root());
    $p = new Phone();
    eq($s->handle('GET', '/locked/space', $p->headers('GET', '/locked/space', '', 'MANY-MANY'), '')->status, 200, 'in');
    $h = $p->headers('GET', '/locked/space');
    eq($s->handle('GET', '/locked/space', $h, '')->status, 200, 'first');
    $again = $s->handle('GET', '/locked/space', $h, '');
    eq([$again->status, json_decode($again->body, true)['error']], [401, 'This request was already used.'], 'replay');
    eq($s->handle('GET', '/locked/space', ['X-Chameloon-Signature' => base64_encode(str_repeat("\0", 64))] + $p->headers('GET', '/locked/space'), '')->status, 401, 'forged');
    eq($s->handle('POST', '/locked/sync', $p->headers('POST', '/locked/sync', '{"a":1}'), '{"a":2}')->status, 401, 'body changed');
    eq($s->handle('GET', '/locked/space?x=2', $p->headers('GET', '/locked/space?x=1'), '')->status, 401, 'query changed');
    eq($s->handle('GET', '/locked/space?x=1', $p->headers('GET', '/locked/space?x=1'), '')->status, 200, 'query signed');
    eq($s->handle('GET', '/locked/space', $p->headers('GET', '/locked/space', '', null, (time() - 600) * 1000), '')->status, 401, '10 min behind');
    eq($s->handle('GET', '/locked/space', $p->headers('GET', '/locked/space', '', null, (time() + 600) * 1000), '')->status, 401, '10 min ahead');
    eq($s->handle('GET', '/locked/space', $p->headers('GET', '/locked/space', '', null, (time() - 120) * 1000), '')->status, 200, '2 min off');
});
test('signatures with leading zero bytes and high bits verify', function () {
    $p = new Phone();
    for ($i = 0; $i < 60; $i++) {
        $h = $p->headers('GET', "/x$i");
        eq(Auth::verify($h['X-Chameloon-Key'], $h['X-Chameloon-Signature'], Auth::canonical('GET', "/x$i", $h['X-Chameloon-Time'], '')), true, "signature $i");
    }
});

echo "invites and devices\n";
test('newInvite, revoke', function () {
    $dir = root() . '/locked';
    $code = Auth::newInvite($dir, null, 'Anna');
    eq(preg_match('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $code), 1, 'format');
    $last = array_pop(Auth::read($dir)['invites']);
    eq($last, ['code' => $code, 'uses' => null, 'note' => 'Anna'], 'stored');
    file_put_contents("$dir/auth.json", json_encode(['devices' => [['key' => 'AAAA', 'device' => 'one', 'added' => 'x'], ['key' => 'BBBB', 'added' => 'x']]]));
    eq(Auth::revoke($dir, 'on'), 1, 'by phone id');
    eq(Auth::revoke($dir, 'zz'), 0, 'nothing');
    eq(Auth::revoke($dir, 'BB'), 1, 'by key');
});

echo "sync\n";
test('package: pages, pageScroll, no $schema', function () {
    $p = json_decode((new Server(root()))->handle('GET', '/open/space', [], '')->body, true);
    eq($p['pages'], ['chat' => ['ui' => "chat\n", 'scroll' => 'bottom'], 'home' => "home\n"], 'pages');
    eq(isset($p['$schema']) || isset($p['pageScroll']), false, 'left out');
});
test('rows reach other phones, never their writer; retries, deletes, lost data', function () {
    $s = new Server(root());
    $o = op('notes', ['id' => 'n1', 'text' => 'hello']);
    $a = sync($s, 'open', 'a', [$o]);
    eq($a['done'], [$o['id']], 'done');
    eq($a['pull'], [], 'no own rows');
    $b = sync($s, 'open', 'b');
    eq($b['pull']['notes'], [['id' => 'n1', 'text' => 'hello']], 'row as sent');
    eq(sync($s, 'open', 'b', [], $b['cursor'])['pull'], [], 'nothing new');
    sync($s, 'open', 'a', [$o]);
    eq(sync($s, 'open', 'b', [], $b['cursor'])['pull'], [], 'retry stored once');
    sync($s, 'open', 'a', [op('notes', ['id' => 'n1', 'text' => 'two'])]);
    eq(sync($s, 'open', 'b', [], (string) $b['cursor'])['pull']['notes'][0]['text'], 'two', 'change, numeric string cursor');
    sync($s, 'open', 'a', [op('notes', ['id' => 'n1'], 'delete')]);
    eq(sync($s, 'open', 'c')['pull'], [], 'deleted');
    sync($s, 'open', 'a', [op('notes', ['id' => 'n2'])]);
    eq(count(sync($s, 'open', 'c', [], 999999)['pull']['notes']), 1, 'cursor from the future');
});
test('numeric row ids and empty tables keep their JSON shape', function () {
    $root = root();
    $s = new Server($root);
    sync($s, 'open', 'a', [op('t', ['id' => '0']), op('t', ['id' => '1'])]);
    $data = json_decode(file_get_contents("$root/open/server-data.json"));
    eq(array_map('strval', array_keys((array) $data->rows->t)), ['0', '1'], 'rows keyed by id');
    eq(json_decode(file_get_contents("$root/open/server-data.json"), true)['push'], [], 'push is an object');
    eq(str_contains(file_get_contents("$root/open/server-data.json"), '"push":{}'), true, 'push {}');
});
test('older phone version: ops taken, no rows, cursor unchanged', function () {
    $s = new Server(root());
    sync($s, 'open', 'a', [op('notes', ['id' => 'n1'])]);
    $old = sync($s, 'open', 'b', [op('notes', ['id' => 'n2'])], 0, ['version' => 2]);
    eq([$old['pull'], $old['cursor'], $old['version'], count($old['done'])], [[], 0, 3, 1], 'old phone');
    eq(count(sync($s, 'open', 'b', [], 0, ['version' => 3])['pull']['notes']), 1, 'after update');
});
test('sync needs a device', function () {
    $s = new Server(root());
    eq($s->handle('POST', '/open/sync', [], '{"ops":[]}')->status, 400, 'none');
    eq($s->handle('POST', '/open/sync', [], '{"device":""}')->status, 400, 'empty');
});
test('404s', function () {
    $s = new Server(root());
    foreach (['/nope/space', '/Open/space', '/open/nothing', '/open/img/missing.png', '/open/img/..%2Fspace.json', '/open/img/space.json'] as $path) {
        eq($s->handle('GET', $path, [], '')->status, 404, $path);
    }
    eq(json_decode($s->handle('GET', '/', [], '')->body), ['locked', 'open', 'shared'], 'list');
});

echo "bots\n";
test('bot in code and bot.php: onOp, onEvent, tick, notify, hook', function () {
    $root = root();
    file_put_contents("$root/open/bot.php", '<?php return ["hook" => fn(array $b, $store, array $h) => ["got" => $b["x"], "ua" => $h["user-agent"] ?? null]];');
    $s = (new Server($root))->bot('shared', [
        'tick' => fn(Store $st) => $st->put('ticks', ['id' => 't']),
    ]);
    $h = $s->handle('POST', '/open/hook', ['User-Agent' => 'n8n'], '{"x":5}');
    eq(json_decode($h->body, true), ['got' => 5, 'ua' => 'n8n'], 'bot.php hook');
    eq($s->handle('POST', '/open/hook', [], '[1]')->status, 400, 'not an object');
    eq($s->handle('POST', '/locked/hook', [], '{}')->status, 404, 'no hook');
    $s2 = (new Server($root))->bot('open', new class {
        public function onEvent(string $e, mixed $d, Store $st, string $device): void
        {
            $st->notify($e, $d['body']);
        }
        public function tick(Store $st): void
        {
            $st->put('ticks', ['id' => count($st->rows('ticks')) . '']);
        }
    });
    sync($s2, 'open', 'a');
    $r = sync($s2, 'open', 'a', [op('hi', ['body' => 'there'], 'event')]);
    eq($r['notify'], [['title' => 'hi', 'body' => 'there']], 'notify with the next sync');
    eq(sync($s2, 'open', 'a')['notify'] ?? [], [], 'only once');
    eq(count(sync($s2, 'open', 'b')['pull']['ticks']), 4, 'tick rows go to everyone');
});
test('notify: a failing relay queues the note', function () {
    $s = (new Server(root()))->bot('open', ['onEvent' => fn($e, $d, Store $st) => $st->notify('t', 'b')]);
    sync($s, 'open', 'a', [], 0, ['push' => 'http://127.0.0.1:1/push/abc']);
    $r = sync($s, 'open', 'a', [op('x', ['id' => '1'], 'event')], 0, ['push' => 'http://127.0.0.1:1/push/abc']);
    eq($r['notify'], [['title' => 't', 'body' => 'b']], 'queued');
});

echo $failed ? "\n$failed failed\n" : "\nall passed\n";
exit($failed ? 1 : 0);
