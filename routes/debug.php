<?php

use App\Exceptions\CoreException;
use App\Exceptions\CoreVersionMismatchException;
use App\Exceptions\MethodNotAllowedHttpException;
use App\Exceptions\MissingDependencyException;
use App\Exceptions\ModuleVersionMismatchException;
use App\Exceptions\NotAllowedException;
use App\Exceptions\NotFoundException;
use App\Exceptions\QueryException;
use App\Exceptions\ValidationException;
use dekor\ArrayToTextTable;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

if ( env( 'APP_DEBUG' ) ) {
    Route::get( '/routes', function () {
        /**
         * Read the collection through its public API rather than casting the
         * RouteCollection to an array and picking index 1, which depended on the
         * internal property order. Every value is cast to a string because
         * ArrayToTextTable rejects null, and domain and name are often null.
         */
        $values = collect( app( 'router' )->getRoutes()->getRoutes() )
            ->map( function ( RoutingRoute $route ) {
                return [
                    'domain' => (string) $route->getDomain(),
                    'uri' => (string) $route->uri(),
                    'methods' => collect( $route->methods() )->join( ', ' ),
                    'name' => (string) $route->getName(),
                ];
            } )
            ->values();

        return ( new ArrayToTextTable( $values->toArray() ) )->render();
    } );

    Route::get( '/exceptions', function ( Request $request ) {
        $class = $request->input( 'class' );
        $exceptions = [
            CoreException::class,
            CoreVersionMismatchException::class,
            MethodNotAllowedHttpException::class,
            MissingDependencyException::class,
            ModuleVersionMismatchException::class,
            NotAllowedException::class,
            NotFoundException::class,
            QueryException::class,
            ValidationException::class,
        ];

        if ( in_array( $class, $exceptions ) ) {
            throw new $class;
        }

        return abort( 404, 'Exception not found.' );
    } );

    Route::get( '/test-csv-parse', function () {
        $parser = app(\Modules\ProcurementImport\Services\CsvParserService::class);
        
        $file1 = 'C:/Users/chava/Desktop/NexoPOS-master/Template - สาขาท่าช้าง.csv';
        $file2 = 'C:/Users/chava/Desktop/NexoPOS-master/Template - ทะเบียนสินค้า.csv';
        
        try {
            $result1 = $parser->parse($file1);
            $result2 = $parser->parse($file2);
            
            return response()->json([
                'success' => true,
                'file1_stock_import' => [
                    'path' => $file1,
                    'exists' => file_exists($file1),
                    'type' => $result1['type'] ?? 'unknown',
                    'row_count' => count($result1['rows'] ?? []),
                    'first_row' => $result1['rows'][0] ?? null,
                    'last_row' => end($result1['rows']) ?: null,
                    'has_quantity_column' => isset($result1['rows'][0]['quantity']),
                ],
                'file2_product_only' => [
                    'path' => $file2,
                    'exists' => file_exists($file2),
                    'type' => $result2['type'] ?? 'unknown',
                    'row_count' => count($result2['rows'] ?? []),
                    'first_row' => $result2['rows'][0] ?? null,
                    'last_row' => end($result2['rows']) ?: null,
                    'has_quantity_column' => isset($result2['rows'][0]['quantity']),
                ],
            ], 200, [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => explode("\n", $e->getTraceAsString()),
            ], 500, [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
    } );
}
