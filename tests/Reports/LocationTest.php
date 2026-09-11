<?php

use Astro2006\LaravelAudit\Reports\Location;

it('parses file, line, range, route and package locations', function (string $raw, string $target, string $string) {
    $location = Location::parse($raw);

    expect($location)->not->toBeNull()
        ->and($location->target())->toBe($target)
        ->and((string) $location)->toBe($string);
})->with([
    'file' => ['app/Models/User.php', 'app/Models/User.php', 'app/Models/User.php'],
    'line' => ['app/Models/User.php:42', 'app/Models/User.php', 'app/Models/User.php:42'],
    'range' => ['app/Models/User.php:42-48', 'app/Models/User.php', 'app/Models/User.php:42-48'],
    'leading ./' => ['./app/Models/User.php:3', 'app/Models/User.php', 'app/Models/User.php:3'],
    'route' => ['route:put  /orders/{order}', 'route:PUT /orders/{order}', 'route:PUT /orders/{order}'],
    'route with pipe' => ['route:GET|HEAD /', 'route:GET|HEAD /', 'route:GET|HEAD /'],
    'package' => ['package:Guzzlehttp/Guzzle', 'package:guzzlehttp/guzzle', 'package:guzzlehttp/guzzle'],
]);

it('parses a locations cell with backticks and commas', function () {
    $locations = Location::parseList('`app/A.php:1`, `route:GET /a`, `package:vendor/b`');

    [, $route] = $locations;

    expect($locations)->toHaveCount(3)
        ->and($route->route)->toBe('GET /a');
});

it('falls back to comma separated values without backticks', function () {
    expect(Location::parseList('app/A.php:1, app/B.php'))->toHaveCount(2);
});

it('detects overlapping ranges only for the same target', function () {
    $a = Location::parse('app/A.php:10-20');

    expect($a->overlaps(Location::parse('app/A.php:15')))->toBeTrue()
        ->and($a->overlaps(Location::parse('app/A.php:21-30')))->toBeFalse()
        ->and($a->overlaps(Location::parse('app/A.php')))->toBeTrue()
        ->and($a->overlaps(Location::parse('app/B.php:10')))->toBeFalse()
        ->and(Location::parse('package:a/b')->overlaps(Location::parse('package:a/b')))->toBeTrue();
});
