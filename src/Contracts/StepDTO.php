<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Contracts;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Typed result data of one preflight step. toArray() feeds summary.json,
 * fromArray() reads it back (e.g. for --reuse-preflight).
 *
 * @extends Arrayable<string, mixed>
 */
interface StepDTO extends Arrayable
{
    /** @param array<mixed> $data */
    public static function fromArray(array $data): static;
}
