<?php

use Astro2006\LaravelAudit\Preflight\Condensers\CodeCandidates;
use Astro2006\LaravelAudit\Support\ProjectInspector;

it('finds risky lines per pattern and skips comments', function () {
    $dir = tempDir('candidates');
    mkdir($dir.'/app/Http/Controllers', 0777, true);
    mkdir($dir.'/resources/views', 0777, true);
    mkdir($dir.'/config', 0777, true);
    file_put_contents($dir.'/app/Http/Controllers/OrderController.php', <<<'PHP'
        <?php
        class OrderController
        {
            public function index($request)
            {
                $key = env('SHOP_KEY');
                // DB::raw('commented out')
                return DB::select('select * from orders order by '.$request->sort);
            }
        }
        PHP);
    file_put_contents($dir.'/resources/views/order.blade.php', "<div>{!! \$order->notes !!}</div>\n");
    file_put_contents($dir.'/config/shop.php', "<?php return ['key' => env('SHOP_KEY')];\n");

    $patterns = collect((new CodeCandidates)->find(new ProjectInspector($dir)))->keyBy('pattern');

    expect($patterns['env() outside config']['hits'])->toBe([['file' => 'app/Http/Controllers/OrderController.php', 'line' => 6, 'code' => "\$key = env('SHOP_KEY');"]])
        ->and($patterns['raw SQL']['total'])->toBe(1)
        ->and($patterns['raw SQL']['hits'][0]['line'])->toBe(8)
        ->and($patterns['unescaped output']['hits'][0]['file'])->toBe('resources/views/order.blade.php')
        ->and($patterns['shell or process']['total'])->toBe(0);
});
