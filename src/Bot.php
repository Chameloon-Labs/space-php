<?php
declare(strict_types=1);

namespace Chameloon\Space;

use Closure;

/**
 * A space's bot. Every hook is optional:
 *   tick(Store $store)                                          before the pull is built on every sync
 *   onOp(array $op, Store $store, string $device)               for each change a phone sent
 *   onEvent(string $event, mixed $data, Store $store, string $device)   something the app tells the server
 *   hook(array $body, Store $store, array $headers)             POST /<space>/hook; headers have lowercase names
 */
final class Bot
{
    public function __construct(
        public readonly ?Closure $tick = null,
        public readonly ?Closure $onOp = null,
        public readonly ?Closure $onEvent = null,
        public readonly ?Closure $hook = null,
    ) {
    }

    /** From an object with those methods (or closure properties), or an array of closures. */
    public static function from(object|array $bot): self
    {
        if ($bot instanceof self) {
            return $bot;
        }
        $pick = static function (string $name) use ($bot): ?Closure {
            if (is_array($bot)) {
                return isset($bot[$name]) ? Closure::fromCallable($bot[$name]) : null;
            }
            if (method_exists($bot, $name)) {
                return $bot->$name(...);
            }
            return ($bot->$name ?? null) instanceof Closure ? $bot->$name : null;
        };
        return new self($pick('tick'), $pick('onOp'), $pick('onEvent'), $pick('hook'));
    }
}
