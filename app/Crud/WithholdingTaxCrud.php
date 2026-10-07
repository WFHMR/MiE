<?php

namespace App\Crud;

use App\Casts\CurrencyCast;
use App\Classes\CrudForm;
use App\Classes\CrudTable;
use App\Classes\FormInput;
use App\Classes\Hook;
use App\Exceptions\NotAllowedException;
use App\Models\User;
use App\Models\WithholdingTax;
use App\Services\CrudEntry;
use App\Services\CrudService;
use App\Services\Helper;
use App\Services\ThaiVatService;
use App\Services\ThaiWithholdingTaxService;
use Illuminate\Http\Request;

/**
 * Thai withholding tax records (ภาษีหัก ณ ที่จ่าย).
 *
 * The form asks for the payment including VAT and the rate, then derives the base and the
 * tax on save. That direction is deliberate: a supplier invoice shows the VAT inclusive
 * total, and asking a bookkeeper to work the base out by hand is exactly where the common
 * error — withholding on the gross rather than the net — creeps in.
 */
class WithholdingTaxCrud extends CrudService
{
    /**
     * Define the autoload status
     */
    const AUTOLOAD = true;

    /**
     * Define the identifier
     */
    const IDENTIFIER = 'ns.withholding-tax';

    /**
     * define the base table
     */
    protected $table = 'nexopos_withholding_taxes';

    /**
     * base route name
     */
    protected $mainRoute = 'ns.withholding-tax';

    /**
     * Define namespace
     */
    protected $namespace = 'ns.withholding-tax';

    /**
     * Model Used
     */
    protected $model = WithholdingTax::class;

    protected $slug = 'taxes/withholding';

    /**
     * Adding relation
     */
    public $relations = [
        'join' => [
            [ User::class, 'user' ],
        ],
    ];

    public $pick = [
        'user' => [ 'username' ],
    ];

    /**
     * Cancelling is mapped to the "delete" verb because that is the destructive action the
     * CRUD exposes, but nothing is ever deleted — beforeDelete refuses and the entry is
     * cancelled through its own action instead.
     */
    protected $permissions = [
        'create' => 'nexopos.create.withholding-tax',
        'read' => 'nexopos.read.withholding-tax',
        'update' => 'nexopos.update.withholding-tax',
        'delete' => 'nexopos.delete.withholding-tax',
    ];

    protected $listWhere = [];

    protected $whereIn = [];

    public $fillable = [];

    protected $casts = [
        'base_amount' => CurrencyCast::class,
        'gross_amount' => CurrencyCast::class,
        'tax_amount' => CurrencyCast::class,
    ];

    public function __construct()
    {
        parent::__construct();
    }

    private function withholdingService(): ThaiWithholdingTaxService
    {
        return app()->make( ThaiWithholdingTaxService::class );
    }

    public function getLabels()
    {
        return [
            'list_title' => __( 'ภาษีหัก ณ ที่จ่าย' ),
            'list_description' => __( 'รายการภาษีที่ร้านหักไว้จากการจ่ายค่าบริการ ค่าเช่า ค่าขนส่ง หรือค่าโฆษณา และหนังสือรับรองที่ออกให้ผู้รับเงิน' ),
            'no_entry' => __( 'ยังไม่มีการบันทึกภาษีหัก ณ ที่จ่าย' ),
            'create_new' => __( 'บันทึกการหักภาษี ณ ที่จ่าย' ),
            'create_title' => __( 'บันทึกการหักภาษี ณ ที่จ่าย' ),
            'create_description' => __( 'กรอกยอดที่จ่ายและอัตราที่หัก ระบบจะคำนวณฐานก่อน VAT และภาษีที่หักให้ พร้อมออกเลขที่หนังสือรับรอง' ),
            'edit_title' => __( 'แก้ไขการหักภาษี ณ ที่จ่าย' ),
            'edit_description' => __( 'แก้ได้เฉพาะฉบับที่ยังไม่ได้ยกเลิก และควรแก้ก่อนยื่นแบบ ภ.ง.ด. ของเดือนนั้น' ),
            'back_to_list' => __( 'กลับไปรายการภาษีหัก ณ ที่จ่าย' ),
        ];
    }

