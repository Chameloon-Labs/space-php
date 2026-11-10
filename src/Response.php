<?php
declare(strict_types=1);

namespace Chameloon\Space;

/** What the server answers: plain data, so any framework can turn it into its own response. */
final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    public static function json(mixed $value, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'application/json'], Json::encode($value));
    }

    public static function text(string $text, int $status): self
    {
        return new self($status, ['Content-Type' => 'text/plain; charset=utf-8'], $text);
    }

    /** Writes the answer with PHP's own functions (plain PHP, php -S, FPM). */
    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        echo $this->body;
    }
}
