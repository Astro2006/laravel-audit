<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Data\Tests;

use Illuminate\Contracts\Support\Arrayable;

/**
 * One route and the test files that seem to cover it.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class RouteTestLink implements Arrayable
{
    /**
     * @param  list<string>  $tests
     * @param  list<string>  $matchedBy  name, uri, controller
     */
    public function __construct(
        public string $method,
        public string $uri,
        public ?string $name,
        public string $action,
        public array $tests,
        public array $matchedBy,
    ) {}

    /** @return array{method: string, uri: string, name: string|null, action: string, tests: list<string>, matched_by: list<string>} */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'uri' => $this->uri,
            'name' => $this->name,
            'action' => $this->action,
            'tests' => $this->tests,
            'matched_by' => $this->matchedBy,
        ];
    }
}
