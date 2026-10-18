<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Enums;

enum AgentExit: string
{
    case Success = 'success';
    case MaxTurns = 'max_turns';
    case Budget = 'budget';
    case Timeout = 'timeout';
    case Error = 'error';
    case Limit = 'limit';
}
