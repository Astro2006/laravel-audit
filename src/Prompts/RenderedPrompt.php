<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Prompts;

final readonly class RenderedPrompt
{
    /**
     * @param  string  $hash  identifies the prompt version (templates + checks), not the run
     */
    public function __construct(
        public string $text,
        public string $hash,
    ) {}
}
