<?php

declare(strict_types=1);

use Illuminate\Support\Str;

it('keeps every source file at or below 250 lines', function () {
    $root = dirname(__DIR__, 2);
    $stillTooLong = [];
    $offences = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src')) as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $path = Str::after($file->getPathname(), $root.DIRECTORY_SEPARATOR);
        $lines = count(file($file->getPathname()) ?: []);

        if ($lines > 250 && ! in_array($path, $stillTooLong, true)) {
            $offences[] = $path.' ('.$lines.' lines)';
        }
    }

    expect($offences)->toBe([]);
})->group('core', 'architecture');
