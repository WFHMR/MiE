<?php

namespace App\Mcp\Servers;

use App\Mcp\Resources\PaymentTypesResource;
use App\Mcp\Resources\ProductCategoriesResource;
use App\Mcp\Resources\StoreConfigResource;
use App\Mcp\Resources\TaxGroupsResource;
use App\Mcp\Tools\BulkUpdateProductsTool;
use App\Mcp\Tools\CreateCategoryTool;
use App\Mcp\Tools\DeleteMediaTool;
use App\Mcp\Tools\GenerateReportTool;
use App\Mcp\Tools\GetCustomerTool;
use App\Mcp\Tools\GetDashboardSummaryTool;
use App\Mcp\Tools\GetLowStockProductsTool;
use App\Mcp\Tools\GetOrderTool;
use App\Mcp\Tools\GetProductTool;
use App\Mcp\Tools\SearchCustomersTool;
use App\Mcp\Tools\SearchOrdersTool;
use App\Mcp\Tools\SearchProductSalesTool;
use App\Mcp\Tools\SearchProductsTool;
use App\Mcp\Tools\SearchWalletHistoryTool;
use App\Mcp\Tools\UpdateCategoryTool;
use App\Mcp\Tools\UpdateProductTool;
use App\Mcp\Tools\UpdateProductUnitQuantityTool;
use App\Mcp\Tools\UpdateSettingsTool;
use App\Mcp\Tools\UploadMediaTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name( 'RGBF MCP Server' )]
#[Version( '1.0.0' )]
#[Instructions(
    'This server exposes read and write tools plus reference resources for the RGBF point-of-sale system. ' .
    'Every tool listed below is registered and callable. Do not assume any other tool exists. ' .

    'CATALOGUE (read): search_products to find products by name, barcode or SKU. ' .
    'get_product to retrieve one product by id, barcode or sku, including its unit_quantities. ' .
    'get_low_stock_products to identify inventory that needs restocking. ' .

    'CATALOGUE (write): update_product to change a product main entry such as name, description, status, category, barcode or SKU. ' .
    'It requires the product id and never touches price or stock. ' .
    'bulk_update_products to apply the same status or category change to up to 200 product ids in one call. ' .
    'update_product_unit_quantity to change price, stock quantity, barcode or scale settings for one unit quantity, identified by its own id. ' .
    'Pricing and stock always live on unit quantities, never on the product itself. ' .

    'CATEGORIES: create_product_category and update_product_category manage the category tree. ' .
    'update_product_category requires the category id. There is no tool for deleting categories or products. ' .

    'CUSTOMERS (read): search_customers to find customers, get_customer to retrieve one by id, ' .
    'search_wallet_history to track customer account operations (ADD, DEDUCT, PAYMENT, REFUND). ' .

    'ORDERS (read): search_orders to query and filter multiple orders, get_order to retrieve a specific order. ' .

    'ANALYTICS: get_dashboard_summary for sales metrics over a day or date range. ' .
    'search_product_sales to aggregate product metrics such as sales volume, most purchased or top returned. ' .
    'generate_report to turn collected data into a temporary signed PDF or HTML report with store branding, ' .
    'tables, KPI cards and pie or bar charts. Call it only after gathering the data with the read tools. ' .

    'SETTINGS: update_settings to modify global store settings and options. ' .

    'MEDIA: upload_media and delete_media to manage files and images in the media library. ' .

    'RESOURCES provide reference data that needs no arguments: store-config for settings and currency, ' .
    'payment-types for accepted payment methods, product-categories for the category tree, ' .
    'and tax-groups for all configured tax rates. Read the relevant resource before writing data ' .
    'so that ids and option keys are correct.'
)]
class POSServer extends Server
{
    /**
     * Tools exposed over the MCP transport.
     *
     * Keep this list and the #[Instructions] block above in sync. An agent can only
     * discover what is registered here, and it will try to call whatever the
     * instructions describe, so a tool named in one place must exist in the other.
     */
    protected array $tools = [
        // Catalogue reads
        SearchProductsTool::class,
        GetProductTool::class,
        GetLowStockProductsTool::class,

        // Catalogue writes
        UpdateProductTool::class,
        BulkUpdateProductsTool::class,
        UpdateProductUnitQuantityTool::class,

        // Categories
        CreateCategoryTool::class,
        UpdateCategoryTool::class,

        // Customers
        SearchCustomersTool::class,
        GetCustomerTool::class,
        SearchWalletHistoryTool::class,

        // Orders
        SearchOrdersTool::class,
        GetOrderTool::class,

        // Analytics and reporting
        GetDashboardSummaryTool::class,
        SearchProductSalesTool::class,
        GenerateReportTool::class,

        // Settings
        UpdateSettingsTool::class,

        // Media
        UploadMediaTool::class,
        DeleteMediaTool::class,
    ];

    protected array $resources = [
        StoreConfigResource::class,
        PaymentTypesResource::class,
        ProductCategoriesResource::class,
        TaxGroupsResource::class,
    ];

    protected array $prompts = [
        //
    ];
}
