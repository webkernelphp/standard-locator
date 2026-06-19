<?php declare(strict_types=1);
namespace Webkernel\StdPaths;

final class WebkernelCache
{
    /** @var array<string,mixed> */
    private static array $memory = [];

    /** @var array<string,int> */
    private static array $mtimes = [];

    private static ?string $cacheDir = null;

    public static function useDirectory(string $absolutePath): void
    {
        if (!is_dir($absolutePath)) {
            mkdir($absolutePath, 0755, true);
        }
        self::$cacheDir = $absolutePath;
    }

    public static function isConfigured(): bool
    {
        return self::$cacheDir !== null;
    }

    /** @param $compute */
    public static function get(string $key, callable $compute, ?string $watchPath = null): mixed
    {
        $mtime = $watchPath !== null ? (int) filemtime($watchPath) : 0;

        if (array_key_exists($key, self::$memory) && self::$mtimes[$key] === $mtime) {
            return self::$memory[$key];
        }

        $file = self::path($key);
        if (is_file($file)) {
            /** @var array{mtime:int,value:mixed} $entry */
            $entry = include $file;
            if ($entry['mtime'] === $mtime) {
                self::$memory[$key] = $entry['value'];
                self::$mtimes[$key] = $mtime;
                return $entry['value'];
            }
        }

        $value = $compute();
        self::write($file, $value, $mtime);
        self::$memory[$key] = $value;
        self::$mtimes[$key] = $mtime;
        return $value;
    }

    public static function forget(string $key): void
    {
        unset(self::$memory[$key], self::$mtimes[$key]);
        $file = self::path($key);
        if (is_file($file)) {
            unlink($file);
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($file, true);
            }
        }
    }

    public static function flush(): void
    {
        self::$memory = [];
        self::$mtimes = [];
        foreach (glob(self::dir() . '/*.php') ?: [] as $file) {
            unlink($file);
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($file, true);
            }
        }
    }

    /** @return array<string,array{file:string,mtime:int}> */
    public static function table(): array
    {
        $result = [];
        foreach (glob(self::dir() . '/*.php') ?: [] as $file) {
            $entry  = include $file;
            $result[basename($file, '.php')] = ['file' => $file, 'mtime' => $entry['mtime'] ?? 0];
        }
        return $result;
    }

    private static function dir(): string
    {
        if (self::$cacheDir === null) {
            throw new \RuntimeException(
                'WebkernelCache: no cache directory configured. Call WebkernelCache::useDirectory() first.'
            );
        }
        return self::$cacheDir;
    }

    private static function path(string $key): string
    {
        return self::dir() . '/' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $key) . '.php';
    }

    private static function write(string $file, mixed $value, int $mtime): void
    {
        file_put_contents(
            $file,
            '<?php return ' . var_export(['mtime' => $mtime, 'value' => $value], true) . ";\n",
            LOCK_EX
        );
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file, true);
        }
    }
}
