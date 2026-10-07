<?php

namespace App\Models;

use App\Casts\FloatConvertCasting;
use App\Events\ProductUnitQuantityAfterCreatedEvent;
use App\Events\ProductUnitQuantityAfterUpdatedEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int     $id
 * @property int     $product_id
 * @property string  $type
 * @property string  $preview_url
 * @property string  $expiration_date
 * @property int     $unit_id
 * @property string  $barcode
 * @property string  $scale_plu
 * @property bool    $is_weighable
 * @property float   $quantity
 * @property float   $low_quantity
 * @property bool    $stock_alert_enabled
 * @property float   $sale_price
 * @property float   $sale_price_edit
 * @property float   $sale_price_net
 * @property float   $sale_price_gross
 * @property float   $sale_price_tax
 * @property float   $wholesale_price
 * @property float   $wholesale_price_edit
 * @property float   $wholesale_price_gross
 * @property float   $wholesale_price_net
 * @property float   $wholesale_price_tax
 * @property float   $custom_price
 * @property float   $custom_price_edit
 * @property float   $custom_price_gross
 * @property float   $cogs
 * @property float   $custom_price_net
 * @property Product $product
 * @property Unit    $unit
 */
class ProductUnitQuantity extends NsModel
{
    /**
     * BroadcastsEvents was removed here.
     *
     * The trait was in use without a broadcastOn() override, so Laravel's default
     * applied: every save emitted a model event on a per-row private channel named
     * App.Models.ProductUnitQuantity.{id}. Nothing subscribed to it — there is no such
     * channel in routes/channels.php, so a client could never even be authorised — and
     * nothing in resources/ts listened for it.
     *
     * Beyond being dead weight it was a hazard: the broadcast is emitted from
     * PendingBroadcast::__destruct, so with a real broadcast driver and an unreachable
     * websocket server it threw from a destructor on every single stock write, where it
     * cannot be caught at the call site.
     *
     * Stock changes are now announced deliberately by ProductStockUpdatedEvent, which
     * has a channel the POS actually subscribes to and a dispatch that survives Reverb
     * being down.
     */
    use HasFactory;

    protected $table = 'nexopos_' . 'products_unit_quantities';

    protected $dispatchesEvents = [
        'created' => ProductUnitQuantityAfterCreatedEvent::class,
        'updated' => ProductUnitQuantityAfterUpdatedEvent::class,
    ];

    /**
     * We want to enforce the property type
     * might be useful to solve a common bug when the
     * database doesn't return the right type.
     */
    protected $casts = [
        'sale_price' => FloatConvertCasting::class,
        'sale_price_edit' => FloatConvertCasting::class,
        'sale_price_net' => FloatConvertCasting::class,
        'sale_price_gross' => FloatConvertCasting::class,
        'sale_price_tax' => FloatConvertCasting::class,
        'wholesale_price' => FloatConvertCasting::class,
        'wholesale_price_edit' => FloatConvertCasting::class,
        'wholesale_price_gross' => FloatConvertCasting::class,
        'wholesale_price_net' => FloatConvertCasting::class,
        'wholesale_price_tax' => FloatConvertCasting::class,
        'custom_price' => FloatConvertCasting::class,
        'custom_price_edit' => FloatConvertCasting::class,
        'custom_price_gross' => FloatConvertCasting::class,
        'custom_price_net' => FloatConvertCasting::class,
        'quantity' => 'float',
        'low_quantity' => 'float',
    ];

    /**
     * Fetch products unique a barcode filter
     *
     * @param  QueryBuilder $query
     * @param  string       $reference
     * @return QueryBuilder
     **/
    public function scopeBarcode( $query, $reference )
    {
        return $query->where( 'barcode', $reference );
    }

    public function scopeHidden( $query )
    {
        return $query->where( 'visible', false );
    }

    public function scopeVisible( $query )
    {
        return $query->where( 'visible', true );
    }

    public function unit()
    {
        return $this->hasOne( Unit::class, 'id', 'unit_id' );
    }

    public function history()
    {
        return $this->hasMany( ProductHistoryCombined::class, 'product_id', 'product_id' );
    }

    public function taxes()
    {
        return $this->hasMany( ProductTax::class, 'unit_quantity_id' );
    }

    public function scopeWithUnit( Builder $query, $id )
    {
        return $query->where( 'unit_id', $id );
    }

    public function product()
    {
        return $this->belongsTo( Product::class, 'product_id', 'id' );
    }

    public function branch()
    {
        return $this->belongsTo( Branch::class, 'branch_id', 'id' );
    }

    public function scopeWithProduct( Builder $query, $id )
    {
        return $query->where( 'product_id', $id );
    }

    public function scopeWithBranch( Builder $query, $id )
    {
        return $query->where( 'branch_id', $id );
    }

    public function scopeStockAlertEnabled( Builder $query )
    {
        return $query->where( 'stock_alert_enabled', true );
    }
}
