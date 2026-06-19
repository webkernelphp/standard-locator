<?php declare(strict_types=1);
namespace Webkernel\StdPaths;

use Composer\Config;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\IO\NullIO;
use Composer\Factory;

final class WebkernelComposer
{
    // -------------------------------------------------------------------------
    // Level 1 static cache — zero I/O on hot calls
    // -------------------------------------------------------------------------
    private static ?string $resolvedRoot    = null;
    private static ?string $resolvedVendor  = null;
    private static ?string $resolvedBin     = null;
    private static ?array  $installedData   = null;
    private static bool    $cacheBooted     = false;

    // -------------------------------------------------------------------------
    // composer.json in-memory cache
    // -------------------------------------------------------------------------
    /** @var array<string,mixed>|null */
    private static ?array $data  = null;
    private static ?int   $mtime = null;

    private static ?\Composer\Composer $instance = null;

    private const IMMUTABLE = ['name', 'type', 'license', '_readme'];

    private function __construct(private readonly string $root) {}

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------
    public static function boot(string $cacheDir): void
    {
        WebkernelCache::useDirectory($cacheDir);
        static::$cacheBooted = true;
    }

    // -------------------------------------------------------------------------
    // Load
    // -------------------------------------------------------------------------
    public static function load(?string $root = null): static
    {
        $root ??= static::root();
        static::hydrate($root);
        return new static($root);
    }

    // -------------------------------------------------------------------------
    // Root — resolved once from included files, stored in static property
    // -------------------------------------------------------------------------
    public static function root(): string
    {
        if (static::$resolvedRoot !== null) {
            return static::$resolvedRoot;
        }

        foreach (get_included_files() as $file) {
            $pos = strrpos($file, '/composer/');
            if ($pos === false) {
                continue;
            }
            $candidate = dirname(substr($file, 0, $pos));
            if (is_file($candidate . '/composer.json')) {
                return static::$resolvedRoot = $candidate;
            }
        }

        throw new \RuntimeException('Cannot resolve root: no composer/ file in get_included_files().');
    }

    // -------------------------------------------------------------------------
    // Vendor dir — static-cached first, file cache second, Config last
    // -------------------------------------------------------------------------
    public static function vendorDir(): string
    {
        if (static::$resolvedVendor !== null) {
            return static::$resolvedVendor;
        }

        static::ensureBooted();

        return static::$resolvedVendor = WebkernelCache::get(
            '__composer.vendor-dir',
            static function (): string {
                $root = static::root();
                $cfg  = new Config(true, $root);
                $cfg->merge(['config' => static::readRaw($root)['config'] ?? []]);
                return $cfg->get('vendor-dir');
            },
            static::root() . '/composer.json'
        );
    }

    // -------------------------------------------------------------------------
    // Bin dir — same three-level strategy
    // -------------------------------------------------------------------------
    public static function binDir(): string
    {
        if (static::$resolvedBin !== null) {
            return static::$resolvedBin;
        }

        static::ensureBooted();

        return static::$resolvedBin = WebkernelCache::get(
            '__composer.bin-dir',
            static function (): string {
                $root = static::root();
                $cfg  = new Config(true, $root);
                $cfg->merge(['config' => static::readRaw($root)['config'] ?? []]);
                return $cfg->get('bin-dir');
            },
            static::root() . '/composer.json'
        );
    }

    // -------------------------------------------------------------------------
    // Autoload
    // -------------------------------------------------------------------------
    public static function autoloadFile(): string
    {
        return static::vendorDir() . '/autoload.php';
    }

    public static function requireAutoload(): void
    {
        if (!class_exists(\Composer\Autoload\ClassLoader::class, false)) {
            require static::autoloadFile();
        }
    }

    // -------------------------------------------------------------------------
    // Composer instance
    // -------------------------------------------------------------------------
    public static function instance(): \Composer\Composer
    {
        return static::$instance ??= Factory::create(new NullIO());
    }

