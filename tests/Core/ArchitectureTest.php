<?php

declare(strict_types=1);

it('will not use any debug function')
    ->expect(['dd', 'ray', 'dump'])
    ->each
    ->not()
    ->toBeUsed()
    ->group('core', 'architecture');
