<?php

declare(strict_types=1);

use Illuminate\Support\Str;

it('explains itself through names, not through comments', function () {
    $root = dirname(__DIR__, 2);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src'));
    $offences = [];

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $path = Str::after($file->getPathname(), $root.DIRECTORY_SEPARATOR);

        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (! is_array($token)) {
                continue;
            }

            $isPlainComment = $token[0] === T_COMMENT;
            $isProseDocBlock = $token[0] === T_DOC_COMMENT && preg_match('/@\w+/', $token[1]) !== 1;

            if ($isPlainComment || $isProseDocBlock) {
                $offences[] = $path.':'.$token[2].' '.Str::limit(trim(Str::before($token[1], "\n")), 60);
            }
        }
    }

    expect($offences)->toBe([]);
})->group('core', 'architecture');
