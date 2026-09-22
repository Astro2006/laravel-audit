<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Agents\Drivers;

use Astro2006\LaravelAudit\Agents\Telemetry;
use Astro2006\LaravelAudit\Support\Read;

/**
 * Token usage of a Claude Code result, summed over all models (the CLI may
 * use a small model for housekeeping), and the model that did most of the work.
 */
final readonly class ClaudeUsage
{
    public function __construct(
        public Telemetry $telemetry,
        public ?string $model,
    ) {}

    /** @param  array<mixed>  $data  the `--output-format json` result */
    public static function fromResult(array $data): self
    {
        $input = 0;
        $output = 0;
        $cacheRead = 0;
        $model = null;
        $topCost = -1.0;

        foreach (Read::array($data, 'modelUsage') as $name => $usage) {
            $usage = is_array($usage) ? $usage : [];
            $input += Read::int($usage, 'inputTokens') + Read::int($usage, 'cacheCreationInputTokens');
            $output += Read::int($usage, 'outputTokens');
            $cacheRead += Read::int($usage, 'cacheReadInputTokens');

            if (Read::float($usage, 'costUSD') > $topCost) {
                $topCost = Read::float($usage, 'costUSD');
                $model = Read::nullableString($usage, 'canonicalModel') ?? (string) preg_replace('/\[.*\]$/', '', (string) $name);
            }
        }

        if ($input === 0) {
            $input = Read::int($data, 'usage.input_tokens') + Read::int($data, 'usage.cache_creation_input_tokens');
            $output = Read::int($data, 'usage.output_tokens');
            $cacheRead = Read::int($data, 'usage.cache_read_input_tokens');
        }

        return new self(
            new Telemetry(
                inputTokens: $input,
                outputTokens: $output,
                cacheReadTokens: $cacheRead,
                costUsd: Read::nullableFloat($data, 'total_cost_usd'),
                turns: Read::int($data, 'num_turns'),
                durationMs: Read::int($data, 'duration_ms'),
            ),
            $model,
        );
    }
}
