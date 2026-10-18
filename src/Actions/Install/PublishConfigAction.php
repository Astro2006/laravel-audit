<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Actions\Install;

use Illuminate\Support\Facades\Artisan;

final class PublishConfigAction
{
    public function handle(): void
    {
        if (! is_file(config_path('audit.php'))) {
            Artisan::call('vendor:publish', ['--tag' => 'audit-config']);
        }
    }
}
