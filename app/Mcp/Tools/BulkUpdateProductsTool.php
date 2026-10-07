<?php

namespace App\Mcp\Tools;

use App\Models\Product;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class BulkUpdateProductsTool extends Tool
{
    /**
     * Upper bound on how many products a single call may touch. This keeps an
     * agent from rewriting the whole catalogue through one accidental request.
     */
    public const MAX_IDS = 200;

    public string $name = 'bulk_update_products';

    public string $description = 'Applies the same field updates to a list of product IDs. Limited to ' . self::MAX_IDS . ' products per call. Does not touch pricing or stock, which belong to product unit quantities.';

    public function schema( JsonSchema $schema ): array
    {
        return [
            'ids' => $schema->array()
                ->items( $schema->integer() )
                ->description( 'List of product IDs to update. Maximum ' . self::MAX_IDS . ' per call.' )
                ->required(),
            'status' => $schema->string()
                ->description( 'The status to set on all provided products (e.g. available, unavailable).' )
                ->nullable(),
            'category_id' => $schema->integer()
                ->description( 'Move all provided products to this category ID.' )
                ->nullable(),
        ];
    }

    public function handle( Request $request ): Response
    {
        $ids = $request->get( 'ids' );

        if ( empty( $ids ) || ! is_array( $ids ) ) {
            return Response::error( 'The ids parameter must be a non-empty array of product IDs.' );
        }

        $ids = array_values( array_unique( array_filter( $ids, 'is_numeric' ) ) );

        if ( empty( $ids ) ) {
            return Response::error( 'The ids parameter did not contain any numeric product ID.' );
        }

        if ( count( $ids ) > self::MAX_IDS ) {
            return Response::error( sprintf(
                'Too many products requested: %d. Split the request into batches of %d or fewer.',
                count( $ids ),
                self::MAX_IDS
            ) );
        }

        $updateData = [];

        foreach ( [ 'status', 'category_id' ] as $field ) {
            if ( $request->get( $field ) !== null ) {
                $updateData[ $field ] = $request->get( $field );
            }
        }

        if ( empty( $updateData ) ) {
            return Response::error( 'No valid update fields provided. Please provide status or category_id.' );
        }

        /**
         * Saving each model rather than issuing a single query update keeps casts
         * and mutators in play, matching how update_product behaves.
         */
        $products = Product::whereIn( 'id', $ids )->get();
        $updated = [];

        foreach ( $products as $product ) {
            foreach ( $updateData as $field => $value ) {
                $product->$field = $value;
            }

            $product->save();
            $updated[] = $product->id;
        }

        $missing = array_values( array_diff( array_map( 'intval', $ids ), $updated ) );

        return Response::json( [
            'updated_count' => count( $updated ),
            'updated_ids' => $updated,
            'not_found_ids' => $missing,
            'applied_fields' => array_keys( $updateData ),
            'message' => sprintf( 'Successfully updated %d product(s).', count( $updated ) ),
        ] );
    }
}
