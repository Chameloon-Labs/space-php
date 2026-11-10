<?php
declare(strict_types=1);

namespace Chameloon\Space;

/** @internal */
final class Json
{
    public static function encode(mixed $value, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;
        return json_encode($value, $flags | ($pretty ? JSON_PRETTY_PRINT : 0));
    }

    /** Decodes to arrays; null when the text isn't JSON. */
    public static function decode(string $text): mixed
    {
        try {
            return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }
}
