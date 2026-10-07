<?php

namespace App\Services;

use App\Models\AuditTrail;
use App\Models\Branch;
use App\Models\ProductUnitQuantity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reads and writes purchase cost for many products at once, for one branch.
 *
 * Why this exists: cost is held per product per unit per branch in
 * nexopos_products_unit_quantities.cogs, and the only screen that could edit it handled one product
 * at a time. On this shop 419 of 7,997 unit rows carry no cost at all, 394 of them with goods on the
 * shelf, and every sale of those goods is reported at a loss because the margin is computed against
 * zero. Correcting that one product form at a time is not work anybody will finish.
 *
 * Two rules decide everything below.
 *
 * The first is that a cost belongs to a branch. A figure typed while standing in one branch must not
 * land on another branch's row, so a submitted row whose branch is not the current one is refused
 * and counted rather than written. Rows carrying no branch at all belong to every branch — the ones
 * predating the split, and single store installs — and are editable, but the screen says so plainly,
 * because saving one changes the figure everywhere.
 *
 * The second is that an explicitly typed figure outranks a derived one. Costing the carton implies
 * the cost of the bottle, and leaving the sibling unit at zero is the bug this is meant to end — so
 * the derivation runs by default. But if somebody typed both the carton and the bottle in the same
 * save, they stated two facts, and neither may be overwritten by arithmetic from the other.
 */
class ProductCostEditorService
{
    /**
     * How many rows one submission may carry.
     *
     * The form posts one input per visible row, so this is bounded by the page size. The cap is here
     * so that a hand-built request cannot ask for an unbounded number of writes in one transaction.
     */
    const MAX_ROWS_PER_SAVE = 500;

    /**
     * Costs are held as decimal(18,5). Rounding to the column's own precision keeps the comparison
     * that decides "did this change" honest: without it a stored 12.50000 read back and re-submitted
     * as "12.5" counts as an edit every time and writes a row that did not change.
     */
    const COST_PRECISION = 5;

    public function __construct(
        private ProductService $productService,
        private AuditTrailService $auditTrail,
    ) {}

    /**
     * The branch whose figures are being edited, or null on a single store install.
     */
    public function branchId(): ?int
    {
        return $this->productService->branchIdForCurrentUser();
    }

    public function branchName(): ?string
    {
        $branchId = $this->branchId();

        return $branchId === null ? null : Branch::find( $branchId )?->name;
    }

    /**
     * The rows the screen shows.
     *
     * @param array{search?: string|null, category_id?: int|null, only_missing?: bool, only_in_stock?: bool, per_page?: int} $filters
     * @return LengthAwarePaginator<int, ProductUnitQuantity>
     */
    public function rows( array $filters = [] ): LengthAwarePaginator
    {
        $perPage = (int) ( $filters[ 'per_page' ] ?? 50 );
        $perPage = max( 10, min( 200, $perPage ) );

        return $this->query( $filters )
            ->with( [ 'product', 'unit', 'branch' ] )
            ->paginate( $perPage )
            ->withQueryString();
    }

    /**
     * Counts for the branch being edited, so the screen can say how much is left to do rather than
     * only showing the current page.
     *
     * @return array{rows: int, missing: int, missing_with_stock: int, unassigned_branch: int}
     */
    public function summary(): array
    {
        $base = fn (): Builder => $this->branchScope( ProductUnitQuantity::query() );

        return [
            'rows' => $base()->count(),
            'missing' => $base()->where( fn ( Builder $q ) => $this->missingCostScope( $q ) )->count(),
            'missing_with_stock' => $base()
                ->where( fn ( Builder $q ) => $this->missingCostScope( $q ) )
                ->where( 'nexopos_products_unit_quantities.quantity', '>', 0 )
                ->count(),
            'unassigned_branch' => $base()->whereNull( 'nexopos_products_unit_quantities.branch_id' )->count(),
        ];
    }

