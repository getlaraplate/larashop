<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\CMS\Database\Seeders\CMSDatabaseSeeder;
use Modules\CMS\Models\Content;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Models\ACL;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Support\CrudApiExposure;
use Modules\Core\Support\PermissionName;
use Modules\Core\Tests\Support\FakeVectorProfile;
use Modules\Shop\Database\Seeders\ShopDatabaseSeeder;
use Modules\Shop\Models\Product;

/*
 * A product is online for the public when its content is in its publication window and it is published in the shop
 * (E18; spec 2026-11-17-extended-entity-search-design.md, XS9). The anonymous reader of the api gets that condition
 * from the ACL the Shop seeder puts on the `guest` role, on the product routes and in the content list opened with
 * `with_extended`; a user restricted to a company sees that company's products only, on the same reads.
 */

beforeEach(function (): void {
    Cache::flush();
    Carbon::setTestNow('2026-08-19 12:00:00');
    FakeVectorProfile::none();
    CrudApiExposure::enable();
    setupShopEntities();

    Artisan::call('permission:refresh', ['--quiet' => true]);
    $this->seed(CoreDatabaseSeeder::class);
    $this->seed(CMSDatabaseSeeder::class);
    // Again, now that the guest role exists: the grant needs it.
    $this->seed(ShopDatabaseSeeder::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * A product whose content has the given publication window.
 *
 * @param  array<string, mixed>  $attributes
 */
function public_product(?string $valid_from, ?string $valid_to = null, array $attributes = []): Product
{
    $product = Product::factory()->create(['is_published_in_shop' => true, ...$attributes]);

    // The window is written on the row: approvals of a published content are not what this test is about.
    DB::table((new Content)->getTable())->where('id', $product->content_id)->update([
        'valid_from' => $valid_from === null ? null : Carbon::parse($valid_from),
        'valid_to' => $valid_to === null ? null : Carbon::parse($valid_to),
    ]);

    return $product;
}

/**
 * @return list<int>
 */
function public_product_ids(TestResponse $response): array
{
    $response->assertOk();

    return collect($response->json('data'))->pluck('id')->map(static fn (mixed $id): int => (int) $id)->sort()->values()->all();
}

/**
 * The ids each read returns, from the product routes and from the content list opened to its extenders.
 *
 * @param  string  $routes  `core.api` for the anonymous api, `core.crud` for the session app
 * @return array<string, list<int>>
 */
function public_product_reads(string $routes): array
{
    $products = ['module' => 'shop', 'entity' => 'products'];
    $contents = ['module' => 'cms', 'entity' => 'contents'];

    return [
        'select products' => public_product_ids(test()->getJson(route("{$routes}.list", $products))),
        'search products' => public_product_ids(test()->getJson(route("{$routes}.search", [...$products, 'qs' => Product::CONTENT_ALIAS]))),
        'select contents' => public_product_ids(test()->getJson(route("{$routes}.list", [...$contents, 'with_extended' => 1]))),
        'search contents' => public_product_ids(test()->getJson(route("{$routes}.search", [...$contents, 'qs' => Product::CONTENT_ALIAS, 'with_extended' => 1]))),
    ];
}

/**
 * @param  list<int>  $ids
 * @return array<string, list<int>>
 */
function public_product_everywhere(array $ids): array
{
    return ['select products' => $ids, 'search products' => $ids, 'select contents' => $ids, 'search contents' => $ids];
}

it('shows the anonymous reader a product only while its content is valid and it is published in the shop', function (): void {
    $online = public_product('2026-08-01');
    $ending = public_product('2026-08-01', '2026-09-01');
    // Hidden whatever the flag: a draft or scheduled content, and an expired one.
    public_product('2026-09-01');
    public_product('2026-07-01', '2026-08-01');
    // Hidden whatever the content: not published in the shop.
    public_product('2026-08-01', null, ['is_published_in_shop' => false]);

    $expected = [$online->id, $ending->id];
    sort($expected);

    expect(public_product_reads('core.api'))->toBe(public_product_everywhere($expected));
});

it('shows a user restricted to a company only the products of that company', function (): void {
    $own = public_product('2026-08-01');
    public_product('2026-08-01');

    $role = Role::factory()->create(['name' => 'shop_company_' . uniqid(), 'guard_name' => 'web']);
    $products = Permission::findOrCreate(PermissionName::forClass(Product::class, 'select'), 'web');
    $role->givePermissionTo([$products, Permission::findOrCreate(PermissionName::forClass(Content::class, 'select'), 'web')]);

    $acl = new ACL;
    $acl->setSkipValidation(true);
    $acl->forceFill([
        'permission_id' => $products->id,
        'filters' => new FiltersGroup([new Filter('company_id', $own->company_id)]),
        'unrestricted' => false,
        'priority' => 10,
        'is_active' => true,
    ]);
    $acl->save();

    $user = User::factory()->create();
    $user->assignRole($role);
    $this->actingAs($user);

    expect(public_product_reads('core.crud'))->toBe(public_product_everywhere([$own->id]));
});
