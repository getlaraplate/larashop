<?php

declare(strict_types=1);

use Modules\CMS\Models\Content;
use Modules\Shop\Models\Product;

beforeEach(function (): void {
    setupShopEntities();
});

/*
 * XS17 of spec docs/superpowers/specs/2026-11-17-extended-entity-search-design.md: an extender's own columns never
 * share a name with an attribute of its host, whatever the type, except the native keys. The merge of Product routes
 * an own column to the product and every other key to its content, so a shared name would make the content's field
 * unreachable through the product, silently, and the search would translate a filter on it to the wrong field.
 */
it('owns no column named like a column or a translated field of its content', function (): void {
    $native = ['id', 'created_at', 'updated_at', 'deleted_at', 'is_deleted'];
    $own = array_values(array_diff((new Product())->extensionOwnColumns(), $native));

    $content = new Content();
    $host = [
        ...$content->getConnection()->getSchemaBuilder()->getColumnListing($content->getTable()),
        ...Content::getTranslatableFields(),
    ];

    $shared = array_values(array_intersect($own, $host));

    expect($shared)->toBe([], sprintf(
        'XS17: the own columns %s of Product are also attributes of Content. Rename them on Product: a shared name hides the content field behind the product column.',
        json_encode($shared),
    ));
});

it('declares as its own columns exactly the keys its merge keeps for itself', function (): void {
    $columns = (new Product())->getConnection()->getSchemaBuilder()->getColumnListing((new Product())->getTable());

    expect(array_diff($columns, (new Product())->extensionOwnColumns()))->toBe([]);
});
