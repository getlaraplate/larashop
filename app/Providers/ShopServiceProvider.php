<?php

declare(strict_types=1);

namespace Modules\Shop\Providers;

use Illuminate\Support\Facades\Gate;
use Modules\CMS\Models\Content;
use Modules\Core\Overrides\ModuleServiceProvider;
use Modules\Core\Search\Extensions\ExtensionRegistry;
use Modules\Shop\Models\Product;
use Modules\Shop\Policies\ProductPolicy;
use Override;

final class ShopServiceProvider extends ModuleServiceProvider
{
    #[Override]
    protected string $name = 'Shop';

    #[Override]
    protected string $nameLower = 'shop';

    /**
     * Provider classes to register.
     *
     * @var array<int, class-string>
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    /**
     * Register the product as an extender of CMS `Content` (E4) in the Core extension registry (spec
     * 2026-11-17-extended-entity-search-design.md, XS13). The registry is a singleton bound by Core's
     * SearchServiceProvider; resolving() runs the registration whenever it is first resolved, and the resolved()
     * guard covers the case where it was already resolved before this provider booted, so registration takes effect
     * regardless of provider order.
     */
    #[Override]
    public function register(): void
    {
        parent::register();

        $this->app->resolving(
            ExtensionRegistry::class,
            static function (ExtensionRegistry $registry): void {
                $registry->register(Content::class, Product::CONTENT_ALIAS, Product::class);
            },
        );

        if ($this->app->resolved(ExtensionRegistry::class)) {
            $this->app->make(ExtensionRegistry::class)->register(Content::class, Product::CONTENT_ALIAS, Product::class);
        }
    }

    #[Override]
    public function boot(): void
    {
        parent::boot();

        Gate::policy(Product::class, ProductPolicy::class);
    }
}
