<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Data\Phpstan;

use Astro2006\LaravelAudit\Support\Read;
use Illuminate\Contracts\Support\Arrayable;

/**
 * One error from `phpstan analyse --error-format=json`.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class PhpstanMessage implements Arrayable
{
    public function __construct(
        public string $file,
        public ?int $line,
        public string $message,
        public string $identifier,
    ) {}

    /**
     * @param  array<mixed>  $json  decoded PHPStan output
     * @return list<self>
     */
    public static function listFromJson(array $json, string $basePath): array
    {
        $base = rtrim(str_replace('\\', '/', $basePath), '/').'/';
        $messages = [];

        foreach (Read::array($json, 'files') as $file => $data) {
            $path = str_replace('\\', '/', (string) $file);
            $relative = str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;

            foreach (Read::arrays(is_array($data) ? $data : [], 'messages') as $message) {
                $messages[] = new self(
                    $relative,
                    Read::nullableInt($message, 'line'),
                    Read::string($message, 'message'),
                    Read::string($message, 'identifier', 'unknown'),
                );
            }
        }

        return $messages;
    }

    /** @return array{file: string, line: int|null, message: string} */
    public function toArray(): array
    {
        return ['file' => $this->file, 'line' => $this->line, 'message' => $this->message];
    }
}
