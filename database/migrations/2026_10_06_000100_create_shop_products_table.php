<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\CMS\Enums\CMSTables;
use Modules\Core\Helpers\MigrateUtils;
use Modules\Core\Support\Db2Drivers;
use Modules\ERP\Helpers\ERPMigrateUtils;
use Modules\Shop\Enums\ProductKind;
use Modules\Shop\Enums\ShopTables;

return new class extends Migration
{
    public function up(): void
    {
        $table_name = ShopTables::Products->value;
        $connection = Schema::getConnection();

        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            // Shared key: a product is identified by the id of its content (`id` = `content_id`), so one id names it
            // on the content routes and on the product routes alike. Not generated here: the model sets it on create.
            $table->unsignedBigInteger('id')->primary();

            // E23: the product carries its tenant via ERP BelongsToCompany.
            ERPMigrateUtils::companyForeign($table, indexed: false);

            // E16: one extender per content — content_id is mandatory and unique. The FK cascades on
            // delete so a hard-deleted content can never leave a bodiless product (E17); the soft
            // lifecycle is mirrored in PHP by the content-extension seam.
            $table->foreignId('content_id')
                ->unique()
                ->constrained(CMSTables::Contents->value, 'id', "{$table_name}_content_id_FK")
                ->cascadeOnDelete();

            $table->string('kind')->default(ProductKind::Physical->value);
            $table->boolean('is_published_in_shop')->default(false);
            $table->boolean('featured')->default(false);
            $table->date('release_date')->nullable();
            $table->json('metadata')->nullable();

            MigrateUtils::timestamps($table, hasCreateUpdate: true, hasSoftDelete: true);

            $table->index(['company_id', 'is_published_in_shop'], "{$table_name}_company_published_IDX");
        });

        // The database keeps `id` = `content_id` where it can, as Core's taxonomies migration guards its own rule:
        // a CHECK on PostgreSQL and Db2; triggers on MySQL and MariaDB, which refuse a CHECK on a column a foreign key uses
        // (error 3823). SQLite cannot add a CHECK to an existing table: there the model is the only guard.
        $driver_name = $connection->getDriverName();
        $grammar = $connection->getQueryGrammar();
        $wrapped_table = $grammar->wrapTable($table_name);

        if ($driver_name === 'pgsql' || Db2Drivers::is($driver_name)) {
            $wrapped_constraint = $grammar->wrap("{$table_name}_id_content_CHECK");
            $wrapped_id = $grammar->wrap('id');
            $wrapped_content_id = $grammar->wrap('content_id');
            $connection->statement("ALTER TABLE {$wrapped_table} ADD CONSTRAINT {$wrapped_constraint} CHECK ({$wrapped_id} = {$wrapped_content_id})");
        } elseif (in_array($driver_name, ['mysql', 'mariadb'], true)) {
            foreach (['INSERT' => 'insert', 'UPDATE' => 'update'] as $event => $suffix) {
                $wrapped_trigger = $grammar->wrap("{$table_name}_id_content_{$suffix}");
                $connection->unprepared(<<<SQL
                    CREATE TRIGGER {$wrapped_trigger}
                    BEFORE {$event} ON {$wrapped_table}
                    FOR EACH ROW
                    BEGIN
                        IF NEW.id <> NEW.content_id THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A product takes the id of its content';
                        END IF;
                    END;
                SQL);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(ShopTables::Products->value);
    }
};