    public function isEnabled( $feature ): bool
    {
        return false;
    }

    /**
     * Fields
     *
     * @param  object/null
     * @return array of field
     */
    public function getForm( $entry = null )
    {
        $service = $this->withholdingService();

        return CrudForm::form(
            main: FormInput::text(
                label: __( 'ชื่อผู้รับเงิน' ),
                name: 'payee_name',
                value: $entry->payee_name ?? '',
                validation: 'required',
                description: __( 'ชื่อตามที่จดทะเบียน เพราะชื่อนี้จะถูกพิมพ์บนหนังสือรับรองและใช้ยื่นแบบ ภ.ง.ด.' ),
            ),
            tabs: CrudForm::tabs(
                CrudForm::tab(
                    identifier: 'payee',
                    label: __( 'ผู้รับเงิน' ),
                    fields: CrudForm::fields(
                        FormInput::select(
                            label: __( 'ประเภทผู้รับเงิน' ),
                            name: 'payee_type',
                            options: Helper::kvToJsOptions( $service->payeeTypes() ),
                            value: $entry->payee_type ?? WithholdingTax::PAYEE_JURISTIC,
                            validation: 'required',
                            description: __( 'ตัวเลือกนี้กำหนดว่าจะยื่นในแบบใด บุคคลธรรมดา = ภ.ง.ด.3 นิติบุคคล = ภ.ง.ด.53 ระบบเลือกแบบให้เองจากค่านี้' ),
                        ),
                        FormInput::text(
                            label: __( 'เลขประจำตัวผู้เสียภาษีของผู้รับเงิน' ),
                            name: 'payee_tax_id',
                            value: $entry->payee_tax_id ?? '',
                            validation: 'required',
                            description: __( 'ตัวเลข 13 หลัก ระบบตรวจเลขหลักสุดท้ายให้ ถ้าเลขผิดกรมสรรพากรจะเครดิตภาษีที่หักไว้ให้ผู้รับเงินไม่ได้ และผู้รับเงินจะถูกเก็บภาษีซ้ำ' ),
                        ),
                        FormInput::text(
                            label: __( 'รหัสสาขาของผู้รับเงิน' ),
                            name: 'payee_branch_code',
                            value: $entry->payee_branch_code ?? '00000',
                            validation: 'nullable',
                            description: __( 'ตัวเลข 5 หลัก สำนักงานใหญ่ใช้ 00000' ),
                        ),
                        FormInput::textarea(
                            label: __( 'ที่อยู่ผู้รับเงิน' ),
                            name: 'payee_address',
                            value: $entry->payee_address ?? '',
                            description: __( 'ที่อยู่ตามที่จดทะเบียน พิมพ์บนหนังสือรับรอง' ),
                        ),
                    )
                ),
                CrudForm::tab(
                    identifier: 'payment',
                    label: __( 'การจ่ายเงินและการหักภาษี' ),
                    fields: CrudForm::fields(
                        FormInput::date(
                            label: __( 'วันที่จ่ายเงิน' ),
                            name: 'paid_on',
                            value: $entry?->paid_on?->toDateString() ?? ns()->date->getNow()->toDateString(),
                            validation: 'required|date',
                            description: __( 'เดือนที่ต้องยื่นแบบคิดจากวันนี้ ไม่ใช่วันที่บันทึกเข้าระบบ' ),
                        ),
                        FormInput::text(
                            label: __( 'ยอดที่จ่าย (รวม VAT ถ้ามี)' ),
                            name: 'gross_amount',
                            value: $entry->gross_amount ?? '',
                            validation: 'required|numeric|min:0',
                            description: __( 'ยอดตามใบแจ้งหนี้ที่ผู้รับเงินออกให้ ระบบจะถอด VAT ออกให้เองเพื่อหาฐานที่ใช้คำนวณ' ),
                        ),
                        FormInput::select(
                            label: __( 'ผู้รับเงินจดทะเบียน VAT หรือไม่' ),
                            name: 'payee_vat_registered',
                            options: Helper::kvToJsOptions( [
                                'yes' => __( 'จดทะเบียน — ยอดข้างต้นรวม VAT แล้ว' ),
                                'no' => __( 'ไม่จดทะเบียน — ยอดข้างต้นไม่มี VAT' ),
                            ] ),
                            value: $entry !== null && (float) $entry->gross_amount === (float) $entry->base_amount
                                ? 'no'
                                : 'yes',
                            validation: 'required',
                            description: __( 'ถ้าผู้รับเงินไม่ได้จดทะเบียน VAT ยอดที่จ่ายคือฐานอยู่แล้ว ไม่ต้องถอดอะไรออก' ),
                        ),
                        FormInput::select(
                            label: __( 'อัตราที่หัก' ),
                            name: 'rate',
                            options: Helper::kvToJsOptions( $service->rates() ),
                            value: (string) ( $entry?->rate !== null ? (int) $entry->rate : ns()->option->get( 'ns_thai_wht_default_rate', '3' ) ),
                            validation: 'required',
                            description: __( 'เลือกให้ตรงกับประเภทของเงินที่จ่าย อัตราที่ใช้ผิดทำให้หนังสือรับรองใช้อ้างอิงไม่ได้' ),
                        ),
                        FormInput::select(
                            label: __( 'ประเภทเงินได้ตามมาตรา 40' ),
                            name: 'income_type',
                            options: Helper::kvToJsOptions( $service->incomeTypes() ),
                            value: $entry->income_type ?? ns()->option->get( 'ns_thai_wht_default_income_type', '40(8)' ),
                            validation: 'required',
                            description: __( 'เป็นช่องที่ต้องกรอกในแบบ ภ.ง.ด. ทั้งสองแบบ' ),
                        ),
                        FormInput::text(
                            label: __( 'รายละเอียดการจ่าย' ),
                            name: 'income_description',
                            value: $entry->income_description ?? '',
                            description: __( 'เช่น "ค่าเช่าอาคาร เดือนกันยายน 2569" หรือ "ค่าขนส่งสินค้า บิลเลขที่ 1234"' ),
                        ),
                    )
                )
            )
        );
    }

