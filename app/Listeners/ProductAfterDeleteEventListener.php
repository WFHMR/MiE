<?php

namespace App\Listeners;

use App\Events\ProductAfterDeleteEvent;
use App\Models\ProductCategory;
use App\Services\ProductCategoryService;
use App\Services\ProductService;

class ProductAfterDeleteEventListener
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct(
        public ProductService $productService,
        public ProductCategoryService $productCategoryService,
    ) {
        //
    }

    /**
     * Handle the event.
     *
     * Products are allowed to have no category: category_id is nullable, and a
     * category can be removed while its products stay. Passing that null straight
     * into computeProducts() raised a TypeError and made deleting such a product
     * fail outright, so the relation is checked first.
     *
     * @return void
     */
    public function handle( ProductAfterDeleteEvent $event )
    {
        $category = $event->product->category;

        if ( ! $category instanceof ProductCategory ) {
            return;
        }

        $this->productCategoryService->computeProducts( $category );
    }
}
