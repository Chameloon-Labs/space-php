<?php
// The router `chameloon-serve serve` hands to php -S. SPACES and TOKEN come from the environment.
declare(strict_types=1);

foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php'] as $file) {
    if (is_file($file)) {
        require $file;
        break;
    }
}
class_exists(Chameloon\Space\Server::class) || require __DIR__ . '/../src/autoload.php';

Chameloon\Space\Server::fromGlobals()->run();
