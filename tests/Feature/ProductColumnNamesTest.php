<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Modules\CMS\Models\Content;
use Modules\Core\Casts\FieldType;
use Modules\Core\Models\Field;
use Modules\Core\Models\Pivot\Fieldable;
use Modules\Shop\Casts\EntityType;
use Modules\Shop\Models\Entity;
use Modules\Shop\Models\Pivot\Presettable;
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

it('refuses a field named like a column of Product on the presets of the product entity', function (): void {
    expect((new Product())->extensionEntityType())->toBe(EntityType::Products->value);

    $entity_id = Entity::query()->where('type', EntityType::Products)->value('id');
    $preset_id = Presettable::query()->where('entity_id', $entity_id)->value('preset_id');
    $field = Field::query()->create(['name' => 'featured', 'type' => FieldType::Text, 'options' => new stdClass()]);

    expect(fn () => Fieldable::query()->create([
        'field_id' => $field->id,
        'preset_id' => $preset_id,
        'order_column' => 99,
        'default' => null,
        'is_required' => false,
    ]))->toThrow(ValidationException::class, 'XS17');
});
