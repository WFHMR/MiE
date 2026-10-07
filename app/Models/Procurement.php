<?php

namespace App\Models;

use App\Events\ProcurementAfterCreateEvent;
use App\Events\ProcurementAfterDeleteEvent;
use App\Events\ProcurementAfterUpdateEvent;
use App\Events\ProcurementBeforeDeleteEvent;
use App\Events\ProcurementBeforeUpdateEvent;
use App\Exceptions\NotAllowedException;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int $id
 * @property mixed $name
 * @property int $provider_id
 * @property float $value
 * @property float $cost
 * @property float $tax_value
 * @property mixed $invoice_reference
 * @property bool $automatic_approval
 * @property Carbon $delivery_time
 * @property Carbon $invoice_date
 * @property string $purchase_order_number the shop's own purchase order reference
 * @property string $delivery_note_number the supplier's delivery note, what receiving checks against
 * @property string $delivery_note_date
 * @property string $billing_note_number the supplier's billing note, what accounts pays against
 * @property string $billing_note_date
 * @property string $contact_person
 * @property int $credit_days
 * @property string $due_date
 * @property mixed $payment_status
 * @property mixed $delivery_status
 * @property int $total_items
 * @property string $description
 * @property int $author_id
 * @property mixed $uuid
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Procurement extends NsModel
{
    use HasFactory;

    public function getPayableTotalAttribute(): float
    {
        return max(0, round((float) $this->cost - (float) $this->purchase_return_credit, 5));
    }

    protected $table = 'nexopos_'.'procurements';

    /**
     * this status mention when the procurement
     * has been received
     *
     * @param string
     */
    const DELIVERED = 'delivered';

    /**
     * this status state when the procurement has
     * moved from the "draft" status to pending. Which
     * means it's ready to be processed or it's being processed.
     *
     * @param string
     */
    const PENDING = 'pending';

    /**
     * The procurement is in draft mode. Means it's not yet ready
     * to be processed or send to the provider
     *
     * @param string
     */
    const DRAFT = 'draft';

    /**
     * The procurement has affected the actual products stock.
     * Here the procurement has been delivered and integrated to the stock.
     *
     * @param string
     */
    const STOCKED = 'stocked';

    /**
     * The procurement hasn't been paid.
     */
    const PAYMENT_UNPAID = 'unpaid';

    /**
     * The procurement has been paid.
     */
    const PAYMENT_PAID = 'paid';

    /**
     * Every other pair here maps the "ing" hook to a Before event and the "ed" hook to an After one.
     * "creating" used to map to ProcurementAfterCreateEvent as well, which ran the whole after-create
     * pipeline — refresh, provider summary, stock handling, transaction — against a row that did not
     * exist yet, and then ran it a second time once it did. The first pass assigned the model an id,
     * so the insert that followed collided with it and the save failed outright.
     *
     * ProcurementService::create() never showed the fault because it wraps the save in
     * withoutEvents() and raises both events itself in the right order. Anything saving a Procurement
     * outside that method hit it.
     *
     * ProcurementBeforeCreateEvent is not usable here: it takes the submitted payload as a second
     * constructor argument, which the model event map cannot supply. The service raises it instead.
     */
    protected $dispatchesEvents = [
        'created' => ProcurementAfterCreateEvent::class,
        'deleting' => ProcurementBeforeDeleteEvent::class,
        'updating' => ProcurementBeforeUpdateEvent::class,
        'updated' => ProcurementAfterUpdateEvent::class,
        'deleted' => ProcurementAfterDeleteEvent::class,
    ];

    protected static function booted(): void
    {
        static::deleting(function (self $receipt): void {
            if ($receipt->purchase_order_id || $receipt->receipt_source) {
                throw new NotAllowedException('เอกสารรับเข้าจากใบสั่งซื้อยืนยันแล้ว ลบไม่ได้ กรุณาปรับสต๊อกโดยมีหลักฐานอ้างอิง');
            }
        });
        static::saving(function (self $receipt): void {
            if (($receipt->getOriginal('purchase_order_id') || $receipt->getOriginal('receipt_source')) && $receipt->isDirty(['branch_id', 'purchase_order_id', 'provider_id', 'delivery_status', 'receipt_source', 'receipt_request_key'])) {
                throw new NotAllowedException('ไม่สามารถเปลี่ยนสาขาหรือสถานะรับของเอกสารที่ยืนยันแล้ว');
            }
        });
    }

    public function transactionHistories()
    {
        return $this->hasMany(TransactionHistory::class, 'procurement_id');
    }

    public function products()
    {
        return $this->hasMany(ProcurementProduct::class, 'procurement_id');
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function scopePending($query)
    {
        return $query->where('delivery_status', self::PENDING);
    }

    public function scopeAutoApproval($query)
    {
        return $query->where('automatic_approval', true);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