    // -------------------------------------------------------------------------
    // Installed packages — static-cached after first read
    // -------------------------------------------------------------------------
    /**
     * @param  string|null $type
     * @param  string|null $vendor
     * @return array<int,array{name:string,version:string,type:string,description:string}>
     */
    public static function installedPackages(?string $type = null, ?string $vendor = null): array
    {
        if (static::$installedData === null) {
            $installedJson = static::vendorDir() . '/composer/installed.json';
            if (!is_file($installedJson)) {
                return [];
            }
            $raw = json_decode((string) file_get_contents($installedJson), true, 512, JSON_THROW_ON_ERROR);
            static::$installedData = $raw['packages'] ?? $raw;
        }

        if ($type === null && $vendor === null) {
            return static::$installedData;
        }

        $result = [];
        foreach (static::$installedData as $pkg) {
            $name = $pkg['name'] ?? '';
            if ($vendor !== null && !str_starts_with($name, $vendor . '/')) {
                continue;
            }
            if ($type !== null && ($pkg['type'] ?? '') !== $type) {
                continue;
            }
            $result[] = [
                'name'        => $name,
                'version'     => $pkg['version'] ?? '',
                'type'        => $pkg['type'] ?? '',
                'description' => $pkg['description'] ?? '',
            ];
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // Auth
    // -------------------------------------------------------------------------
    /** @return array<string,mixed> */
    public static function authRead(): array
    {
        $path = static::root() . '/auth.json';
        return is_file($path) ? (new JsonFile($path))->read() : [];
    }

    public static function authSet(string $method, string $host, mixed $value): void
    {
        $path = static::root() . '/auth.json';
        $auth = is_file($path) ? (new JsonFile($path))->read() : [];
        $auth[$method][$host] = $value;
        (new JsonFile($path))->write($auth);
    }

    public static function authForget(string $method, string $host): void
    {
        $path = static::root() . '/auth.json';
        if (!is_file($path)) {
            return;
        }
        $auth = (new JsonFile($path))->read();
        unset($auth[$method][$host]);
        if (empty($auth[$method])) {
            unset($auth[$method]);
        }
        (new JsonFile($path))->write($auth);
    }

    public static function authBearer(string $host, string $token): void
    {
        static::authSet('bearer', $host, $token);
    }

    public static function authBasic(string $host, string $username, string $password): void
    {
        static::authSet('http-basic', $host, ['username' => $username, 'password' => $password]);
    }

    // -------------------------------------------------------------------------
    // Read / write composer.json
    // -------------------------------------------------------------------------
    public function get(string $key, mixed $default = null): mixed
    {
        return static::dotGet(static::$data ?? [], $key, $default);
    }

    /** @return array<string,mixed> */
    public function all(): array { return static::$data ?? []; }

    public function set(string $key, mixed $value): static
    {
        $top = explode('.', $key)[0];
        if (in_array($top, self::IMMUTABLE, true)) {
            throw new \InvalidArgumentException("Key [{$top}] is immutable.");
        }

        $path = static::root() . '/composer.json';
        $raw  = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read [{$path}].");
        }

        $m        = new JsonManipulator($raw);
        $segments = explode('.', $key);
        count($segments) > 1
            ? $m->addSubNode($segments[0], implode('.', array_slice($segments, 1)), $value)
            : $m->addMainKey($key, $value);

        file_put_contents($path, $m->getContents(), LOCK_EX);
        static::bust();
        return $this;
    }

    public function addRepository(array $repository): static
    {
        $repos   = (array) $this->get('repositories', []);
        $repos[] = $repository;
        return $this->set('repositories', array_values($repos));
    }

    public function removeRepository(string $url): static
    {
        return $this->set('repositories', array_values(array_filter(
            (array) $this->get('repositories', []),
            static fn (array $r): bool => ($r['url'] ?? '') !== $url,
        )));
    }

    public function requirePackage(string $package, string $constraint = '*'): static
    {
        $require           = (array) $this->get('require', []);
        $require[$package] = $constraint;
        return $this->set('require', $require);
    }

    public function removePackage(string $package): static
    {
        $require = (array) $this->get('require', []);
        unset($require[$package]);
        return $this->set('require', $require);
    }

    // -------------------------------------------------------------------------
    // Cache flush
    // -------------------------------------------------------------------------
    public static function flush(): void
    {
        static::bust();
        static::$resolvedRoot   = null;
        static::$resolvedVendor = null;
        static::$resolvedBin    = null;
        static::$installedData  = null;
        static::$cacheBooted    = false;
        static::$instance       = null;
        WebkernelCache::flush();
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------
    private static function ensureBooted(): void
    {
        if (static::$cacheBooted) {
            return;
        }

        $root     = static::root();
        $cacheDir = $root . DIRECTORY_SEPARATOR . 'storage/frame' . DIRECTORY_SEPARATOR . '_tmp';

        if (!is_dir($cacheDir)) {
            $prev = umask(0);
            @mkdir($cacheDir, 0755, true);
            umask($prev);
        }

        static::boot($cacheDir);
    }

    /** @return array<string,mixed> */
    private static function readRaw(string $root): array
    {
        $path  = $root . '/composer.json';
        $mtime = filemtime($path);
        if ($mtime === false) {
            throw new \RuntimeException("Cannot stat [{$path}].");
        }
        if (static::$data !== null && static::$mtime === $mtime) {
            return static::$data;
        }
        static::$data  = (new JsonFile($path))->read();
        static::$mtime = $mtime;
        return static::$data;
    }

    private static function hydrate(string $root): void { static::readRaw($root); }

    private static function bust(): void
    {
        static::$data           = null;
        static::$mtime          = null;
        static::$resolvedVendor = null;
        static::$resolvedBin    = null;
        static::$installedData  = null;
        WebkernelCache::forget('__composer.vendor-dir');
        WebkernelCache::forget('__composer.bin-dir');
    }

    /** @param array<string,mixed> $data */
    private static function dotGet(array $data, string $key, mixed $default): mixed
    {
        foreach (explode('.', $key) as $segment) {
            if (!is_array($data) || !array_key_exists($segment, $data)) {
                return $default;
            }
            $data = $data[$segment];
        }
        return $data;
    }
}
