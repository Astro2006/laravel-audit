<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Data\Routes;

use Astro2006\LaravelAudit\Support\Read;
use Illuminate\Contracts\Support\Arrayable;

/**
 * One entry of `php artisan route:list --json`.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class RouteDefinition implements Arrayable
{
    /** @param list<string> $middleware */
    public function __construct(
        public string $method,
        public string $uri,
        public ?string $name,
        public string $action,
        public array $middleware,
    ) {}

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $middleware = data_get($data, 'middleware', []);

        if (is_string($middleware)) {
            $middleware = preg_split('/\n+/', $middleware) ?: [];
        }

        return new self(
            method: Read::string($data, 'method'),
            uri: '/'.ltrim(Read::string($data, 'uri'), '/'),
            name: Read::nullableString($data, 'name'),
            action: Read::string($data, 'action'),
            middleware: array_values(array_filter(array_map(
                fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '',
                is_array($middleware) ? $middleware : [],
            ))),
        );
    }

    /**
     * @param  array<mixed>  $json  decoded route:list output
     * @return list<self>
     */
    public static function listFromJson(array $json): array
    {
        return array_map(self::fromArray(...), array_values(array_filter($json, 'is_array')));
    }

    /** @return list<string> methods without HEAD */
    public function methods(): array
    {
        return array_values(array_filter(
            explode('|', strtoupper($this->method)),
            fn (string $method): bool => $method !== '' && $method !== 'HEAD',
        ));
    }

    /** The controller method of "Class@method", or "" for closures and invokables. */
    public function actionMethod(): string
    {
        return str_contains($this->action, '@') ? substr($this->action, (int) strrpos($this->action, '@') + 1) : '';
    }

    public function isControllerAction(): bool
    {
        return $this->action !== '' && $this->action !== 'Closure' && str_contains($this->action, '\\');
    }

    /** @return array{method: string, uri: string, name: string|null, action: string, middleware: list<string>} */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'uri' => $this->uri,
            'name' => $this->name,
            'action' => $this->action,
            'middleware' => $this->middleware,
        ];
    }
}
