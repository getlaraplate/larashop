<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\CMS\Models\Content;
use Modules\Core\Overrides\LocaleScope;
use Modules\Core\Search\Extensions\ExtensionRegistry;
use Modules\Core\Search\Schema\FieldType;
use Modules\Shop\Enums\ProductKind;
use Modules\Shop\Models\Product;

beforeEach(function (): void {
    setupShopEntities();
});

it('an extended product content is hidden from a plain Content query', function (): void {
    $product = Product::factory()->create();

    expect(Content::query()->count())->toBe(0)
        ->and(Content::query()->find($product->content_id))->toBeNull()
        ->and(Content::withExtended()->find($product->content_id))->not->toBeNull();
});

it('is registered for Content in the Core extension registry', function (): void {
    expect(app(ExtensionRegistry::class)->resolve(Content::class, Product::CONTENT_ALIAS))->toBe(Product::class)
        ->and((new Content())->extenders())->toHaveKey(Product::CONTENT_ALIAS);
});

it('indexes its company and its publication in the extension section of its content', function (): void {
    $product = Product::factory()->create(['is_published_in_shop' => true, 'featured' => false]);

    expect($product->searchableExtension())->toBe([
        'kind' => $product->kind?->value,
        'is_published_in_shop' => true,
        'featured' => false,
        'company_id' => $product->company_id,
    ])->and($product->searchableExtensionMapping()['company_id'])->toBe(['type' => FieldType::Integer, 'filterable' => true])
        ->and(Content::withExtended()->findOrFail($product->content_id)->extensionSearchDocument())->toMatchArray([
            'type' => Product::CONTENT_ALIAS,
            'company_id' => $product->company_id,
        ]);
});

