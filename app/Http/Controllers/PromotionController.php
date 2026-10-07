<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Services\MemberImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PromotionController extends Controller
{
    public function products( Request $request ): array
    {
        abort_unless( Gate::allows( 'nexopos.create.coupons' ) || Gate::allows( 'nexopos.update.coupons' ), 403 );
        $request->validate( ['q' => 'nullable|string|max:120', 'ids' => 'nullable|array|max:50', 'ids.*' => 'integer'] );
        $branch = (int) session( 'selected_branch_id' );
        app( MemberImportService::class )->authorizeBranch( $branch );
        $query = Product::query();
        if ( $request->filled( 'ids' ) ) {
            $query->whereIn( 'id', $request->input( 'ids' ) );
        } else {
            foreach ( preg_split( '/\s+/u', trim( $request->string( 'q' ) ) ) as $term ) {
                if ( $term === '' ) {
                    continue;
                }
                $query->where( function ( $q ) use ( $term, $branch ): void {
                    $q->where( 'name', 'like', '%' . $term . '%' )->orWhere( 'sku', 'like', '%' . $term . '%' )->orWhere( 'barcode', $term )
                        ->orWhereIn( 'id', ProductUnitQuantity::where( 'branch_id', $branch )->where( 'barcode', $term )->select( 'product_id' ) );
                } );
            }
        }

        return $query->orderBy( 'name' )->limit( 50 )->get()->map( function ( $product ) use ( $branch ): array {
            $units = ProductUnitQuantity::with( 'unit' )->where( 'product_id', $product->id )->where( 'branch_id', $branch )->get();

            return ['id' => $product->id, 'name' => $product->name, 'sku' => $product->sku,
                'units' => $units->map( fn ( $u ) => ['unit_id' => $u->unit_id, 'name' => $u->unit?->name ?? 'หน่วยถูกลบ', 'barcode' => $u->barcode, 'price' => $u->sale_price] )->values()->all()];
        } )->all();
    }
}
