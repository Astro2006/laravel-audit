<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Enums;

/**
 * Whether an agent CLI can keep the agents away from secret files
 * (agent.deny_read). Only Enforced lets a run start without an opt-in.
 */
enum ReadProtection: string
{
    /** The CLI blocks the denied files for every tool the agent has. */
    case Enforced = 'enforced';

    /** The CLI cannot block them, and the config explicitly accepts that. */
    case Waived = 'waived';

    /** The CLI cannot block them and nobody accepted that: the run refuses to start. */
    case Missing = 'missing';
}