it('a product listing upcasts in a constant number of queries', function (): void {
    $host = new Content();

    // The upcast consumes a lean page of extended contents: it only reads each row's key and
    // `extended_type`, then re-attaches that very content to its extender via setRelation(). The
    // editorial `presettable`/`translation` eager-loads Content carries in `$with` are not part of
    // the upcast, so the pipeline's own cost is two queries — one for the content page, one batched
    // query for all extenders — no matter how many rows the page holds (C5/C6, spec "two queries per
    // entity-scoped page").
    $fetchPage = static fn (): Collection => Content::withExtended()
        ->withoutGlobalScope(LocaleScope::class)
        ->without('presettable', 'translation')
        ->get();

    $upcastQueryCount = static function () use ($host, $fetchPage): int {
        // Warm the request-scoped permission-existence memos (Content/Product `select`) so the count
        // reflects the steady-state cost, not first-touch cache fills that happen once per request.
        $host->upcastExtensions($fetchPage());

        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();
        $host->upcastExtensions($fetchPage());
        $queries = count(DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();

        return $queries;
    };

    Product::factory()->count(5)->create();
    $resolvedForFive = $host->upcastExtensions($fetchPage());
    $queriesForFive = $upcastQueryCount();

    // Grow the page: the count must not grow with it (no N+1).
    Product::factory()->count(7)->create();
    $resolvedForTwelve = $host->upcastExtensions($fetchPage());
    $queriesForTwelve = $upcastQueryCount();

    expect($resolvedForFive)->toHaveCount(5)
        ->and($resolvedForFive->every(static fn ($item): bool => $item instanceof Product))->toBeTrue()
        ->and($resolvedForTwelve)->toHaveCount(12)
        ->and($queriesForFive)->toBe(2)
        ->and($queriesForTwelve)->toBe(2);
});

it('a content cannot be extended by two products', function (): void {
    $first = Product::factory()->create();

    $second = new Product([
        'content_id' => $first->content_id,
        'company_id' => $first->company_id,
        'kind' => ProductKind::Physical->value,
    ]);
    $second->save();
})->throws(UniqueConstraintViolationException::class);

it('deleting a product deletes its content and vice versa', function (): void {
    // Extender -> content: soft-deleting the product cascades to its content (C9/E17).
    $product = Product::factory()->create();
    $contentId = $product->content_id;

    $product->delete();

    expect(Content::withExtended()->find($contentId))->toBeNull()
        ->and(Content::withExtended()->withTrashed()->find($contentId))->not->toBeNull();

    // Content -> extender: deleting the content directly cascades to the product (no bodiless orphan).
    $other = Product::factory()->create();

    $other->content->delete();

    expect(Product::query()->find($other->getKey()))->toBeNull()
        ->and(Product::withTrashed()->find($other->getKey()))->not->toBeNull();
});

it('product reads and writes merged content fields', function (): void {
    $product = Product::factory()->create();

    // Read-through: a content-owned attribute reads as if native on the product.
    expect($product->valid_from)->not->toBeNull()
        ->and($product->valid_from->toDateString())->toBe($product->content->valid_from->toDateString());

    // Write-through: setting a content-owned attribute on the product root persists it on the Content.
    $date = now()->addDays(10);
    $product->valid_to = $date;
    $product->save();

    $fresh = Product::query()->findOrFail($product->getKey());

    expect($fresh->valid_to)->not->toBeNull()
        ->and($fresh->valid_to->toDateString())->toBe($date->toDateString())
        ->and($fresh->content->valid_to->toDateString())->toBe($date->toDateString());
});

it('persists a translatable content field written through the product root', function (): void {
    $product = Product::factory()->create();

    // `title` is a translatable field: it buffers in the content's pending translations and never
    // makes the Content dirty, so the write-through must still flush it on save (E2a).
    $title = 'Merged Product Title ' . uniqid();
    $product->title = $title;

    // Read-through on the same instance before saving.
    expect($product->title)->toBe($title);

    $product->save();

    $fresh = Product::query()->findOrFail($product->getKey());

    expect($fresh->title)->toBe($title)
        ->and($fresh->content->title)->toBe($title);
});

/*
 * A product shares the primary key of its content: `shop_products.id` is the id of the content it extends, so one
 * id names the product on the content routes and on the product routes alike, and a mixed list holds one id space.
 */

/**
 * A saved content a product can be linked to, on the Shop product entity.
 */
function shop_saved_backing_content(): Content
{
    $content = Product::newBackingContent();
    $content->save();

    return $content;
}

it('takes the id of its content', function (): void {
    $product = Product::factory()->create();

    expect($product->getKey())->toBe($product->content_id)
        ->and(Product::query()->find($product->content_id)?->is($product))->toBeTrue();
});

it('takes the id of an existing content it is linked to', function (): void {
    $company_id = Product::factory()->create()->company_id;
    $content = shop_saved_backing_content();

    $product = new Product([
        'content_id' => $content->getKey(),
        'company_id' => $company_id,
        'kind' => ProductKind::Physical->value,
    ]);
    $product->save();

    expect($product->getKey())->toBe($content->getKey());
});

it('refuses an id that is not the id of its content', function (): void {
    $company_id = Product::factory()->create()->company_id;
    $content = shop_saved_backing_content();

    $product = new Product([
        'content_id' => $content->getKey(),
        'company_id' => $company_id,
        'kind' => ProductKind::Physical->value,
    ]);
    $product->id = $content->getKey() + 1000;
    $product->save();
})->throws(LogicException::class);

it('never moves a product to another content', function (): void {
    $product = Product::factory()->create();
    $other = shop_saved_backing_content();

    $product->content_id = $other->getKey();
    $product->save();
})->throws(LogicException::class);

it('does not let an update change the content of a product', function (): void {
    expect((new Product)->getRules()['update'])->not->toHaveKey('content_id');
});

it('refuses in the database a row whose id is not the id of its content', function (): void {
    $product = Product::factory()->create();
    $other = shop_saved_backing_content();

    DB::table((new Product)->getTable())->where('id', $product->getKey())->update(['content_id' => $other->getKey()]);
})->throws(Illuminate\Database\QueryException::class)
    ->skip(fn (): bool => ! in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql', 'mariadb'], true), 'The database guard exists on PostgreSQL, MySQL and MariaDB only.');
