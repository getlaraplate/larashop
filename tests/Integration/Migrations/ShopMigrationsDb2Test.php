<?php

declare(strict_types=1);

use Modules\Core\Tests\Stubs\Database\Db2PretendConnection;

it('keeps id equal to content_id with a CHECK constraint on Db2', function (): void {
    $sql = Db2PretendConnection::capture(static function (): void {
        $migration = require base_path('Modules/Shop/database/migrations/2026_10_06_000100_create_shop_products_table.php');
        $migration->up();
    });

    $checks = array_values(array_filter($sql, static fn (string $statement): bool => str_contains($statement, 'CHECK')));

    expect($checks)->toHaveCount(1)
        ->and($checks[0])->toContain('ADD CONSTRAINT')
        ->and($checks[0])->toContain('_id_content_CHECK')
        ->and($checks[0])->toEndWith('CHECK ("ID" = content_id)')
        ->and(implode("\n", $sql))->not->toContain('CREATE TRIGGER');
});
