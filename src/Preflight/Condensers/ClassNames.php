<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Preflight\Condensers;

use Astro2006\LaravelAudit\Support\Regex;

/**
 * Fully qualified class names a PHP or Blade file refers to.
 */
final class ClassNames
{
    /** Blade markers that reveal a package without a class reference: package => markers. */
    private const array BLADE_MARKERS = [
        'livewire/livewire' => ['@livewire', '<livewire:', 'wire:model', 'wire:click', '@livewireStyles', '@livewireScripts'],
        'filament/filament' => ['<x-filament'],
        'inertiajs/inertia-laravel' => ['@inertia'],
    ];

    /** @return list<string> */
    public static function in(string $code, bool $blade): array
    {
        return $blade ? self::bladeNames($code) : self::phpNames($code);
    }

    /**
     * Packages a Blade view uses through markers such as `wire:model`.
     *
     * @return list<string>
     */
    public static function bladePackages(string $code): array
    {
        $packages = [];

        foreach (self::BLADE_MARKERS as $package => $markers) {
            foreach ($markers as $marker) {
                if (str_contains($code, $marker)) {
                    $packages[] = $package;

                    break;
                }
            }
        }

        return $packages;
    }

    /** @return list<string> */
    private static function phpNames(string $code): array
    {
        $names = [];
        $skipNext = false;

        foreach (@token_get_all($code) as $token) {
            if (! is_array($token)) {
                continue;
            }

            [$id, $text] = $token;

            if ($id === T_NAMESPACE) {
                $skipNext = true;

                continue;
            }

            if ($id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED) {
                if (! $skipNext) {
                    $names[] = ltrim($text, '\\');
                }

                $skipNext = false;
            } elseif ($id !== T_WHITESPACE) {
                $skipNext = false;
            }
        }

        return $names;
    }

    /** @return list<string> */
    private static function bladeNames(string $code): array
    {
        return Regex::all('/\\\\?([A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)+)/', $code);
    }
}
