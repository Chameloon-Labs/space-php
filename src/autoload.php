<?php
// For use without Composer: require this file and the classes load.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Chameloon\\Space\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
