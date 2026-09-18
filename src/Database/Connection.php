<?php

declare(strict_types=1);

namespace HektaPay\Database;

use PDO;

final class Connection
{
    private static bool $environmentLoaded = false;

    public static function loadEnvironment(): void
    {
        if (self::$environmentLoaded) {
            return;
        }

        self::$environmentLoaded = true;
        $environmentFiles = array_filter([
            getenv('HEKTA_PAY_ENV_FILE') ?: null,
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env',
            dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '.env',
        ]);

        foreach ($environmentFiles as $environmentFile) {
            if (is_file($environmentFile) && is_readable($environmentFile)) {
                self::loadFile($environmentFile);
                break;
            }
        }
    }

    public static function create(): PDO
    {
        self::loadEnvironment();

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '3306';
        $name = getenv('DB_NAME') ?: 'hekta_pay';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: '';

        return new PDO(
            "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    private static function loadFile(string $file): void
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }

            if (strpos($line, 'export ') === 0) {
                $line = trim(substr($line, 7));
            }

            $separator = strpos($line, '=');
            if ($separator === false) {
                continue;
            }

            $key = trim(substr($line, 0, $separator));
            $value = trim(substr($line, $separator + 1));
            if ($key === '' || getenv($key) !== false) {
                continue;
            }

            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}
