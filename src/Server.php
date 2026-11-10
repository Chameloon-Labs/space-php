<?php
declare(strict_types=1);

namespace Chameloon\Space;

/**
 * A server for any number of spaces: /<space>/space, /<space>/sync, /<space>/img/<file>, /<space>/hook.
 * Each space lives in <root>/<name>/ (space.json + pages/*.oui + optional img/ and bot.php).
 * The server keeps no state in memory: it is built fresh for every request.
 */
final class Server
{
    private const TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'svg' => 'image/svg+xml'];

    /** @var array<string,Bot> */
    private array $bots = [];

    /** With $token, every request needs `Authorization: Bearer <token>`. */
    public function __construct(private string $root, private ?string $token = null)
    {
    }

    /** The server for the SPACES and TOKEN environment variables (or the given folder and token). */
    public static function fromGlobals(?string $root = null, ?string $token = null): self
    {
        return new self($root ?? (getenv('SPACES') ?: 'spaces'), $token ?? (getenv('TOKEN') ?: null));
    }

    /** Registers a space's bot in code. It wins over the space's bot.php. @param object|array<string,callable> $bot */
    public function bot(string $space, object|array $bot): self
    {
        $this->bots[$space] = Bot::from($bot);
        return $this;
    }

    /** Answers the request PHP received and sends the answer. */
    public function run(): void
    {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (str_starts_with($name, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = (string) $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $from => $to) {
            if (isset($_SERVER[$from])) {
                $headers[$to] = (string) $_SERVER[$from];
            }
        }
        $headers['authorization'] ??= $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        $this->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/', $headers, (string) file_get_contents('php://input'))->send();
    }

    /**
     * @param string $pathWithQuery exactly as requested: the phone signs it
     * @param array<string,string|list<string>> $headers any case
     */
    public function handle(string $method, string $pathWithQuery, array $headers, string $body): Response
    {
        $h = [];
        foreach ($headers as $name => $value) {
            $h[strtolower((string) $name)] = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
        }
        $method = strtoupper($method);
        if ($this->token !== null && $this->token !== '' && ($h['authorization'] ?? null) !== "Bearer $this->token") {
            return Response::text('unauthorized', 401);
        }
        $path = explode('?', $pathWithQuery, 2)[0];
        [, $name, $route, $file] = [...explode('/', $path), '', '', '', ''];
        if ($name === '') {
            return Response::json($this->names());
        }
        $dir = $this->dir($name);
        if ($dir === null) {
            return Response::text('no such space', 404);
        }
        $space = new Space($dir, $this->botFor($name, $dir));
        // The space's own access rule (Auth); hooks come from other services and check their caller in the bot.
        if ($route !== 'hook') {
            $denied = Auth::check($dir, $space->auth(), $method, $pathWithQuery, $h, $body);
            if ($denied) {
                return Response::json(['error' => $denied[1]], $denied[0]);
            }
        }
        if ($method === 'GET' && $route === 'space') {
            return Response::json($space->pkg());
        }
        if ($method === 'POST' && $route === 'sync') {
            $req = $body === '' ? [] : Json::decode($body);
            $device = is_array($req) ? ($req['device'] ?? null) : null;
            if (!is_string($device) || $device === '') {
                return Response::text('sync needs a device id', 400);
            }
            $ops = is_array($req['ops'] ?? null) ? array_values(array_filter($req['ops'], 'is_array')) : [];
            $cursor = $req['cursor'] ?? 0;
            $cursor = is_numeric($cursor) ? $cursor + 0 : 0;
            $push = is_string($req['push'] ?? null) ? $req['push'] : null;
            $version = $req['version'] ?? null;
            return Response::json($space->sync($device, $ops, $cursor, $push, is_int($version) || is_float($version) ? $version : null));
        }
        if ($method === 'POST' && $route === 'hook') {
            $payload = Json::decode($body);
            if (!is_array($payload) || !str_starts_with(ltrim($body), '{')) {
                return Response::text('hook needs a JSON object', 400);
            }
            $answer = $space->hook($payload, $h);
            return $answer === null ? Response::text('this space has no hook', 404) : Response::json($answer);
        }
        if ($method === 'GET' && $route === 'img' && preg_match('/^[\w-]+\.(png|jpe?g|svg)\z/', $file, $m)) {
            $path = "$dir/img/$file";
            if (is_file($path)) {
                return new Response(200, ['Content-Type' => self::TYPES[$m[1]]], (string) file_get_contents($path));
            }
        }
        return Response::text('not found', 404);
    }

    /** @return list<string> */
    private function names(): array
    {
        $names = [];
        foreach (scandir($this->root) ?: [] as $n) {
            if ($this->dir($n) !== null) {
                $names[] = $n;
            }
        }
        return $names;
    }

    private function dir(string $name): ?string
    {
        $dir = "$this->root/$name";
        return preg_match('/^[a-z0-9-]+\z/', $name) && is_file("$dir/space.json") ? $dir : null;
    }

    private function botFor(string $name, string $dir): Bot
    {
        if (isset($this->bots[$name])) {
            return $this->bots[$name];
        }
        if (is_file("$dir/bot.php")) {
            $bot = (static fn(string $file) => require $file)("$dir/bot.php");
            if (is_object($bot) || is_array($bot)) {
                return Bot::from($bot);
            }
        }
        return new Bot();
    }
}
