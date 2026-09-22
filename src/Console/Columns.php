<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Console;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Helper;

final class Columns
{
    public static function detail(string $left, string $right, int $width): string
    {
        $dots = max(1, $width - 6 - self::width($left) - self::width($right));

        return '  '.$left.' <fg=gray>'.str_repeat('.', $dots).'</> '.$right;
    }

    public static function fit(string $text, int $width): string
    {
        return mb_strimwidth($text, 0, max(10, $width - 2), '…');
    }

    private static function width(string $text): int
    {
        return Helper::width(Helper::removeDecoration(new OutputFormatter, $text));
    }
}
