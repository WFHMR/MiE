<?php

namespace App\Services;

use App\Exceptions\NotAllowedException;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductUnitQuantity;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CouponPricingService
{
    public function validateDefinition( array $inputs ): array
    {
        $inputs['bundle_items'] = is_string( $inputs['bundle_items'] ?? null )
            ? json_decode( $inputs['bundle_items'], true ) : ( $inputs['bundle_items'] ?? [] );
        $bundle = ( $inputs['type'] ?? '' ) === 'bundle_price';
        Validator::make( $inputs, [
            'type' => ['required', Rule::in( ['percentage_discount', 'flat_discount', 'bundle_price'] )],
            'discount_value' => ['required', 'numeric', 'min:0.01', ( $inputs['type'] ?? '' ) === 'percentage_discount' ? 'max:100' : 'max:1000000'],
            'minimum_cart_value' => 'nullable|numeric|min:0',
            'maximum_cart_value' => 'nullable|numeric|min:0',
            'limit_usage' => 'nullable|integer|min:0|max:1000000',
            'valid_until' => 'nullable|date',
            'valid_hours_start' => 'nullable|date',
            'valid_hours_end' => 'nullable|date',
            'bundle_items' => [$bundle ? 'required' : 'nullable', 'array', 'max:50'],
            'bundle_items.*.product_id' => ['required', 'integer', Rule::exists( 'nexopos_products', 'id' )],
            'bundle_items.*.unit_id' => ['required', 'integer', Rule::exists( 'nexopos_units', 'id' )],
            'bundle_items.*.quantity' => 'required|integer|min:1|max:1000000',
        ] )->validate();
        if ( ( $inputs['maximum_cart_value'] ?? 0 ) > 0 && $inputs['maximum_cart_value'] < ( $inputs['minimum_cart_value'] ?? 0 ) ) {
            throw new NotAllowedException( 'ยอดสูงสุดต้องไม่น้อยกว่ายอดขั้นต่ำ' );
        }
        $seen = [];
        $items = [];
        foreach ( $bundle ? $inputs['bundle_items'] : [] as $item ) {
            $key = $item['product_id'] . ':' . $item['unit_id'];
            if ( isset( $seen[$key] ) || ! ProductUnitQuantity::where( 'product_id', $item['product_id'] )->where( 'unit_id', $item['unit_id'] )->exists() ) {
                throw new NotAllowedException( 'สินค้าหรือหน่วยในชุดไม่ถูกต้อง หรือเลือกซ้ำ กรุณารวมจำนวนในแถวเดียว' );
            }
            $seen[$key] = true;
            $items[] = ['product_id' => (int) $item['product_id'], 'unit_id' => (int) $item['unit_id'], 'quantity' => (int) $item['quantity']];
        }
        $inputs['bundle_items'] = json_encode( $items );

        return $inputs;
    }

    /** Calculate from canonical product totals, never from the submitted coupon value. */
    public function value( Coupon $coupon, array $products, float $subtotal ): float
    {
        if ( $subtotal < (float) $coupon->minimum_cart_value || ( (float) $coupon->maximum_cart_value > 0 && $subtotal > (float) $coupon->maximum_cart_value ) ) {
            throw new NotAllowedException( 'ยอดสินค้าไม่อยู่ในช่วงที่กำหนดของโปร ' . $coupon->name );
        }
        $required = $coupon->products->pluck( 'product_id' )->all();
        if ( $required && ! array_intersect( $required, array_column( $products, 'product_id' ) ) ) {
            throw new NotAllowedException( 'ไม่มีสินค้าที่ร่วมโปร ' . $coupon->name . ' ในบิล' );
        }
        $categories = $coupon->categories->pluck( 'category_id' )->all();
        if ( $categories && ! Product::whereIn( 'id', array_column( $products, 'product_id' ) )->whereIn( 'category_id', $categories )->exists() ) {
            throw new NotAllowedException( 'ไม่มีสินค้าในหมวดที่ร่วมโปร ' . $coupon->name );
        }
        if ( $coupon->type === 'bundle_price' ) {
            $rules = $coupon->bundle_rules;
            if ( ! $rules ) {
                throw new NotAllowedException( 'โปรชุดยังไม่ได้กำหนดสินค้า' );
            }
            $sets = PHP_INT_MAX;
            $buckets = [];
            foreach ( $rules as $item ) {
                $rows = array_values( array_filter( $products, fn ( $p ) => (int) $p['product_id'] === (int) $item['product_id'] && (int) $p['unit_id'] === (int) $item['unit_id'] ) );
                $sets = min( $sets, (int) floor( ( array_sum( array_column( $rows, 'quantity' ) ) + 0.000001 ) / $item['quantity'] ) );
                $buckets[] = [$item, $rows];
            }
            if ( $sets < 1 ) {
                throw new NotAllowedException( 'สินค้า หน่วยขาย หรือจำนวนยังไม่ครบชุดของโปร ' . $coupon->name );
            }
            $eligible = 0;
            foreach ( $buckets as [$item, $rows] ) {
                // Cheapest eligible units are consumed first for deterministic split lines.
                usort( $rows, fn ( $a, $b ) => ( $a['total_price'] / $a['quantity'] ) <=> ( $b['total_price'] / $b['quantity'] ) );
                $needed = $sets * $item['quantity'];
                foreach ( $rows as $row ) {
                    $take = min( $needed, $row['quantity'] );
                    $eligible += $take * $row['total_price'] / $row['quantity'];
                    $needed -= $take;
                }
            }

            return round( max( 0, min( $subtotal, $eligible - $sets * (float) $coupon->discount_value ) ), 2 );
        }
        $value = match ( $coupon->type ) {
            'percentage_discount' => $subtotal * (float) $coupon->discount_value / 100,
            'flat_discount' => (float) $coupon->discount_value,
            default => throw new NotAllowedException( 'ประเภทโปรไม่ถูกต้อง' ),
        };

        return round( max( 0, min( $subtotal, $value ) ), 2 );
    }

    public function prepare( array $fields ): array
    {
        $submitted = $fields['coupons'] ?? [];
        if ( ! $submitted ) {
            return $fields;
        }
        if ( count( $submitted ) !== 1 || (float) ( $fields['discount'] ?? 0 ) > 0 || (float) ( $fields['discount_percentage'] ?? 0 ) > 0 ) {
            throw new NotAllowedException( 'ใช้โปรได้ 1 รายการต่อบิล และไม่ใช้ร่วมกับส่วนลดท้ายบิลหรือแลกแต้ม' );
        }
        $config = $submitted[0];
        $coupon = Coupon::whereKey( $config['coupon_id'] ?? 0 )->lockForUpdate()->firstOrFail();
        app( CustomerService::class )->checkCouponExistence( $config, $fields );
        $products = collect( $fields['products'] )->all();
        $subtotal = round( array_sum( array_column( $products, 'total_price' ) ), 2 );
        $value = $this->value( $coupon, $products, $subtotal );
        if ( $value <= 0 ) {
            throw new NotAllowedException( 'ราคาปกติไม่สูงกว่าราคาโปร กรุณาถอดโปรออกก่อนชำระ' );
        }
        if ( isset( $config['value'] ) && abs( (float) $config['value'] - $value ) > 0.011 ) {
            throw new NotAllowedException( 'ยอดโปรเปลี่ยนแปลง กรุณาถอดแล้วเลือกโปรใหม่ก่อนชำระ' );
        }
        $fields['coupons'] = [array_merge( $config, ['type' => $coupon->type, 'discount_value' => (float) $coupon->discount_value, 'value' => $value] )];
        $fields['total_coupons'] = $value;
        $fields['subtotal'] = $subtotal;

        return $fields;
    }
}