    /**
     * Derives the base and the tax before the record is written.
     *
     * Done here rather than in the browser so the stored figures cannot disagree with the
     * rate: a client side calculation can be edited in the payload, and the base is what the
     * monthly return is built from.
     */
    public function filterPostInputs( $inputs )
    {
        return $this->deriveAmounts( $inputs );
    }

    public function filterPutInputs( $inputs, WithholdingTax $entry )
    {
        if ( $entry->isCancelled() ) {
            throw new NotAllowedException( __( 'แก้ไขหนังสือรับรองที่ยกเลิกแล้วไม่ได้ ต้องออกฉบับใหม่แทน' ) );
        }

        $inputs = $this->deriveAmounts( $inputs );

        /**
         * The number stays with the record for life. Re-deriving it on an edit would either
         * consume a second number or renumber a document already handed to the payee.
         */
        unset( $inputs[ 'number' ], $inputs[ 'sequence_scope' ], $inputs[ 'sequence_number' ] );

        return $inputs;
    }

    /**
     * @param  array<string, mixed> $inputs
     * @return array<string, mixed>
     */
    private function deriveAmounts( array $inputs ): array
    {
        $service = $this->withholdingService();

        $payeeType = $inputs[ 'payee_type' ] ?? WithholdingTax::PAYEE_JURISTIC;
        $rate = (float) ( $inputs[ 'rate' ] ?? 0 );
        $gross = (float) ( $inputs[ 'gross_amount' ] ?? 0 );
        $vatRegistered = ( $inputs[ 'payee_vat_registered' ] ?? 'yes' ) === 'yes';
        $paidOn = $inputs[ 'paid_on' ] ?? null;

        if ( ! $service->isValidRate( $rate ) ) {
            throw new NotAllowedException( __( 'อัตราภาษีหัก ณ ที่จ่ายต้องเป็น 1%, 2%, 3% หรือ 5% เท่านั้น' ) );
        }

        if ( ! app()->make( ThaiVatService::class )->isValidTaxpayerId( $inputs[ 'payee_tax_id' ] ?? null ) ) {
            throw new NotAllowedException( __( 'เลขประจำตัวผู้เสียภาษีของผู้รับเงินไม่ถูกต้อง ต้องเป็นตัวเลข 13 หลักและผ่านการตรวจเลขหลักสุดท้าย' ) );
        }

        $breakdown = $service->breakdown(
            grossAmount: $gross,
            rate: $rate,
            payeeType: $payeeType,
            vatRegistered: $vatRegistered,
            date: $paidOn,
        );

        if ( $breakdown[ 'base' ] <= 0 ) {
            throw new NotAllowedException( __( 'ยอดเงินก่อนภาษีมูลค่าเพิ่มต้องมากกว่าศูนย์' ) );
        }

        $inputs[ 'base_amount' ] = $breakdown[ 'base' ];
        $inputs[ 'tax_amount' ] = $breakdown[ 'tax' ];
        $inputs[ 'form' ] = $breakdown[ 'form' ];
        $inputs[ 'status' ] = $inputs[ 'status' ] ?? WithholdingTax::STATUS_ISSUED;

        /**
         * A blank branch code means head office, and a numeric cast would have eaten the
         * leading zeros of a real one.
         */
        $branchCode = preg_replace( '/\D/', '', (string) ( $inputs[ 'payee_branch_code' ] ?? '' ) );
        $inputs[ 'payee_branch_code' ] = $branchCode === '' ? '00000' : str_pad( $branchCode, 5, '0', STR_PAD_LEFT );
        $inputs[ 'payee_tax_id' ] = str_pad( preg_replace( '/\D/', '', (string) $inputs[ 'payee_tax_id' ] ), 13, '0', STR_PAD_LEFT );

        /**
         * Not a column. It only tells deriveAmounts() whether to strip VAT off the gross.
         */
        unset( $inputs[ 'payee_vat_registered' ] );

        return $inputs;
    }

