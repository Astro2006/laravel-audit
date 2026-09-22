<?php

use Astro2006\LaravelAudit\Agents\AgentRequest;
use Astro2006\LaravelAudit\Agents\Drivers\CodexDriver;
use Astro2006\LaravelAudit\Enums\AgentExit;
use Astro2006\LaravelAudit\Enums\ReadProtection;
use Astro2006\LaravelAudit\Support\McpServer;

it('runs codex exec in the read-only sandbox with boost as the only mcp server', function () {
    $driver = new CodexDriver(model: 'gpt-oss:20b', boost: McpServer::default(), boostTools: ['application-info', 'tinker'], overrides: ['model_provider="ollama"']);

    expect($driver->command(new AgentRequest('backend.injection', 'p', '/app')))->toBe([
        'codex', 'exec', '--json', '--skip-git-repo-check', '--model', 'gpt-oss:20b',
        '-c', 'mcp_servers={}',
        '-c', 'mcp_servers.laravel-boost.command="php"',
        '-c', 'mcp_servers.laravel-boost.args=["artisan","boost:mcp"]',
        '-c', 'mcp_servers.laravel-boost.enabled_tools=["application-info"]',
        '-c', 'model_provider="ollama"',
        '--sandbox', 'read-only', '-',
    ]);
});

it('cannot protect secret files unless the config accepts that', function () {
    expect((new CodexDriver)->readProtection())->toBe(ReadProtection::Missing)
        ->and((new CodexDriver(allowUnrestrictedRead: true))->readProtection())->toBe(ReadProtection::Waived);
});

it('parses the json lines and resumes the thread', function () {
    $driver = new CodexDriver;
    $result = $driver->parse(file_get_contents(fixture('agents/codex-exec.jsonl')), 0);

    expect($result->succeeded())->toBeTrue()
        ->and($result->answer)->toBe("## Findings\n\nNo findings.")
        ->and($result->sessionId)->toBe('0199a213-81c0-7800-8aa1-bbab2a035a53')
        ->and($result->telemetry->inputTokens)->toBe(315)
        ->and($result->telemetry->cacheReadTokens)->toBe(24448)
        ->and($result->telemetry->outputTokens)->toBe(162)
        ->and($result->telemetry->costUsd)->toBeNull()
        ->and(array_slice($driver->resumeCommand($result, new AgentRequest('k', 'p', '/app')), -3))->toBe(['resume', '0199a213-81c0-7800-8aa1-bbab2a035a53', '-']);
});

it('reports failed turns', function () {
    $stream = '{"type":"thread.started","thread_id":"t1"}'."\n".'{"type":"turn.failed","error":{"message":"model not found"}}';

    expect((new CodexDriver)->parse($stream, 1))->exit->toBe(AgentExit::Error)->error->toBe('model not found')
        ->and((new CodexDriver)->parse('', 1)->exit)->toBe(AgentExit::Error);
});
