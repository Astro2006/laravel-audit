<?php

use Astro2006\LaravelAudit\Reports\EvidenceChecker;

$file = <<<'PHP'
    <?php

    class OrderService
    {
        public function update($order, array $data)
        {
            $order->fill($data)->save();

            return $order;
        }
    }
    PHP;

it('accepts quotes with different whitespace, line numbers and omissions', function () use ($file) {
    $evidence = "```php\n9:   \$order->fill( \$data )->save();\n    // ...\n    return \$order;\n```";

    expect((new EvidenceChecker)->matches($evidence, [$file]))->toBeTrue();
});

it('rejects code that is not in the file', function () use ($file) {
    $evidence = "```php\n\$sort = \$request->get('sort');\n\$query->orderByRaw(\$sort);\n```";

    expect((new EvidenceChecker)->matches($evidence, [$file]))->toBeFalse();
});

it('has nothing to compare without a code block, for tool output or short lines', function (string $evidence) use ($file) {
    expect((new EvidenceChecker)->matches($evidence, [$file]))->toBeNull();
})->with([
    'prose' => ['No dispatch of the job was found.'],
    'tool output' => ["```text\nParameter #1 \$x of method foo() expects int, string given.\n```"],
    'preflight reference' => ["See preflight/phpstan/phpstan-grouped.json:\n```\nundefined property \$day\n```"],
    'short lines' => ["```php\n}\n];\n```"],
]);

it('has nothing to compare without files', function () {
    expect((new EvidenceChecker)->matches("```php\n\$order->fill(\$data)->save();\n```", []))->toBeNull();
});
