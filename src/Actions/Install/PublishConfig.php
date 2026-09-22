<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Actions\Install;

use Illuminate\Support\Facades\Artisan;

/**
 * Publishes config/audit.php once. An existing file is never overwritten:
 * it only holds the keys the project changed, everything else comes from
 * the package defaults.
 */
final class PublishConfig
{
    public function handle(): void
    {
        if (! is_file(config_path('audit.php'))) {
            Artisan::call('vendor:publish', ['--tag' => 'audit-config']);
        }
    }
}
