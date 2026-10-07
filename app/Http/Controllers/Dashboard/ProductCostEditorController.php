<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\DashboardController;
use App\Models\ProductCategory;
use App\Services\ProductCostEditorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * แก้ต้นทุนสินค้าหลายรายการ.
 *
 * The product form edits one product's cost at a time, which is unusable against the 419 unit rows
 * on this shop that carry no cost — 394 of them holding goods that are being sold at a reported loss,
 * because a margin computed against a cost of zero reads as the whole sale price being profit, and a
 * sale below the real cost cannot be seen at all.
 */
class ProductCostEditorController extends DashboardController
{
    /**
     * The service arrives by method injection rather than through a constructor: DashboardController
     * takes a DateService of its own, and declaring a constructor here means restating and forwarding
     * a dependency this class has no use for.
     */
    public function index( Request $request, ProductCostEditorService $costEditor ): View
    {
        /**
         * Changing cost changes reported profit, so it is held to the same permission as editing the
         * product itself. Checked on the route and not only in the menu, because the URL is what
         * somebody bookmarks.
         */
        ns()->restrict( 'nexopos.update.products' );

        $filters = $this->filters( $request );

        return view( 'pages.dashboard.products.cost-editor', [
            'title' => __( 'แก้ต้นทุนสินค้าหลายรายการ' ),
            'description' => __( 'กรอกต้นทุนของสินค้าหลายรายการในหน้าเดียว แยกตามสาขา' ),
            'rows' => $costEditor->rows( $filters ),
            'summary' => $costEditor->summary(),
            'branchName' => $costEditor->branchName(),
            'categories' => ProductCategory::orderBy( 'name' )->get( [ 'id', 'name' ] ),
            'filters' => $filters,
        ] );
    }

    public function save( Request $request, ProductCostEditorService $costEditor ): RedirectResponse
    {
        ns()->restrict( 'nexopos.update.products' );

        $request->validate( [
            'costs' => 'nullable|array',
            'costs.*' => 'nullable|string|max:24',
            'spread_to_other_units' => 'nullable|boolean',
        ] );

        $result = $costEditor->apply(
            costs: $request->input( 'costs', [] ),
            spreadToOtherUnits: $request->boolean( 'spread_to_other_units' ),
        );

        $message = sprintf(
            __( 'บันทึกต้นทุนแล้ว %d รายการ · คำนวณหน่วยอื่นให้อีก %d รายการ · ไม่เปลี่ยนแปลง %d รายการ' ),
            $result[ 'updated' ],
            $result[ 'spread' ],
            $result[ 'unchanged' ]
        );

        if ( $result[ 'refused' ] > 0 || $result[ 'invalid' ] > 0 ) {
            $message .= sprintf(
                __( ' · ข้าม %d รายการ · กรอกไม่ถูกต้อง %d รายการ' ),
                $result[ 'refused' ],
                $result[ 'invalid' ]
            );
        }

        /**
         * Returned to the same filtered page the save was made from, so somebody working through a
         * long list is not thrown back to the first page after every submission.
         */
        return redirect()
            ->to( $request->input( 'return_to' ) ?: route( 'ns.products-cost-editor' ) )
            ->with( 'success', $message )
            ->with( 'cost_editor_refusals', $result[ 'refusals' ] );
    }

    /**
     * @return array{search: string|null, category_id: int|null, only_missing: bool, only_in_stock: bool, per_page: int}
     */
    private function filters( Request $request ): array
    {
        $validated = $request->validate( [
            'search' => 'nullable|string|max:250',
            'category_id' => 'nullable|integer|min:1',
            'only_missing' => 'nullable|boolean',
            'only_in_stock' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:10|max:200',
        ] );

        return [
            'search' => $validated[ 'search' ] ?? null,
            'category_id' => isset( $validated[ 'category_id' ] ) ? (int) $validated[ 'category_id' ] : null,

            /**
             * Both filters default to on, and only when the screen is opened clean.
             *
             * The rows with no cost and goods on the shelf are the ones costing the shop money
             * today, so that is what the screen opens on. Once any filter has been submitted the
             * checkboxes are read as sent, otherwise unticking one could never take effect — an
             * unticked checkbox is simply absent from the query string.
             */
            'only_missing' => $request->has( 'filtered' ) ? $request->boolean( 'only_missing' ) : true,
            'only_in_stock' => $request->has( 'filtered' ) ? $request->boolean( 'only_in_stock' ) : true,
            'per_page' => (int) ( $validated[ 'per_page' ] ?? 50 ),
        ];
    }
}
