<?php

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->each->not->toBeUsed();

arch('enums are backed enums')
    ->expect('Astro2006\LaravelAudit\Enums')
    ->toBeStringBackedEnums();

arch('contracts are interfaces')
    ->expect('Astro2006\LaravelAudit\Contracts')
    ->toBeInterfaces();

arch('source files declare strict types')
    ->expect('Astro2006\LaravelAudit')
    ->toUseStrictTypes();