    public function beforePost( $inputs )
    {
        if ( ! $this->withholdingService()->isWithholdingAgent() ) {
            throw new NotAllowedException(
                __( 'ยังบันทึกการหักภาษี ณ ที่จ่ายไม่ได้ ต้องตั้งสถานะร้านเป็นผู้มีหน้าที่หักภาษี ณ ที่จ่าย และกรอกเลขประจำตัวผู้เสียภาษีให้ถูกต้องก่อน — ตั้งค่า → ภาษีไทย → ภาษีหัก ณ ที่จ่าย' )
            );
        }

        return $inputs;
    }

    /**
     * Allocates the certificate number after the row exists.
     *
     * Done here rather than in filterPostInputs because the number must not be consumed by
     * a save that then fails validation — a gap in the series is what an auditor asks about.
     */
    public function afterPost( array $inputs, WithholdingTax $entry ): array
    {
        $service = $this->withholdingService();
        $scope = $service->currentScope( $entry->paid_on );

        $entry->sequence_scope = $scope;
        $entry->sequence_number = $service->allocateNumberFor( $scope );
        $entry->number = $service->formatNumber( $scope, $entry->sequence_number );
        $entry->save();

        return $inputs;
    }

    public function get( $param )
    {
        switch ( $param ) {
            case 'model': return $this->model;
                break;
        }
    }

    public function hook( $query ): void
    {
        $query->orderBy( 'id', 'desc' );
    }

