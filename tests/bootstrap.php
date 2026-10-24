<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';

/** Minimal dependency-free test harness (PHPUnit is not available on the XAMPP PHP build). */
final class T
{
    /** @var array<int,array{name:string, fn:callable}> */
    public static array $tests = [];
    public static int $assertions = 0;

    public static function test(string $name, callable $fn): void
    {
        self::$tests[] = ['name' => $name, 'fn' => $fn];
    }

    public static function same($expected, $actual, string $message = ''): void
    {
        self::$assertions++;
        if ($expected !== $actual) {
            throw new RuntimeException(($message !== '' ? $message . ': ' : '') . 'expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
        }
    }

    public static function true($value, string $message = ''): void
    {
        self::same(true, (bool) $value, $message);
    }

    public static function false($value, string $message = ''): void
    {
        self::same(false, (bool) $value, $message);
    }

    /** @param class-string<Throwable> $class */
    public static function throws(string $class, callable $fn, ?string $code = null): Throwable
    {
        self::$assertions++;
        try {
            $fn();
        } catch (Throwable $error) {
            if (!($error instanceof $class)) {
                throw new RuntimeException('expected ' . $class . ' got ' . get_class($error) . ': ' . $error->getMessage());
            }
            if ($code !== null && method_exists($error, 'errorCode') && $error->errorCode() !== $code) {
                throw new RuntimeException('expected error code ' . $code . ' got ' . $error->errorCode());
            }
            return $error;
        }
        throw new RuntimeException('expected ' . $class . ' but nothing was thrown');
    }

    public static function run(): int
    {
        $failed = 0;
        foreach (self::$tests as $test) {
            try {
                ($test['fn'])();
                echo "  ok   {$test['name']}\n";
            } catch (Throwable $error) {
                $failed++;
                echo "  FAIL {$test['name']}\n       " . $error->getMessage() . "\n       at " . basename($error->getFile()) . ':' . $error->getLine() . "\n";
            }
        }
        echo "\n" . count(self::$tests) . ' tests, ' . self::$assertions . ' assertions, ' . $failed . " failed\n";
        return $failed === 0 ? 0 : 1;
    }
}

/** Generates an RSA key pair, tolerating XAMPP's missing default openssl.cnf. */
function test_rsa_key(): array
{
    $candidates = [null, 'C:\\xampp\\php\\extras\\ssl\\openssl.cnf', 'C:\\xampp\\apache\\conf\\openssl.cnf'];
    foreach ($candidates as $config) {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        if ($config !== null) {
            if (!is_file($config)) {
                continue;
            }
            $options['config'] = $config;
        }
        $key = openssl_pkey_new($options);
        if ($key === false) {
            continue;
        }
        $privatePem = '';
        openssl_pkey_export($key, $privatePem, null, $config !== null ? ['config' => $config] : []);
        $details = openssl_pkey_get_details($key);
        return ['private' => $privatePem, 'public' => $details['key'], 'resource' => $key, 'config' => $config];
    }
    throw new RuntimeException('Unable to generate an RSA key; check the PHP openssl configuration.');
}
