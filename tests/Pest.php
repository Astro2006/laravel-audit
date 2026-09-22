<?php

use Astro2006\LaravelAudit\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * A fresh, empty directory below build/tests for one test.
 */
function tempDir(string $name = 'tmp'): string
{
    $dir = dirname(__DIR__).'/build/tests/'.$name.'-'.bin2hex(random_bytes(4));

    mkdir($dir, 0777, true);

    return $dir;
}

/**
 * A Claude Code `--output-format json` result.
 */
function claudeJson(array $overrides = []): string
{
    return json_encode(array_merge([
        'type' => 'result',
        'subtype' => 'success',
        'is_error' => false,
        'result' => "## Summary\nOK",
        'session_id' => 'f1b2c3d4-0000-4000-8000-000000000000',
        'total_cost_usd' => 0.42,
        'num_turns' => 12,
        'duration_ms' => 61000,
        'stop_reason' => 'end_turn',
        'modelUsage' => [
            'claude-haiku-4-5-20251001' => ['inputTokens' => 900, 'outputTokens' => 12, 'cacheReadInputTokens' => 0, 'cacheCreationInputTokens' => 0, 'costUSD' => 0.001, 'canonicalModel' => 'claude-haiku-4-5'],
            'claude-opus-5[1m]' => ['inputTokens' => 20, 'outputTokens' => 4000, 'cacheReadInputTokens' => 150000, 'cacheCreationInputTokens' => 30000, 'costUSD' => 0.419, 'canonicalModel' => 'claude-opus-5'],
        ],
    ], $overrides));
}

/**
 * config('audit.audits') as the package ships it; with $enabled, only
 * those audits are enabled.
 *
 * @param  list<string>|null  $enabled
 */
function auditsConfig(?array $enabled = null): array
{
    $audits = (require dirname(__DIR__).'/config/audit.php')['audits'];

    if ($enabled !== null) {
        foreach ($audits as $audit => $config) {
            $audits[$audit]['enabled'] = in_array($audit, $enabled, true);
        }
    }

    return $audits;
}
