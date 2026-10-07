<?php

/**
 * RGBF Controller
 *
 * @since  1.0
 **/

namespace App\Http\Controllers\Dashboard;

use App\Crud\TaxCrud;
use App\Crud\TaxesGroupCrud;
use App\Crud\WithholdingTaxCrud;
use App\Exceptions\NotFoundException;
use App\Http\Controllers\DashboardController;
use App\Models\Tax;
use App\Models\TaxGroup;
use App\Models\WithholdingTax;
use App\Services\DateService;
use App\Services\TaxService;
use App\Services\ThaiVatService;
use App\Services\ThaiWithholdingTaxService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

class TaxesController extends DashboardController
{
    public function __construct(
        protected TaxService $taxService,
        protected DateService $dateService
    ) {
        // ...
    }

    public function get( $id = null )
    {
        if ( ! empty( $id ) ) {
            $productTax = Tax::find( $id );
            if ( ! $productTax instanceof Tax ) {
                throw new Exception( __( 'Unable to find the requested product tax using the provided id' ) );
            }

            return $productTax;
        }

        return Tax::get();
    }

    /**
     * try to delete a category using the provided
     * id
     *
     * @param number id
     * @return json
     */
    public function delete( $id )
    {
        return $this->taxService->delete( $id );
    }

    /**
     * Internal feature to get product
     * tax or to fail
     *
     * @param int tax id
     * @return Tax | null
     */
    private function getTaxOrFail( $id )
    {
        $productTax = Tax::find( $id );
        if ( ! $productTax instanceof Tax ) {
            throw new Exception( __( 'Unable to find the requested product tax using the provided identifier.' ) );
        }

        return $productTax;
    }

    /**
     * create a category using the provided
     * form data
     *
     * @param Request
     * @return json
     */
    public function post( Request $request ) // must be a specific form request with a validation
    {
        /**
         * @todo add a prior validation
         * on this element and check the permisions.
         * The validation should check whether the type is "grouped" or "simple"
         */
        $fields = $request->only( [
            'name', 'rate', 'description', 'type', 'parent_id',
        ] );

        $this->taxService->create( $fields );

        return [
            'status' => 'success',
            'message' => __( 'The product tax has been created.' ),
        ];
    }

    /**
     * edit existing category using
     * the provided ID
     *
     * @param int category id
     * @return json
     */
    public function put( $id, Request $request ) // must use a specific request which include a validation
    {
        $fields = $request->only( [
            'name', 'rate', 'description', 'type', 'parent_id',
        ] );

        $tax = $this->taxService->update( $id, $fields );

        /**
         * @todo dispatch en event
         * mentionning the edited tax
         */
        return [
            'status' => 'success',
            'message' => __( 'The product tax has been updated' ),
            'data' => compact( 'tax' ),
        ];
    }

    /**
     * Get sub taxes
     *
     * @param int tax id
     * @return json
     */
    public function getTaxGroup( $taxId = null )
    {
        if ( $taxId === null ) {
            return TaxGroup::with( 'taxes' )->get();
        }

        $taxGroup = TaxGroup::find( $taxId );

        if ( ! $taxGroup instanceof TaxGroup ) {
            throw new NotFoundException( sprintf(
                __( 'Unable to retrieve the requested tax group using the provided identifier "%s".' ),
                $taxId
            ) );
        }

        $taxGroup->load( 'taxes' );

        $taxArray = $taxGroup->toArray();

        $taxArray[ 'taxes' ] = collect( $taxGroup[ 'taxes' ] )->map( function ( $tax ) {
            $tax[ 'tax_id' ] = $tax[ 'id' ];
            unset( $tax[ 'id' ] );

            return $tax;
        } );

        return $taxArray;
    }

    /**
     * List all available taxes
     *
     * @return View
     */
    public function listTaxes()
    {
        return TaxCrud::table();
    }

    /**
     * Create new taxes
     *
     * @return View
     */
    public function createTax()
    {
        return TaxCrud::form();
    }

    /**
     * Edit existing taxes
     *
     * @return View
     */
    public function editTax( Tax $tax )
    {
        return TaxCrud::form( $tax );
    }

    /**
     * Thai withholding tax records.
     */
    public function listWithholdingTaxes()
    {
        return WithholdingTaxCrud::table();
    }

    public function createWithholdingTax()
    {
        return WithholdingTaxCrud::form();
    }

    public function editWithholdingTax( WithholdingTax $withholdingTax )
    {
        return WithholdingTaxCrud::form( $withholdingTax );
    }

    /**
     * The certificate the payee files with their own return.
     *
     * Everything printed comes from the record's own snapshot. The payer block is the only
     * live part, and it is read once here rather than in the view so the view stays a
     * template.
     */
    public function showWithholdingCertificate( WithholdingTax $withholdingTax )
    {
        ns()->restrict( 'nexopos.read.withholding-tax' );

        $vatService = app()->make( ThaiVatService::class );
        $profile = $vatService->taxpayerProfile();

        return View::make( 'pages.dashboard.taxes.withholding.certificate', [
            'title' => sprintf( '%s — %s', __( 'หนังสือรับรองการหักภาษี ณ ที่จ่าย' ), $withholdingTax->number ),
            'description' => sprintf( __( 'จ่ายให้ %s เมื่อ %s' ), $withholdingTax->payee_name, $withholdingTax->paid_on->toDateString() ),
            'record' => $withholdingTax,
            'payer' => [
                'name' => $profile[ 'name' ] !== '' ? $profile[ 'name' ] : ns()->option->get( 'ns_store_name', '' ),
                'address' => $profile[ 'address' ],
                'tax_id' => $profile[ 'tax_id' ],
                'branch_code' => $profile[ 'branch_code' ],
            ],
        ] );
    }

    /**
     * Cancels a certificate. The number stays consumed and the record is kept.
     */
    public function cancelWithholdingTax( WithholdingTax $withholdingTax, Request $request )
    {
        ns()->restrict( 'nexopos.delete.withholding-tax' );

        $reason = (string) $request->input( 'reason', __( 'ยกเลิกโดยผู้ใช้' ) );

        $record = app()->make( ThaiWithholdingTaxService::class )->cancel( $withholdingTax, $reason );

        return [
            'status' => 'success',
            'message' => sprintf( __( 'ยกเลิกหนังสือรับรองเลขที่ %s แล้ว ยอดนี้จะถูกตัดออกจากแบบ ภ.ง.ด. ของเดือนที่จ่าย' ), $record->number ),
            'data' => compact( 'record' ),
        ];
    }

    /**
     * Create tax groups
     *
     * @return View
     */
    public function taxesGroups()
    {
        return TaxesGroupCrud::table();
    }

    /**
     * Create tax groups
     *
     * @return View
     */
    public function createTaxGroups()
    {
        return TaxesGroupCrud::form();
    }

    /**
     * Edit tax groups
     *
     * @return View
     */
    public function editTaxGroup( TaxGroup $group )
    {
        return TaxesGroupCrud::form( $group );
    }
}
