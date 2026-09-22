<?php

use Astro2006\LaravelAudit\Preflight\Condensers\MigrationSchema;
use Astro2006\LaravelAudit\Support\ProjectInspector;

function migrationProject(array $migrations): ProjectInspector
{
    $dir = tempDir('migrations');
    mkdir($dir.'/database/migrations', 0777, true);

    foreach ($migrations as $name => $code) {
        file_put_contents($dir.'/database/migrations/'.$name.'.php', "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\n\nreturn new class extends Migration\n{\n".$code."\n};\n");
    }

    return new ProjectInspector($dir);
}

it('reads tables, columns, indexes and foreign keys from the migrations', function () {
    $schema = (new MigrationSchema)->read(migrationProject([
        '2024_01_01_000000_create_orders_table' => <<<'PHP'
                public function up(): void
                {
                    Schema::create('orders', function (Blueprint $table) {
                        $table->id();
                        $table->foreignId('user_id')->constrained();
                        $table->unsignedBigInteger('shop_id');
                        $table->float('total_price');
                        $table->string('shipped_at')->nullable();
                        $table->string('number')->unique();
                        $table->timestamps();
                    });
                }

                public function down(): void
                {
                    Schema::dropIfExists('orders');
                }
            PHP,
        '2024_02_01_000000_add_index_to_orders' => <<<'PHP'
                public function up(): void
                {
                    Schema::table('orders', function (Blueprint $table) {
                        $table->index('user_id');
                    });
                }
            PHP,
    ]));

    $orders = $schema['tables']['orders'];

    expect($orders['columns'])->toMatchArray(['id' => 'id', 'user_id' => 'foreignId', 'shop_id' => 'unsignedBigInteger', 'total_price' => 'float', 'number' => 'string'])
        ->and($orders['foreign'])->toBe(['user_id'])
        ->and($orders['indexed'])->toContain('id', 'user_id')
        ->and($orders['unique'])->toBe(['number'])
        ->and($orders['migrations'])->toHaveCount(2)
        ->and($orders['flags'])->toBe([
            '`shop_id` has no foreign key constraint',
            '`shop_id` has no explicit index',
            '`total_price` holds money as float',
            '`shipped_at` holds a date as string',
        ])
        ->and($schema['migrations'][0]['flags'])->toBe([])
        ->and($schema['migrations'][1]['flags'])->toBe(['no down()']);
});

it('flags migrations that drop or change data', function () {
    $schema = (new MigrationSchema)->read(migrationProject([
        '2024_03_01_000000_cleanup' => <<<'PHP'
                public function up(): void
                {
                    Schema::table('orders', function (Blueprint $table) {
                        $table->dropColumn('legacy');
                    });
                    DB::table('orders')->update(['status' => 'open']);
                }

                public function down(): void
                {
                    //
                }
            PHP,
    ]));

    expect($schema['migrations'][0]['flags'])->toBe(['empty down()', 'drops data in up()', 'changes data in up()']);
});
