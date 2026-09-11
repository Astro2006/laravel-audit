<?php

use Astro2006\LaravelAudit\Agents\AgentRequest;
use Astro2006\LaravelAudit\Agents\ClaudeCodeDriver;
use Astro2006\LaravelAudit\Agents\DeniedFiles;
use Astro2006\LaravelAudit\Agents\McpConfigWriter;
use Astro2006\LaravelAudit\Enums\AgentExit;
use Astro2006\LaravelAudit\Support\ProjectInspector;


it('builds a read-only headless command', function () {
    $driver = new ClaudeCodeDriver(
        binary: 'claude',
        model: 'opus',
        maxBudgetUsd: 3.0,
        boostTools: ['application-info', 'tinker', 'database-schema'],
        deniedFiles: ['.env', '.env.testing'],
    );

    $command = $driver->command(new AgentRequest('backend', 'prompt', '/app', '/app/storage/audit/run/logs/mcp.json', ['/elsewhere']));

    expect($command)->toBe([
        'claude', '-p', '--output-format', 'json', '--restricted', '--permission-prompts', 'none', '--strict-mcp-config',
        '--mcp-config', '/app/storage/audit/run/logs/mcp.json',
        '--tools', 'Read,Grep,Glob',
        '--allowedTools=mcp__laravel-boost__application-info,mcp__laravel-boost__database-schema',
        '--disallowedTools=Edit,Write,NotebookEdit,Bash,WebFetch,WebSearch,mcp__laravel-boost__tinker,mcp__laravel-boost__database-query,mcp__laravel-boost__read-log-entries,mcp__laravel-boost__last-error,mcp__laravel-boost__browser-logs,mcp__laravel-boost__list-available-env-vars,Read(./.env),Read(./.env.testing)',
        '--model', 'opus',
        '--max-budget-usd', '3',
        '--add-dir', '/elsewhere',
    ]);
});

it('allows no mcp tools without an mcp config', function () {
    $command = (new ClaudeCodeDriver(boostTools: ['application-info']))->command(new AgentRequest('tests', 'p', '/app'));

    expect($command)->not->toContain('--mcp-config')
        ->and(implode(' ', $command))->not->toContain('--allowedTools');
});

it('resumes the session for the repair pass', function () {
    $driver = new ClaudeCodeDriver;
    $previous = $driver->parse(claudeJson(), 0);

    expect(array_slice($driver->resumeCommand($previous, new AgentRequest('tests', 'p', '/app')), -2))
        ->toBe(['--resume', 'f1b2c3d4-0000-4000-8000-000000000000']);
});

it('parses the json result with telemetry and the main model', function () {
    $result = (new ClaudeCodeDriver)->parse(claudeJson(), 0);

    expect($result->exit)->toBe(AgentExit::Success)
        ->and($result->succeeded())->toBeTrue()
        ->and($result->answer)->toBe("## Summary\nOK")
        ->and($result->model)->toBe('claude-opus-5')
        ->and($result->telemetry->inputTokens)->toBe(30920)
        ->and($result->telemetry->outputTokens)->toBe(4012)
        ->and($result->telemetry->cacheReadTokens)->toBe(150000)
        ->and($result->telemetry->costUsd)->toBe(0.42)
        ->and($result->telemetry->turns)->toBe(12);
});

it('maps budget, errors, truncation and garbage', function () {
    $driver = new ClaudeCodeDriver;

    expect($driver->parse(claudeJson(['subtype' => 'error_max_budget_usd', 'is_error' => true]), 1)->exit)->toBe(AgentExit::Budget)
        ->and($driver->parse(claudeJson(['subtype' => 'error_during_execution', 'is_error' => true]), 1)->exit)->toBe(AgentExit::Error)
        ->and($driver->parse(claudeJson(['stop_reason' => 'max_tokens']), 0)->succeeded())->toBeFalse()
        ->and($driver->parse('Not logged in', 1)->exit)->toBe(AgentExit::Error);
});

it('detaches the agent from a parent Claude Code session', function () {
    expect((new ClaudeCodeDriver)->environment())->toMatchArray(['CLAUDECODE' => false, 'CLAUDE_CODE_SESSION_ID' => false])
        ->not->toHaveKey('ANTHROPIC_API_KEY');
});

it('resolves denied files and keeps the allowed exceptions', function () {
    $dir = tempDir('deny');
    foreach (['.env', '.env.example', '.env.testing', 'auth.json'] as $file) {
        touch($dir.'/'.$file);
    }
    mkdir($dir.'/storage');
    touch($dir.'/storage/oauth-private.key');

    expect(DeniedFiles::resolve($dir, ['.env*', 'auth.json', 'storage/*.key'], ['.env.example']))
        ->toBe(['.env', '.env.testing', 'auth.json', 'storage/oauth-private.key']);
});

it('writes an mcp config with only the boost server', function () {
    $dir = tempDir('mcp');
    file_put_contents($dir.'/composer.lock', json_encode(['packages-dev' => [['name' => 'laravel/boost', 'version' => '1.0.0']]]));
    file_put_contents($dir.'/.mcp.json', json_encode(['mcpServers' => [
        'laravel-boost' => ['command' => 'php', 'args' => ['artisan', 'boost:mcp']],
        'github' => ['command' => 'gh-mcp'],
    ]]));

    $path = (new McpConfigWriter)->write(new ProjectInspector($dir), $dir.'/logs/mcp.json');

    expect(json_decode(file_get_contents($path), true))->toBe(['mcpServers' => ['laravel-boost' => ['command' => 'php', 'args' => ['artisan', 'boost:mcp']]]])
        ->and((new McpConfigWriter)->write(new ProjectInspector(tempDir('noboost')), $dir.'/x.json'))->toBeNull();
});
