<?php declare(strict_types=1);
// @package webkernel/standard-paths/load.standard-paths.functions.php
use Webkernel\StdPaths\WebkernelComposer;
use Webkernel\StdPaths\WebkernelRouter;

/**
 * Real-ish paths without realpath()
 * Normalize a file path by resolving "." and ".." segments,
 * collapsing multiple slashes, and ensuring no trailing slash.
 *
 * @param string $filename Input path to resolve
 * @return string Normalized path without trailing slash
 */
 function resolveFilename(string $filename): string
 {
     $isAbsolute = str_starts_with($filename, '/');
     $filename   = preg_replace('#/+#', '/', $filename);
     $parts      = explode('/', $filename);
     $out        = [];

     foreach ($parts as $part) {
         if ($part === '' || $part === '.') { continue; }
         if ($part === '..') { array_pop($out); continue; }
         $out[] = $part;
     }

     $resolved = implode('/', $out);
     if ($isAbsolute) {
         $resolved = '/' . $resolved;
     }
     return rtrim($resolved, '/');
 }

// =============================================================================
// Composer path helpers — thin wrappers over WebkernelComposer (loaded first)
// =============================================================================

if (!function_exists('project_root')) {
    function project_root(?string $path = null): string
    {
        $root = WebkernelComposer::root();
        return $path ? $root . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : $root;
    }
}

if (!function_exists('webapp_path')) {
    function webapp_path(?string $path = null): string
    {
        $root = WebkernelComposer::root();
        return $path ? $root . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : $root;
    }
}

if (!function_exists('vendor_dir')) {
    function vendor_dir(?string $path = null): string
    {
        $dir = WebkernelComposer::vendorDir();
        return $path ? $dir . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : $dir;
    }
}

if (!function_exists('bin_dir')) {
    function bin_dir(?string $path = null): string
    {
        $dir = WebkernelComposer::binDir();
        return $path ? $dir . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : $dir;
    }
}

if (!function_exists('autoload_file')) {
    function autoload_file(): string
    {
        return WebkernelComposer::autoloadFile();
    }
}

// =============================================================================
// webkernel_std_make_subpath
// Primitive filesystem helper. No dependency on any other Webkernel function.
// =============================================================================

if (!function_exists('webkernel_std_make_subpath')) {
    /**
     * Resolves a path under $basePath, creating it on demand when $makeOnMiss
     * is true. Accepts an optional $onError callback for caller-controlled error
     * handling; when omitted, throws RuntimeException.
     *
     * @param string        $basePath   Absolute base directory.
     * @param string        $subpath    Relative path to append (file or dir).
     * @param bool          $makeOnMiss Create target automatically if missing.
     * @param callable|null $onError    fn(string $message): never  — called
     *                                  instead of throwing RuntimeException.
     * @return string Absolute resolved path.
     * @throws RuntimeException When $onError is null and an error occurs.
     */
    function webkernel_std_make_subpath(
        string   $basePath,
        string   $subpath,
        bool     $makeOnMiss = true,
        ?callable $onError   = null
    ): string {
        $raise = static function (string $message) use ($onError): never {
            if ($onError !== null) {
                ($onError)($message);
                // Guarantee never-return contract even if caller forgot it.
                throw new \LogicException('$onError must not return: ' . $message);
            }
            throw new \RuntimeException($message);
        };

        $base   = rtrim($basePath, DIRECTORY_SEPARATOR);
        $sub    = ltrim($subpath,  DIRECTORY_SEPARATOR);
        $target = $base . DIRECTORY_SEPARATOR . $sub;

        if (file_exists($target)) {
            $resolved = realpath($target);
            if ($resolved === false) {
                $raise(sprintf('Failed to resolve absolute path for: %s', $target));
            }
            return $resolved;
        }

        if (!$makeOnMiss) {
            $raise(sprintf('Target path does not exist: %s', $target));
        }

        $isFile = pathinfo($target, PATHINFO_EXTENSION) !== '';

        if ($isFile) {
            $parent = dirname($target);
            if (!is_dir($parent)) {
                $prev    = umask(0);
                $created = @mkdir($parent, 0755, true);
                umask($prev);
                if (!$created && !is_dir($parent)) {
                    $raise(sprintf('Failed to create parent directory: %s', $parent));
                }
            }
            if (@touch($target) === false) {
                $raise(sprintf('Failed to create file: %s', $target));
            }
        } else {
            $prev    = umask(0);
            $created = @mkdir($target, 0755, true);
            umask($prev);
            if (!$created && !is_dir($target)) {
                $raise(sprintf('Failed to create directory: %s', $target));
            }
        }

        $resolved = realpath($target);
        if ($resolved === false) {
            $raise(sprintf('Failed to resolve path after creation: %s', $target));
        }

        return $resolved;
    }
}