    /**
     * Writes the submitted costs.
     *
     * @param  array<int|string, string|null> $costs           Keyed by unit quantity row id. A blank
     *                                                         value is "leave alone", not "set to zero",
     *                                                         because the form posts every visible row.
     * @param  bool                           $spreadToOtherUnits
     * @return array{updated: int, unchanged: int, refused: int, invalid: int, spread: int, refusals: array<int, string>}
     */
    public function apply( array $costs, bool $spreadToOtherUnits = true ): array
    {
        $branchId = $this->branchId();

        $result = [
            'updated' => 0,
            'unchanged' => 0,
            'refused' => 0,
            'invalid' => 0,
            'spread' => 0,
            'refusals' => [],
        ];

        $costs = array_slice( $costs, 0, self::MAX_ROWS_PER_SAVE, true );

        /**
         * @var array<int, array<int, int>> $editedUnitsByProduct
         * @var array<int, ProductUnitQuantity> $spreadSources
         * @var array<int, string> $changes
         */
        $editedUnitsByProduct = [];
        $spreadSources = [];
        $changes = [];

        foreach ( $costs as $rowId => $submitted ) {
            if ( $submitted === null || trim( (string) $submitted ) === '' ) {
                continue;
            }

            $row = ProductUnitQuantity::with( [ 'product', 'unit' ] )->find( (int) $rowId );

            if ( ! $row instanceof ProductUnitQuantity ) {
                $result[ 'refused' ]++;
                $this->addRefusal( $result, sprintf( __( 'ไม่พบรายการหมายเลข %s' ), $rowId ) );

                continue;
            }

            /**
             * The branch rule. A row stamped with another branch is that branch's cost, and the
             * person editing is not standing in it.
             */
            if ( $branchId !== null && $row->branch_id !== null && (int) $row->branch_id !== $branchId ) {
                $result[ 'refused' ]++;
                $this->addRefusal( $result, sprintf(
                    __( 'ข้าม "%s" เพราะเป็นต้นทุนของสาขาอื่น' ),
                    $row->product?->name ?? ( '#' . $row->id )
                ) );

                continue;
            }

            $value = $this->parseCost( $submitted );

            if ( $value === null ) {
                $result[ 'invalid' ]++;
                $this->addRefusal( $result, sprintf(
                    __( '"%s" ของ "%s" ไม่ใช่จำนวนเงินที่ใช้ได้' ),
                    (string) $submitted,
                    $row->product?->name ?? ( '#' . $row->id )
                ) );

                continue;
            }

            $current = round( (float) $row->cogs, self::COST_PRECISION );

            if ( abs( $current - $value ) < ( 1 / ( 10 ** self::COST_PRECISION ) ) / 2 ) {
                $result[ 'unchanged' ]++;

                continue;
            }

            $row->cogs = $value;
            $row->save();

            $result[ 'updated' ]++;
            $changes[] = $row->id . ':' . $current . '>' . $value;
            $editedUnitsByProduct[ (int) $row->product_id ][] = (int) $row->unit_id;

            /**
             * One source per product, the largest unit that was typed.
             *
             * Deriving from the pack and dividing down is steadier than multiplying a single item's
             * cost up, and picking deterministically matters: spreading from two different typed
             * rows of the same product would have each overwrite the other's siblings, and which
             * figure survived would depend on the order rows came back from the database.
             */
            $productId = (int) $row->product_id;
            $existing = $spreadSources[ $productId ] ?? null;

            if ( $existing === null || (float) ( $row->unit?->value ?? 0 ) > (float) ( $existing->unit?->value ?? 0 ) ) {
                $spreadSources[ $productId ] = $row;
            }
        }

        if ( $spreadToOtherUnits ) {
            foreach ( $spreadSources as $productId => $source ) {
                $result[ 'spread' ] += $this->productService->spreadCogsAcrossUnits(
                    $source,
                    $editedUnitsByProduct[ $productId ] ?? []
                );
            }
        }

        if ( $result[ 'updated' ] > 0 ) {
            $this->recordAudit( $result, $changes, $spreadToOtherUnits );
        }

        return $result;
    }

    /**
     * One audit row per save, not per line.
     *
     * A change of cost moves reported profit, so it has to be attributable. Writing a row per line
     * would bury the rest of the trail under a single afternoon's corrections, so the line detail is
     * recorded compactly as "rowId:before>after" and AuditTrailService caps the payload.
     *
     * @param array{updated: int, unchanged: int, refused: int, invalid: int, spread: int, refusals: array<int, string>} $result
     * @param array<int, string>                                                                                        $changes
     */
    private function recordAudit( array $result, array $changes, bool $spreadToOtherUnits ): void
    {
        $this->auditTrail->record(
            action: AuditTrail::ACTION_UPDATED,
            resource: 'ns.products-cost',
            resourceLabel: __( 'แก้ต้นทุนหลายรายการ' ),
            recordLabel: sprintf( __( 'แก้ %d รายการ' ), $result[ 'updated' ] ),
            changes: [
                'branch_id' => $this->branchId(),
                'updated' => $result[ 'updated' ],
                'spread' => $result[ 'spread' ],
                'refused' => $result[ 'refused' ],
                'invalid' => $result[ 'invalid' ],
                'spread_enabled' => $spreadToOtherUnits,
                'rows' => implode( ',', $changes ),
            ],
        );
    }

