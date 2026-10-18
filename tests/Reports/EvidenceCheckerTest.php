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
    'preflight reference' => ["preflight: P-01\n```\nundefined property \$day\n```"],
    'short lines' => ["```php\n}\n];\n```"],
]);

it('has nothing to compare without files', function () {
    expect((new EvidenceChecker)->matches("```php\n\$order->fill(\$data)->save();\n```", []))->toBeNull();
});

$controller = <<<'PHP'
    <?php

    namespace App\Http\Controllers\ScTools;

    use App\Http\Controllers\Controller;
    use App\Http\Requests\SaveFleetyardsLoginRequest;

    final class SaveFleetyardsLoginController extends Controller
    {
        public function __invoke(SaveFleetyardsLoginRequest $request): RedirectResponse
        {
            $request->user()->fleetyardsAccount->fill($request->loginAttributes())->save();
        }
    }
    PHP;

it('does not let real boilerplate carry an invented method', function () use ($controller) {
    $evidence = "```php\n<?php\n\nnamespace App\\Http\\Controllers\\ScTools;\n\nuse App\\Http\\Controllers\\Controller;\n\nclass SaveFleetyardsLoginController extends Controller\n{\n    public function __invoke(Request \$request)\n    {\n        // This method is not used\n    }\n}\n";

    expect((new EvidenceChecker)->matches($evidence, [$controller]))->toBeFalse();
});

it('reads code in code and pre elements', function () use ($controller) {
    expect((new EvidenceChecker)->matches("<code>\n\$this->assertStringContainsString('window.frontendTokens', \$output);\n</code>", [$controller]))->toBeFalse()
        ->and((new EvidenceChecker)->matches("<pre>\n\$request->user()->fleetyardsAccount->fill(\$request->loginAttributes())->save();\n</pre>", [$controller]))->toBeTrue();
});

it('compares boilerplate when the quote has nothing else', function () use ($controller) {
    expect((new EvidenceChecker)->matches("```php\nfinal class SaveFleetyardsLoginController extends Controller\n```", [$controller]))->toBeTrue();
});

it('keeps lines with multi-byte characters such as ✅ whole', function () {
    $file = "<?php\n\n\$status = '✅ done';\n\$label = strtoupper(\$status);\n";

    expect((new EvidenceChecker)->matches("```php\n\$status = '✅ done';\n\$label = strtoupper(\$status);\n```", [$file]))->toBeTrue()
        ->and((new EvidenceChecker)->matches("```php\n\$status = '✅ open';\n\$label = strtolower(\$status);\n```", [$file]))->toBeFalse();
});

it('strips line numbers without a space after them', function () use ($file) {
    expect((new EvidenceChecker)->matches("```php\n9:\$order->fill(\$data)->save();\n11:return \$order;\n```", [$file]))->toBeTrue();
});
