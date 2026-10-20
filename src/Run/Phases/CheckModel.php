<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Run\Phases;

use Astro2006\LaravelAudit\Agents\DriverRegistry;
use Astro2006\LaravelAudit\Agents\ModelProbe;
use Astro2006\LaravelAudit\Console\Output\AuditConsole;
use Astro2006\LaravelAudit\Contracts\AgentDriver;
use Astro2006\LaravelAudit\Requirements\Check;
use Astro2006\LaravelAudit\Support\AuditConfig;
use Astro2006\LaravelAudit\Support\ProjectInspector;

final class CheckModel
{
    public function __construct(private readonly AuditConfig $config) {}

    /**
     * Sends the model one tool-less turn before the run starts. When it does
     * not answer, an interactive console offers the models the CLI lists.
     *
     * @return AgentDriver|null the driver with a model that answers; null when there is none and the run has to stop
     */
    public function handle(AgentDriver $driver, ProjectInspector $project, AuditConsole $console): ?AgentDriver
    {
        if (! $this->config->bool('agent.check_model')) {
            return $driver;
        }

        $messages = $console->messages;
        $probe = new ModelProbe($project);

        while (true) {
            $model = $driver->model() ?? $messages->get('model.default');
            $started = microtime(true);
            $error = $probe->check($driver);

            if ($error === null) {
                $console->checks([Check::ok($messages->get('model.ok', ['model' => $model, 'seconds' => (int) round(microtime(true) - $started)]))]);

                return $driver;
            }

            $console->checks([Check::failed($messages->get('model.failed', ['model' => $model, 'error' => $error]))]);
            $alternatives = $probe->alternatives($driver);

            if ($alternatives === []) {
                $console->line('    '.$messages->get('model.hint'));

                return null;
            }

            if (! $console->interactive) {
                $console->line('    '.$messages->get('model.available', ['models' => implode(', ', $alternatives)]));
                $console->line('    '.$messages->get('model.hint'));

                return null;
            }

            $abort = $messages->get('model.abort');
            $choice = $console->choose('model.question', [...$alternatives, $abort]);

            if ($choice === $abort) {
                return null;
            }

            $driver = (new DriverRegistry($this->config, $project))->resolve($driver->name(), $choice);
        }
    }
}
