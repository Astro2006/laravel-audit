<?php

declare(strict_types=1);

use Illuminate\Support\Str;

it('reads arrays through the Arr helper, never through a raw offset', function () {
    $root = dirname(__DIR__, 2);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src'));
    $offences = [];

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $path = Str::after($file->getPathname(), $root.DIRECTORY_SEPARATOR);
        $tokens = [];
        $line = 1;

        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (is_array($token)) {
                $line = $token[2];

                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
            }

            $tokens[] = ['id' => is_array($token) ? $token[0] : $token, 'line' => $line];
        }

        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]['id'] !== '[') {
                continue;
            }

            $previous = $tokens[$i - 1]['id'] ?? null;
            $opensAnAccess = $previous === ']' || $previous === ')' || $previous === T_VARIABLE || $previous === T_STRING;

            if (! $opensAnAccess) {
                continue;
            }

            $close = $i;

            while (($tokens[$close]['id'] ?? null) === '[') {
                $depth = 0;

                for (; $close < $count; $close++) {
                    $depth += match ($tokens[$close]['id']) {
                        '[' => 1,
                        ']' => -1,
                        default => 0,
                    };

                    if ($depth === 0) {
                        break;
                    }
                }

                $close++;
            }

            $next = $tokens[$close]['id'] ?? null;
            $writes = in_array($next, ['=', T_PLUS_EQUAL, T_MINUS_EQUAL, T_CONCAT_EQUAL, T_COALESCE_EQUAL], true);

            if (! $writes) {
                $offences[] = $path.':'.$tokens[$i]['line'];
            }
        }
    }

    expect($offences)->toBe([]);
})->group('core', 'architecture');
