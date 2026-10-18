<?php

use Astro2006\LaravelAudit\Agents\AgentRequest;
use Astro2006\LaravelAudit\Agents\Drivers\OpenCodeDriver;
use Astro2006\LaravelAudit\Enums\AgentExit;
use Astro2006\LaravelAudit\Support\McpServer;

it('builds a headless run command with its own agent and model', function () {
    $driver = new OpenCodeDriver('/app', model: 'ollama/qwen3-coder:30b');

    expect($driver->command(new AgentRequest('backend.injection', 'p', '/app', '/run/logs/opencode.json')))
        ->toBe(['opencode', 'run', '--pure', '--format', 'json', '--agent', 'laravel-audit', '--model', 'ollama/qwen3-coder:30b'])
        ->and($driver->environment(new AgentRequest('backend.injection', 'p', '/app', '/run/logs/opencode.json')))
        ->toBe(['OPENCODE_CONFIG' => '/run/logs/opencode.json']);
});

it('writes a config with read-only tools, denied secrets and only the allowed boost tools', function () {
    $dir = tempDir('opencode');
    $driver = new OpenCodeDriver(
        '/app',
        boost: McpServer::default(),
        boostTools: ['application-info', 'tinker', 'get-config'],
        deniedFiles: ['.env', 'storage/oauth-private.key'],
    );

    $config = json_decode(file_get_contents($driver->writeConfig($dir, ['/outside/audit'])), true);
    $agent = $config['agent']['laravel-audit'];

    expect($agent['tools'])->toBe(['*' => false, 'read' => true, 'glob' => true, 'list' => true, 'laravel-boost_application-info' => true])
        ->and($agent['tools'])->not->toHaveKey('grep')
        ->and($agent['permission'])->toMatchArray(['edit' => 'deny', 'bash' => 'deny', 'webfetch' => 'deny', 'task' => 'deny'])
        ->and($agent['permission']['read'])->toBe([
            '*' => 'allow',
            '.env' => 'deny',
            '/app/.env' => 'deny',
            'storage/oauth-private.key' => 'deny',
            '/app/storage/oauth-private.key' => 'deny',
        ])
        ->and($agent['permission']['external_directory'])->toBe(['*' => 'deny', '/outside/audit/*' => 'allow'])
        ->and($config['mcp']['laravel-boost'])->toBe(['type' => 'local', 'command' => ['php', 'artisan', 'boost:mcp'], 'enabled' => true]);
});

it('limits the steps of the audit agent', function () {
    $dir = tempDir('opencode');
    $limited = json_decode(file_get_contents((new OpenCodeDriver('/app', maxTurns: 12))->writeConfig($dir)), true);
    $unlimited = json_decode(file_get_contents((new OpenCodeDriver('/app'))->writeConfig($dir)), true);

    expect($limited['agent']['laravel-audit']['steps'])->toBe(12)
        ->and($unlimited['agent']['laravel-audit'])->not->toHaveKey('steps');
});

it('runs verify calls as their own agent with fewer steps', function () {
    $driver = new OpenCodeDriver('/app', maxTurns: 15, verifyMaxTurns: 6);
    $config = json_decode(file_get_contents($driver->writeConfig(tempDir('opencode'))), true);

    expect($config['agent']['laravel-audit-verify']['steps'])->toBe(6)
        ->and($config['agent']['laravel-audit-verify']['permission'])->toBe($config['agent']['laravel-audit']['permission'])
        ->and($driver->command(new AgentRequest('k.verify', 'p', '/app', verify: true)))->toContain('laravel-audit-verify')
        ->and($driver->command(new AgentRequest('k', 'p', '/app')))->toContain('laravel-audit');
});

it('has an agent without tools that answers in one step', function () {
    $driver = new OpenCodeDriver('/app', maxTurns: 15);
    $config = json_decode(file_get_contents($driver->writeConfig(tempDir('opencode'))), true);

    expect($config['agent']['laravel-audit-answer'])->toMatchArray(['tools' => ['*' => false], 'steps' => 1])
        ->and($driver->command(new AgentRequest('skills', 'p', '/app', answerOnly: true)))->toContain('laravel-audit-answer');
});

it('parses the json event stream of a real run', function () {
    $driver = new OpenCodeDriver('/app', model: 'opencode/big-pickle');
    $result = $driver->parse(file_get_contents(fixture('agents/opencode-run.jsonl')), 0);

    expect($result->succeeded())->toBeTrue()
        ->and($result->answer)->toBe('hello world')
        ->and($result->sessionId)->toBe('ses_f6f48795dffe3KYHTjDTTIYtYf')
        ->and($result->model)->toBe('opencode/big-pickle')
        ->and($result->telemetry->turns)->toBe(2)
        ->and($result->telemetry->inputTokens)->toBe(8317)
        ->and($result->telemetry->outputTokens)->toBe(132)
        ->and($result->telemetry->cacheReadTokens)->toBe(8000)
        ->and($result->telemetry->costUsd)->toBe(0.0);
});

it('resumes the session and reports errors', function () {
    $driver = new OpenCodeDriver('/app');
    $previous = $driver->parse(file_get_contents(fixture('agents/opencode-run.jsonl')), 0);
    $error = '{"type":"error","timestamp":1,"sessionID":"ses_1","error":{"name":"APIError","data":{"message":"Model hy3-free is not supported"}}}';

    expect(array_slice($driver->resumeCommand($previous, new AgentRequest('k', 'p', '/app')), -2))->toBe(['--session', 'ses_f6f48795dffe3KYHTjDTTIYtYf'])
        ->and($driver->parse($error, 1))->exit->toBe(AgentExit::Error)->error->toBe('Model hy3-free is not supported')
        ->and($driver->parse('', 1)->exit)->toBe(AgentExit::Error);
});

it('fails a run in which the model only sent empty replies', function () {
    $empty = implode("\n", array_map(fn (int $step): string => json_encode(['type' => 'step_finish', 'timestamp' => $step, 'sessionID' => 'ses_1', 'part' => ['messageID' => 'm'.$step, 'reason' => 'unknown', 'tokens' => ['input' => 0, 'output' => 0]]]), range(1, 30)));

    $result = (new OpenCodeDriver('/app'))->parse($empty, 0);

    expect($result->exit)->toBe(AgentExit::Error)
        ->and($result->error)->toContain('30 empty replies')
        ->and($result->telemetry->turns)->toBe(30);
});
