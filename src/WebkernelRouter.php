<?php declare(strict_types=1);

namespace Webkernel\StdPaths;

use Closure;

/**
 * Minimal route registry and dispatcher for internal Webkernel endpoints.
 *
 * All routes live under a fixed URL prefix (/__webkernel-app/). Routes
 * are registered in-memory by package bootstrap code, compiled once into
 * an indexed regex map, and matched in a single pass per request.
 *
 * No framework dependency. The dispatcher must run after all routes are
 * registered; it is invoked from public/index.php immediately after the
 * Composer autoloader (so all package "files" autoloaders have executed).
 */
final class WebkernelRouter
{
    private const string PREFIX = '/__webkernel-app/';

    /** @var list<array{pattern: string, handler: WebkernelRouteHandler}> */
    private static array $routes = [];

    /**
     * Compiled route table. Rebuilt lazily and invalidated on each new registration.
     *
     * @var list<array{regex: string, names: list<string>, handler: WebkernelRouteHandler}>|null
     */
    private static ?array $compiled = null;

    /**
     * Register a route handler for a given pattern.
     *
     * @param string $pattern Pattern relative to PREFIX, e.g. "branding/{brand}/{key}"
     */
    public static function register(string $pattern, WebkernelRouteHandler $handler): void
    {
        self::$routes[] = ['pattern' => $pattern, 'handler' => $handler];
        self::$compiled = null;
    }

    /**
     * Register a route using an inline closure.
     *
     * @param string $pattern Pattern relative to PREFIX, e.g. "branding/{brand}/{key}"
     * @param \Closure(array<string,string>): never $closure
     */
    public static function registerClosure(string $pattern, \Closure $closure): void
    {
        self::register($pattern, new WebkernelClosureHandler($closure));
    }

    /**
     * Check whether the current HTTP request targets a /__webkernel-app/* path.
     */
    public static function isWebkernelRequest(): bool
    {
        return str_starts_with(self::currentPath(), self::PREFIX);
    }

    /**
     * Attempt to dispatch the current request against registered routes.
     *
     * Returns false only if no route matched, allowing the caller to let
     * the framework continue. On a match, the handler exits the process.
     */
    public static function dispatch(): bool
    {
        $uri = self::currentPath();

        if (!str_starts_with($uri, self::PREFIX) && $uri !== rtrim(self::PREFIX, '/')) {
            return false;
        }

        $relative = substr($uri, strlen(self::PREFIX));

        foreach (self::compiled() as $route) {
            if (preg_match($route['regex'], $relative, $matches) === 1) {
                $params = [];
                foreach ($route['names'] as $name) {
                    $params[$name] = $matches[$name] ?? '';
                }

                $route['handler']->handle($params);
            }
        }

        return false;
    }

    /**
     * Build a canonical /__webkernel-app/ URL for a given route pattern.
     *
     * @param array<string, scalar> $params Values to substitute into {placeholders}
     */
    public static function url(string $pattern, array $params = []): string
    {
        $path = $pattern;

        foreach ($params as $key => $value) {
            $path = str_replace('{' . $key . '}', rawurlencode((string) $value), $path);
        }

        return self::PREFIX . ltrim($path, '/');
    }

    /**
     * Return the current request path, normalized to start with a single slash.
     */
    private static function currentPath(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        if (!is_string($uri) || $uri === '') {
            $uri = '/';
        }

        return '/' . ltrim($uri, '/');
    }

    /**
     * Compile registered routes into a regex map. Result is cached until
     * a new route is registered, at which point $compiled is nulled.
     *
     * @return list<array{regex: string, names: list<string>, handler: WebkernelRouteHandler}>
     */
    private static function compiled(): array
    {
        if (self::$compiled !== null) {
            return self::$compiled;
        }

        $compiled = [];

        foreach (self::$routes as $route) {
            $names = [];

            $regex = preg_replace_callback(
                '/\{([a-zA-Z_]\w*)\}/',
                static function (array $m) use (&$names): string {
                    $names[] = $m[1];
                    return '(?P<' . $m[1] . '>[^/]+)';
                },
                $route['pattern'],
            );

            $compiled[] = [
                'regex'   => '#^' . $regex . '$#',
                'names'   => $names,
                'handler' => $route['handler'],
            ];
        }

        return self::$compiled = $compiled;
    }
}

/**
 * Adapts a Closure to WebkernelRouteHandler.
 */
final readonly class WebkernelClosureHandler implements WebkernelRouteHandler
{
    /**
     * @param \Closure(array<string,string>): never $closure
     */
    public function __construct(
        private \Closure $closure,
    ) {
    }

    public function handle(array $params): never
    {
        ($this->closure)($params);
    }
}

/**
 * Contract for a Webkernel route handler.
 *
 * Implementations must terminate the request by setting headers,
 * echoing output, and calling exit(). Dispatch never returns control
 * to the caller after a route matches.
 */
interface WebkernelRouteHandler
{
    /**
     * @param array<string,string> $params Named parameters extracted from the route pattern.
     */
    public function handle(array $params): never;
}
