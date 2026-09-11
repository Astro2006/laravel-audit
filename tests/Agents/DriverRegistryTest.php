<?php

use Astro2006\LaravelAudit\Agents\AgentRequest;
use Astro2006\LaravelAudit\Agents\ClaudeCodeDriver;
use Astro2006\LaravelAudit\Agents\CodexDriver;
use Astro2006\LaravelAudit\Agents\DriverRegistry;
use Astro2006\LaravelAudit\Agents\OpenCodeDriver;
use Astro2006\LaravelAudit\Exceptions\InvalidSelection;
use Astro2006\LaravelAudit\Support\ProjectInspector;

function registryProject(array $boostAgents = []): ProjectInspector
{
    $dir = tempDir('registry');

    if ($boostAgents !== []) {
        file_put_contents($dir.'/boost.json', json_encode(['agents' => $boostAgents]));
    }

    return new ProjectInspector($dir);
}

it('resolves drivers by boost name and alias', function () {
    $registry = new DriverRegistry(['agent' => ['driver' => 'claude']], registryProject());

    expect($registry->resolve())->toBeInstanceOf(ClaudeCodeDriver::class)
        ->and($registry->resolve('opencode'))->toBeInstanceOf(OpenCodeDriver::class)
        ->and($registry->resolve('codex'))->toBeInstanceOf(CodexDriver::class);
});

it('rejects unknown drivers', function () {
    (new DriverRegistry([], registryProject()))->resolve('cursor');
})->throws(InvalidSelection::class, 'Unknown agent driver "cursor"');

it('picks the first available boost agent in auto mode', function () {
    $fake = tempDir('bin').'/opencode';
    touch($fake);

    $config = ['agent' => ['driver' => 'auto', 'drivers' => [
        'claude_code' => ['binary' => '/missing/claude'],
        'opencode' => ['binary' => $fake],
        'codex' => ['binary' => '/missing/codex'],
    ]]];

    expect((new DriverRegistry($config, registryProject(['cursor', 'codex', 'opencode'])))->resolve())->toBeInstanceOf(OpenCodeDriver::class)
        ->and((new DriverRegistry($config, registryProject()))->boostAgents())->toBe([]);
});

it('names the first candidate when no agent CLI is installed', function () {
    $config = ['agent' => ['drivers' => [
        'claude_code' => ['binary' => '/missing/claude'],
        'opencode' => ['binary' => '/missing/opencode'],
        'codex' => ['binary' => '/missing/codex'],
    ]]];

    expect((new DriverRegistry($config, registryProject(['codex'])))->resolve()->name())->toBe('codex');
});

it('lets --model and agent.model win over the driver model', function () {
    $config = ['agent' => ['model' => 'global', 'drivers' => ['opencode' => ['model' => 'ollama/local']]]];

    expect((new DriverRegistry(['agent' => ['drivers' => ['opencode' => ['model' => 'ollama/local']]]], registryProject()))->resolve('opencode')->model())->toBe('ollama/local')
        ->and((new DriverRegistry($config, registryProject()))->resolve('opencode')->model())->toBe('global')
        ->and((new DriverRegistry($config, registryProject()))->resolve('opencode', 'cli')->model())->toBe('cli');
});

it('still reads the pre-driver claude keys', function () {
    $driver = (new DriverRegistry(['agent' => ['driver' => 'claude', 'binary' => '/opt/claude', 'max_budget_usd' => '2']], registryProject()))->resolve();

    expect($driver->binary())->toBe('/opt/claude')
        ->and(implode(' ', $driver->command(new AgentRequest('k', 'p', '/app'))))->toContain('--max-budget-usd 2');
});
