<?php

use Astro2006\LaravelAudit\Agents\AgentRequest;
use Astro2006\LaravelAudit\Agents\DriverRegistry;
use Astro2006\LaravelAudit\Agents\Drivers\ClaudeCodeDriver;
use Astro2006\LaravelAudit\Agents\Drivers\OpenCodeDriver;
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
    $registry = new DriverRegistry(auditConfig(['agent' => ['driver' => 'claude']]), registryProject());

    expect($registry->resolve())->toBeInstanceOf(ClaudeCodeDriver::class)
        ->and($registry->resolve('opencode'))->toBeInstanceOf(OpenCodeDriver::class);
});

it('rejects unknown drivers', function () {
    (new DriverRegistry(auditConfig(), registryProject()))->resolve('cursor');
})->throws(InvalidSelection::class, 'Unknown agent driver "cursor"');

it('picks the first available boost agent in auto mode', function () {
    $fake = tempDir('bin').'/opencode';
    touch($fake);

    $config = ['agent' => ['driver' => 'auto', 'drivers' => [
        'claude_code' => ['binary' => '/missing/claude'],
        'opencode' => ['binary' => $fake],
    ]]];

    expect((new DriverRegistry(auditConfig($config), registryProject(['cursor', 'codex', 'opencode'])))->resolve())->toBeInstanceOf(OpenCodeDriver::class)
        ->and((new DriverRegistry(auditConfig($config), registryProject()))->boostAgents())->toBe([]);
});

it('names the first candidate when no agent CLI is installed', function () {
    $config = ['agent' => ['drivers' => [
        'claude_code' => ['binary' => '/missing/claude'],
        'opencode' => ['binary' => '/missing/opencode'],
    ]]];

    expect((new DriverRegistry(auditConfig($config), registryProject(['codex'])))->resolve()->name())->toBe('claude_code');
});

it('lets --model win over agent.model and agent.model over the CLI default', function () {
    $config = auditConfig(['agent' => ['model' => 'global']]);

    expect((new DriverRegistry(auditConfig(), registryProject()))->resolve('opencode')->model())->toBeNull()
        ->and((new DriverRegistry($config, registryProject()))->resolve('opencode')->model())->toBe('global')
        ->and((new DriverRegistry($config, registryProject()))->resolve('opencode', 'cli')->model())->toBe('cli');
});

it('no longer reads the pre-driver claude keys, but reports them', function () {
    $config = auditConfig(['agent' => ['driver' => 'claude', 'binary' => '/opt/claude', 'max_budget_usd' => '2', 'drivers' => ['opencode' => ['model' => 'ollama/local']]]]);
    $driver = (new DriverRegistry($config, registryProject()))->resolve();

    expect($driver->binary())->toBe('claude')
        ->and(implode(' ', $driver->command(new AgentRequest('k', 'p', '/app'))))->not->toContain('--max-budget-usd')
        ->and($config->legacyKeys())->toBe([
            'agent.binary' => 'agent.drivers.claude_code.binary',
            'agent.max_budget_usd' => 'agent.drivers.claude_code.max_budget_usd',
            'agent.drivers.opencode.model' => 'agent.model',
        ]);
});

it('reads the claude binary and budget from its driver keys', function () {
    $driver = (new DriverRegistry(auditConfig(['agent' => ['driver' => 'claude', 'drivers' => ['claude_code' => ['binary' => '/opt/claude', 'max_budget_usd' => '2']]]]), registryProject()))->resolve();

    expect($driver->binary())->toBe('/opt/claude')
        ->and(implode(' ', $driver->command(new AgentRequest('k', 'p', '/app'))))->toContain('--max-budget-usd 2');
});
