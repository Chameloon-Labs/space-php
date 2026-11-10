<?php
declare(strict_types=1);

namespace Chameloon\Space;

/**
 * PHP runs one process per request, so every piece of state lives in a file and is guarded with flock.
 * @internal
 */
final class Disk
{
    /** The file's text under a shared lock, or null if it doesn't exist. */
    public static function read(string $path): ?string
    {
        $h = @fopen($path, 'r');
        if (!$h) {
            return null;
        }
        flock($h, LOCK_SH);
        $text = stream_get_contents($h);
        flock($h, LOCK_UN);
        fclose($h);
        return $text === false ? null : $text;
    }

    /**
     * Runs $fn(text, write) while holding an exclusive lock on the file; nothing is written unless $fn calls write().
     * @param callable(string, callable(string):void):mixed $fn
     */
    public static function locked(string $path, callable $fn): mixed
    {
        $h = fopen($path, 'c+');
        if (!$h) {
            throw new \RuntimeException("Cannot open $path");
        }
        flock($h, LOCK_EX);
        try {
            $write = static function (string $text) use ($h): void {
                ftruncate($h, 0);
                rewind($h);
                fwrite($h, $text);
                fflush($h);
            };
            return $fn((string) stream_get_contents($h), $write);
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }
}