// =============================================================================
// webkernel_package
// Resolves paths inside the /packages directory.
// Delegates creation to webkernel_std_make_subpath.
// Uses project_root() — never walks composer.json itself.
// =============================================================================

if (!function_exists('webkernel_package')) {
    /**
     * Resolves an absolute (or project-relative) path inside the packages
     * directory, optionally scoped to a named package and sub-path.
     *
     * @param string|null   $name       Package directory name (e.g. 'stdloc').
     * @param string|null   $subpath    Path relative to the package root.
     * @param bool          $relative   Return path relative to project root.
     * @param bool          $makeOnMiss Create target automatically if missing.
     * @param callable|null $onError    Custom error handler — see webkernel_std_make_subpath.
     * @return string Resolved absolute (or relative) path.
     */
    function webkernel_package(
        ?string  $name       = null,
        ?string  $subpath    = null,
        bool     $relative   = false,
        bool     $makeOnMiss = true,
        ?callable $onError   = null
    ): string {
        $root        = project_root();                           // single source of truth
        $packagesDir = $root . DIRECTORY_SEPARATOR . 'packages';

        // Build the target path from non-null segments.
        $segments = array_filter(
            [$packagesDir, $name, $subpath],
            static fn (?string $v): bool => $v !== null && $v !== ''
        );
        $abs = implode(DIRECTORY_SEPARATOR, $segments);

        // Delegate filesystem work entirely
        // When $abs equals $packagesDir (bare call), the directory must already
        // exist; creation is skipped when $makeOnMiss is false.
        $resolved = webkernel_std_make_subpath(
            dirname($abs),
            basename($abs),
            $makeOnMiss,
            $onError
        );

        if (!$relative) {
            return $resolved;
        }

        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($resolved, $prefix)
            ? substr($resolved, strlen($prefix))
            : ltrim(str_replace($root, '', $resolved), DIRECTORY_SEPARATOR);
    }
}

// =============================================================================
// webkernel_cache_path
// Thin scoped wrapper: always targets storage/framework/cache.
// =============================================================================

if (!function_exists('webkernel_cache_path')) {
    /**
     * Resolves or initializes a subpath within the framework cache directory.
     *
     * @param string        $subpath    Relative path inside the cache directory.
     * @param bool          $makeOnMiss Automatically create target if missing.
     * @param callable|null $onError    Custom error handler.
     * @return string Absolute resolved path.
     */
    function webkernel_cache_path(
        string    $subpath,
        bool      $makeOnMiss = true,
        ?callable $onError    = null
    ): string {
        $cacheBase = project_root() . DIRECTORY_SEPARATOR . 'storage/framework/cache';
        return webkernel_std_make_subpath($cacheBase, $subpath, $makeOnMiss, $onError);
    }
}

// =============================================================================
// COMPOSER_VENDOR_DIR Environment Variable Setup
// Sets the value of the environment variable. Otherwise, Laravel's
// PackageDiscoverCommand will create an empty array in
// bootstrap/cache/packages.php.
// =============================================================================

putenv('COMPOSER_VENDOR_DIR=' . vendor_dir());

// =============================================================================
// Webkernel Router Bootstrap
// Intercepts matching requests before the framework router runs.
// Routes must be pre-registered via WebkernelRouter::register().
// =============================================================================

if (php_sapi_name() !== 'cli' && WebkernelRouter::isWebkernelRequest()) {
    WebkernelRouter::dispatch();
}