    /**
     * A certificate handed to a payee, and reported on a filed return, cannot be deleted.
     * It is cancelled, which keeps the number consumed and the audit trail intact.
     */
    public function beforeDelete( $namespace, $id, $model )
    {
        if ( $namespace === 'ns.withholding-tax' ) {
            throw new NotAllowedException(
                __( 'ลบหนังสือรับรองการหักภาษี ณ ที่จ่ายไม่ได้ ให้ใช้คำสั่ง "ยกเลิก" แทน เพื่อให้เลขที่เอกสารไม่หายไปจากชุดและยังตรวจสอบย้อนหลังได้' )
            );
        }
    }

    public function getColumns(): array
    {
        return CrudTable::columns(
            CrudTable::column( __( 'เลขที่' ), 'number' ),
            CrudTable::column( __( 'วันที่จ่าย' ), 'paid_on' ),
            CrudTable::column( __( 'ผู้รับเงิน' ), 'payee_name' ),
            CrudTable::column( __( 'เลขผู้เสียภาษี' ), 'payee_tax_id' ),
            CrudTable::column( __( 'แบบที่ยื่น' ), 'form' ),
            CrudTable::column( __( 'ฐานก่อน VAT' ), 'base_amount' ),
            CrudTable::column( __( 'อัตรา' ), 'rate' ),
            CrudTable::column( __( 'ภาษีที่หัก' ), 'tax_amount' ),
            CrudTable::column( __( 'สถานะ' ), 'status' ),
            CrudTable::column( __( 'ผู้บันทึก' ), 'user_username' ),
        );
    }

    public function setActions( CrudEntry $entry ): CrudEntry
    {
        $entry->form = $entry->form === WithholdingTax::FORM_PND3 ? __( 'ภ.ง.ด.3' ) : __( 'ภ.ง.ด.53' );
        $entry->status = $entry->status === WithholdingTax::STATUS_CANCELLED ? __( 'ยกเลิกแล้ว' ) : __( 'ออกแล้ว' );
        $entry->rate = rtrim( rtrim( number_format( (float) $entry->rate, 2, '.', '' ), '0' ), '.' ) . '%';

        $entry->action(
            identifier: 'certificate',
            label: __( 'พิมพ์หนังสือรับรอง' ),
            url: ns()->url( '/dashboard/taxes/withholding/certificate/' . $entry->id ),
            type: 'GOTO',
        );

        $entry->action(
            identifier: 'edit',
            label: __( 'แก้ไข' ),
            url: ns()->url( '/dashboard/taxes/withholding/edit/' . $entry->id ),
            type: 'GOTO',
        );

        $entry->action(
            identifier: 'cancel',
            label: __( 'ยกเลิก' ),
            url: ns()->url( '/api/withholding-tax/' . $entry->id . '/cancel' ),

            /**
             * POST. A cancelled certificate keeps its number and its place in the sequence the revenue
             * department reads, so cancelling one is a permanent entry and must not be reachable by
             * following a link.
             */
            type: 'POST',
            confirm: [
                'message' => __( 'ยกเลิกหนังสือรับรองฉบับนี้? เลขที่เอกสารจะไม่ถูกนำกลับมาใช้ และยอดจะถูกตัดออกจากแบบ ภ.ง.ด. ของเดือนนั้น' ),
            ],
        );

        return $entry;
    }

    /**
     * There is no bulk action. Cancelling changes what the shop reports and owes for a
     * month, and doing it to a checkbox selection is not a thing that should be one click
     * away.
     */
    public function bulkAction( Request $request )
    {
        return Hook::filter( $this->namespace . '-catch-action', false, $request );
    }

    public function getBulkActions(): array
    {
        return Hook::filter( $this->namespace . '-bulk', [] );
    }

    public function getLinks(): array
    {
        return CrudTable::links(
            list: ns()->url( 'dashboard/taxes/withholding' ),
            create: ns()->url( 'dashboard/taxes/withholding/create' ),
            edit: ns()->url( 'dashboard/taxes/withholding/edit/{id}' ),
            post: ns()->url( 'api/crud/ns.withholding-tax' ),
            put: ns()->url( 'api/crud/ns.withholding-tax/{id}' ),
        );
    }

    public function getExports()
    {
        return [];
    }
}
