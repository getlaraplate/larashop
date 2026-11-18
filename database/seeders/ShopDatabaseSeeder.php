<?php

declare(strict_types=1);

namespace Modules\Shop\Database\Seeders;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Casts\ActionEnum;
use Modules\Core\Casts\FieldType;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\WhereClause;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Models\ACL;
use Modules\Core\Models\Field;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Overrides\Seeder;
use Modules\Core\Services\DynamicContentsService;
use Modules\Core\Services\PresetVersioningService;
use Modules\Core\Support\PermissionName;
use Modules\Shop\Casts\EntityType;
use Modules\Shop\Models\Entity;
use Modules\Shop\Models\Preset;
use Modules\Shop\Models\Product;
use Spatie\Permission\PermissionRegistrar;

/**
 * Bootstraps the Shop module: the product content entity and its default preset, and the products the anonymous
 * reader of the api sees.
 *
 * The product entity is the module's own (it is not a CMS entity type), so the module seeds it.
 * Per-category presets, the attribute sets of the storefront, are added by later work.
 */
final class ShopDatabaseSeeder extends Seeder
{
    private const string PRODUCT_ENTITY = 'product';

    private const string DEFAULT_PRESET = 'standard';

    /**
     * The body of a product, shared with the CMS (same name and definition as the CMS field).
     */
    private const string BODY_FIELD = 'content';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Model::unguarded(function (): void {
            $this->defaultEntities();
            $this->defaultGuestApiGrants();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        DynamicContentsService::getInstance()->clearAllCaches();
    }

    /**
     * Creates the product entity unless one already exists, whatever its name or activation: the
     * guard is the entity type, so an operator renaming or deactivating it does not get a second
     * default entity and preset on the next seed.
     *
     * The preset snapshot is frozen after the fields are attached: BelongsToMany bulk operations
     * do not fire the pivot events that would otherwise version the preset.
     */
    private function defaultEntities(): void
    {
        $this->logOperation(Entity::class);

        $entity_model = new Entity;

        $entity_model->getConnection()->transaction(function () use ($entity_model): void {
            if ($entity_model->newQuery()->withoutGlobalScopes()->where('type', EntityType::Products)->exists()) {
                $this->logOutcome(0, 0, 1);

                return;
            }

            $created = 1;
            $realigned = 0;

            /** @var Entity $entity */
            $entity = $this->create(Entity::class, [
                'name' => self::PRODUCT_ENTITY,
                'type' => EntityType::Products,
                'is_default' => true,
            ]);

            /** @var Preset $preset */
            $preset = $this->create(Preset::class, ['name' => self::DEFAULT_PRESET, 'entity_id' => $entity->id]);

            $preset->fields()->attach($this->bodyField($created, $realigned)->getKey(), ['is_required' => true, 'default' => null]);

            resolve(PresetVersioningService::class)->createVersion($preset);

            $this->logOutcome($created, $realigned);
        });
    }

    /**
     * The condition under which a product is online for the public (E18; spec
     * `docs/superpowers/specs/2026-11-17-extended-entity-search-design.md`, XS9): its content is in its publication
     * window and the product is published in the shop. Column conditions only, never the `valid` scope: a search
     * engine receives no scope, and these conditions reach the `contents` index (`valid_from`, `valid_to`,
     * `extension.is_published_in_shop`), so an anonymous search is not cut short at reload. No approval condition:
     * a write that needs approval never reaches the row (verified in Core `HasApprovals`, XS9).
     */
    public static function onlineProductFilters(): FiltersGroup
    {
        return new FiltersGroup(
            filters: [
                new Filter('valid_from', '@now', FilterOperator::LessEquals),
                new FiltersGroup(
                    filters: [
                        new Filter('valid_to', '@now', FilterOperator::GreatEquals),
                        new Filter('valid_to', null, FilterOperator::Equals),
                    ],
                    operator: WhereClause::Or,
                ),
                new Filter('is_published_in_shop', true, FilterOperator::Equals),
            ],
            operator: WhereClause::And,
        );
    }

    /**
     * Grant the `guest` role of the api guard the select of products, narrowed by {@see self::onlineProductFilters()},
     * next to the CMS public grants. An ACL that exists is never overwritten: an operator may have changed it.
     */
    private function defaultGuestApiGrants(): void
    {
        $this->logOperation(ACL::class);

        /** @var class-string<Permission> $permission_class */
        $permission_class = config('permission.models.permission');

        /** @var class-string<Role> $role_class */
        $role_class = config('permission.models.role');

        $guest = $role_class::query()
            ->where(['name' => CoreDatabaseSeeder::getDefaultUserRoles()['guest'], 'guard_name' => CoreDatabaseSeeder::API_GUARD])
            ->first(['id', 'guard_name']);

        if ($guest === null) {
            $this->command?->line('    - api guest role missing, skipping public grants');

            return;
        }

        $permission_name = PermissionName::forClass(Product::class, ActionEnum::Select->value);
        $permission = $permission_class::query()->where(['name' => $permission_name, 'guard_name' => $guest->guard_name])->first(['id']);

        if ($permission === null) {
            $this->command?->line("    - permission {$permission_name} missing, skipping its public grant");

            return;
        }

        $created = $guest->permissions()->syncWithoutDetaching([$permission->id])['attached'] === [] ? 0 : 1;
        $unchanged = 1 - $created;

        if (ACL::query()->where('permission_id', $permission->id)->where('role_id', $guest->id)->exists()) {
            $unchanged++;
        } else {
            $acl = new ACL;
            $acl->setSkipValidation(true);
            $acl->forceFill([
                'permission_id' => $permission->id,
                'role_id' => $guest->id,
                'filters' => self::onlineProductFilters(),
                'description' => 'Anonymous readers of the api see only the products online in the shop.',
                'unrestricted' => false,
                'priority' => 100,
                'is_active' => true,
            ]);
            $acl->save();
            $created++;
        }

        $this->logOutcome($created, 0, $unchanged);
    }

    /**
     * The body field is shared with the CMS: reuse its `content` field by name and create the same
     * definition only when none exists. A soft-deleted one is restored rather than attached
     * trashed (the preset snapshot would come out empty) or recreated (Core's `unique` rule on the
     * field name does not look at soft-deleted rows, so the create would be refused). A created
     * field counts in `$created`, a restored one in `$realigned`.
     */
    private function bodyField(int &$created, int &$realigned): Field
    {
        $field_model = new Field;
        $existing = $field_model->newQuery()->withTrashed()->where($field_model->qualifyColumn('name'), self::BODY_FIELD)->first();

        if ($existing instanceof Field) {
            if ($existing->trashed()) {
                $existing->restore();
                $realigned++;
            }

            return $existing;
        }

        /** @var Field $field */
        $field = $this->create(Field::class, ['name' => self::BODY_FIELD, 'type' => FieldType::Editor, 'options' => (object) [], 'is_translatable' => true]);
        $created++;

        return $field;
    }
}
