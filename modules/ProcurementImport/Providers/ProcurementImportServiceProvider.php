<?php

namespace Modules\ProcurementImport\Providers;

use App\Services\ModulesService;
use Illuminate\Support\ServiceProvider;

class ProcurementImportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register services
    }

    public function boot(): void
    {
        // Load routes
        $this->loadRoutesFrom(__DIR__ . '/../Routes/web.php');
        
        // Load migrations
        $this->loadMigrationsFrom(__DIR__ . '/../Migrations');
        
        // Load views
        $this->loadViewsFrom(__DIR__ . '/../Resources/Views', 'ProcurementImport');
    }
}