    /**
     * A submitted cost, or null if it is not a usable amount.
     *
     * Thousands separators are accepted because a figure copied out of a spreadsheet or an invoice
     * carries them. A negative is refused rather than clamped: goods bought for a negative amount is
     * not a thing, so it is a typo, and silently storing zero would hide it.
     */
    private function parseCost( string $submitted ): ?float
    {
        $cleaned = str_replace( [ ',', ' ', "\u{00a0}" ], '', trim( $submitted ) );

        if ( $cleaned === '' || ! is_numeric( $cleaned ) ) {
            return null;
        }

        $value = (float) $cleaned;

        if ( $value < 0 ) {
            return null;
        }

        return round( $value, self::COST_PRECISION );
    }

    /**
     * @param array{updated: int, unchanged: int, refused: int, invalid: int, spread: int, refusals: array<int, string>} $result
     */
    private function addRefusal( array &$result, string $message ): void
    {
        /**
         * Capped so a malformed bulk submission cannot fill the session flash with hundreds of
         * lines. The counts above still report the full total.
         */
        if ( count( $result[ 'refusals' ] ) < 10 ) {
            $result[ 'refusals' ][] = $message;
        }
    }

    /**
     * @param array{search?: string|null, category_id?: int|null, only_missing?: bool, only_in_stock?: bool} $filters
     * @return Builder<ProductUnitQuantity>
     */
    private function query( array $filters ): Builder
    {
        $query = $this->branchScope( ProductUnitQuantity::query() )
            ->select( 'nexopos_products_unit_quantities.*' )
            ->join( 'nexopos_products as product', 'product.id', '=', 'nexopos_products_unit_quantities.product_id' );

        if ( ! empty( $filters[ 'only_missing' ] ) ) {
            $query->where( fn ( Builder $q ) => $this->missingCostScope( $q ) );
        }

        if ( ! empty( $filters[ 'only_in_stock' ] ) ) {
            $query->where( 'nexopos_products_unit_quantities.quantity', '>', 0 );
        }

        if ( ! empty( $filters[ 'category_id' ] ) ) {
            $query->where( 'product.category_id', (int) $filters[ 'category_id' ] );
        }

        $search = trim( (string) ( $filters[ 'search' ] ?? '' ) );

        if ( $search !== '' ) {
            /**
             * Split on whitespace so "น้ำตาล ถุง" narrows rather than having to be typed as one
             * string, which is how the product list already behaves.
             */
            foreach ( array_unique( preg_split( '/[\s+*]+/u', $search, -1, PREG_SPLIT_NO_EMPTY ) ?: [] ) as $term ) {
                $query->where( function ( Builder $match ) use ( $term ): void {
                    foreach ( [ 'name', 'sku', 'barcode' ] as $column ) {
                        $match->orWhere( 'product.' . $column, 'like', '%' . $term . '%' );
                    }

                    $match->orWhere( 'nexopos_products_unit_quantities.barcode', 'like', '%' . $term . '%' );
                } );
            }
        }

        /**
         * Ordered by name rather than by what is most urgent, so the list is the same on every visit
         * and somebody working through 260 rows over two sittings can tell where they stopped.
         */
        return $query
            ->orderBy( 'product.name' )
            ->orderBy( 'nexopos_products_unit_quantities.unit_id' );
    }

    /**
     * Rows the current branch is allowed to see and edit.
     *
     * Another branch's rows are left out entirely rather than shown read-only: a cost on screen that
     * belongs to a different shelf reads as this branch's figure, which is the confusion the whole
     * per-branch split exists to remove.
     *
     * @param  Builder<ProductUnitQuantity> $query
     * @return Builder<ProductUnitQuantity>
     */
    private function branchScope( Builder $query ): Builder
    {
        $branchId = $this->branchId();

        if ( $branchId === null ) {
            return $query;
        }

        return $query->where( fn ( Builder $scoped ) => $scoped
            ->where( 'nexopos_products_unit_quantities.branch_id', $branchId )
            ->orWhereNull( 'nexopos_products_unit_quantities.branch_id' ) );
    }

    /**
     * @param  Builder<ProductUnitQuantity> $query
     * @return Builder<ProductUnitQuantity>
     */
    private function missingCostScope( Builder $query ): Builder
    {
        return $query
            ->whereNull( 'nexopos_products_unit_quantities.cogs' )
            ->orWhere( 'nexopos_products_unit_quantities.cogs', '<=', 0 );
    }
}
