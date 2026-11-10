<?php
// Router for the conformance suite: php -S 127.0.0.1:{port} tests/conformance-router.php  (SPACES = the folder)
// The echo space's bot (conformance/README.md) is registered here in code.
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use Chameloon\Space\Server;
use Chameloon\Space\Store;

Server::fromGlobals(getenv('SPACES') ?: ($argv[1] ?? null))
    ->bot('echo', [
        'onOp' => function (array $op, Store $store, string $device): void {
            if ($op['op'] === 'upsert' && $op['table'] === 'notes') {
                $store->put('replies', ['id' => 're-' . $op['row']['id'], 'text' => 'echo: ' . $op['row']['text'], 'device' => $device]);
            }
        },
        'onEvent' => function (string $event, mixed $data, Store $store, string $device): void {
            if ($event === 'ping') {
                $store->put('pongs', ['id' => $data['id'], 'device' => $device]);
            } elseif ($event === 'notify') {
                $store->notify($data['title'], $data['body']);
            }
        },
        'hook' => function (array $body, Store $store, array $headers): array {
            $store->put('inbox', ['id' => $body['id'], 'text' => $body['text']]);
            return ['ok' => true, 'echoed' => $body['text']];
        },
    ])
    ->run();
