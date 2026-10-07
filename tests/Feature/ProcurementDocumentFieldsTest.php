<?php

namespace Tests\Feature;

use App\Crud\ProcurementCrud;
use App\Models\Procurement;
use App\Models\ProcurementProduct;
use App\Models\Product;
use App\Models\Provider;
use App\Services\CrudEntry;
use App\Services\TestService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * The paperwork a purchase travels with, end to end.
 *
 * A purchase order held only its own name, an invoice reference and two dates, which left both desks
 * that handle it afterwards with nothing to work from. Receiving checks the goods that arrive against
 * the supplier's delivery note — price and quantity, line by line. Accounts pays against the
 * supplier's billing note and has to know which receipt that billing note belongs to; without it the
 * payment is filed against the wrong purchase, which is the failure the shop reported.
 *
 * Pinned here: the columns exist, the create form offers them and offers them last, the values
 * survive a create and a re-read, the due date is worked out from the credit term but never
 * overwrites a date somebody typed, the list shows them with the money split the way the shop reads
 * it, and the printable document renders with the numbers on it.
 */
class ProcurementDocumentFieldsTest extends TestCase
{
    use DatabaseTransactions, WithAuthentication;

    /**
     * @return array<int, string>
     */
    private function documentColumns(): array
    {
        return [
            'purchase_order_number',
            'delivery_note_number',
            'delivery_note_date',
            'billing_note_number',
            'billing_note_date',
            'contact_person',
            'credit_days',
            'due_date',
        ];
    }

    /**
     * A supplier to buy from, created if the fixture holds none.
     *
     * prepareProcurement picks a supplier at random and this file used to do the same, which fails
     * outright on a database that has no suppliers yet — the base install creates none. Building one
     * keeps the test standing on its own instead of on whatever ran before it.
     */
    private function givenProvider( int $authorId ): Provider
    {
        $provider = Provider::first();

        if ( $provider instanceof Provider ) {
            return $provider;
        }

        $provider = new Provider;
        $provider->first_name = 'ผู้ขายสำหรับชุดทดสอบ';
        $provider->code = 'SP-TEST-0001';
        $provider->author_id = $authorId;
        $provider->save();

        return $provider;
    }

    /**
     * Posts a purchase through the real endpoint, so the controller whitelist, the request rules and
     * the service all get a say — the same path the screen uses.
     *
     * @param array<string, mixed> $general dot-notation overrides, as prepareProcurement expects
     */
    private function givenProcurement( array $general = [] ): Procurement
    {
        $user = $this->attemptAuthenticate();

        /**
         * The payload is built here rather than through TestService::prepareProcurement, which picks a
         * tax group and a product at random and so needs a catalogue to already exist. This file is
         * about the header of a purchase, not its lines, and an earlier version of it passed or failed
         * depending on which tests had run first — the exact fragility this suite should not have.
         *
         * No products: the service only saves lines when there are any, and a purchase raised with
         * none is a real state — the desk opens it, records the paperwork, and adds the lines as the
         * goods are counted.
         */
        $payload = [
            'name' => 'ใบรับของสำหรับชุดทดสอบ ' . uniqid(),
            'general' => array_merge( [
                'provider_id' => $this->givenProvider( $user?->id ?? 1 )->id,
                'payment_status' => Procurement::PAYMENT_UNPAID,
                'delivery_status' => Procurement::PENDING,
                'automatic_approval' => 0,
            ], $this->undot( $general ) ),
            'products' => [],
        ];

        $response = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'POST', 'api/procurements', $payload );

        $response->assertJsonPath( 'status', 'success' );

        return Procurement::findOrFail( $response->json( 'data.procurement.id' ) );
    }

    /**
     * Turns the 'general.x' keys the callers use into plain keys, so the tests read the same way the
     * rest of the suite does while the payload stays a plain nested array.
     *
     * @param  array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function undot( array $values ): array
    {
        $result = [];

        foreach ( $values as $key => $value ) {
            $result[ str_starts_with( $key, 'general.' ) ? substr( $key, 8 ) : $key ] = $value;
        }

        return $result;
    }

    public function test_the_table_carries_every_document_column(): void
    {
        foreach ( $this->documentColumns() as $column ) {
            $this->assertTrue(
                Schema::hasColumn( 'nexopos_procurements', $column ),
                "The purchase table has nowhere to keep {$column}."
            );
        }
    }

    public function test_the_create_form_offers_the_document_fields(): void
    {
        $this->attemptAuthenticate();

        $procurement = null;
        $form = include base_path( 'app/Forms/procurement/general.php' );
        $names = array_column( $form[ 'fields' ], 'name' );

        foreach ( $this->documentColumns() as $column ) {
            $this->assertContains( $column, $names, "The create form has no field for {$column}." );
        }
    }

    /**
     * ns-procurement.vue re-merges tabs.general.fields by numeric index when editing, so a field
     * inserted among the original seven would load another field's saved value. They have to be last.
     */
    public function test_the_document_fields_come_after_the_original_ones(): void
    {
        $this->attemptAuthenticate();

        $procurement = null;
        $form = include base_path( 'app/Forms/procurement/general.php' );
        $names = array_column( $form[ 'fields' ], 'name' );

        $firstDocumentIndex = min( array_map( fn ( string $c ) => array_search( $c, $names, true ), $this->documentColumns() ) );

        foreach ( [ 'invoice_reference', 'delivery_time', 'invoice_date', 'automatic_approval', 'delivery_status', 'payment_status', 'provider_id' ] as $original ) {
            $this->assertLessThan(
                $firstDocumentIndex,
                array_search( $original, $names, true ),
                "The original field {$original} now sits after a document field, which shifts the index the edit screen merges by."
            );
        }
    }

    public function test_the_document_details_survive_a_create(): void
    {
        $procurement = $this->givenProcurement( [
            'general.purchase_order_number' => 'PO-2569-0001',
            'general.delivery_note_number' => 'IV0011426',
            'general.delivery_note_date' => '2026-09-01',
            'general.billing_note_number' => '69090608',
            'general.billing_note_date' => '2026-09-18',
            'general.contact_person' => 'คุณสมชาย 081-234-5678',
            'general.credit_days' => 30,
        ] );

        $this->assertSame( 'PO-2569-0001', $procurement->purchase_order_number );
        $this->assertSame( 'IV0011426', $procurement->delivery_note_number );
        $this->assertSame( '69090608', $procurement->billing_note_number );
        $this->assertSame( 'คุณสมชาย 081-234-5678', $procurement->contact_person );
        $this->assertSame( 30, (int) $procurement->credit_days );
        $this->assertStringStartsWith( '2026-09-01', (string) $procurement->delivery_note_date );
        $this->assertStringStartsWith( '2026-09-18', (string) $procurement->billing_note_date );
    }

    public function test_a_purchase_saves_with_no_paperwork_at_all(): void
    {
        $procurement = $this->givenProcurement();

        foreach ( $this->documentColumns() as $column ) {
            $this->assertEmpty(
                $procurement->$column,
                "{$column} was given a value nobody entered, so a purchase raised before its paperwork exists is not blank."
            );
        }
    }

    public function test_the_due_date_is_worked_out_from_the_credit_term(): void
    {
        $procurement = $this->givenProcurement( [
            'general.invoice_date' => '2026-09-18',
            'general.credit_days' => 30,
        ] );

        $this->assertStringStartsWith(
            '2026-10-18',
            (string) $procurement->due_date,
            'The due date was not computed from the invoice date plus the credit days.'
        );
    }

    /**
     * Terms get agreed verbally that do not match days × invoice date — a month end, a date moved for
     * a holiday. The typed date is the one that was agreed, so arithmetic must not overrule it.
     */
    public function test_a_typed_due_date_is_never_overwritten(): void
    {
        $procurement = $this->givenProcurement( [
            'general.invoice_date' => '2026-09-18',
            'general.credit_days' => 30,
            'general.due_date' => '2026-10-31',
        ] );

        $this->assertStringStartsWith( '2026-10-31', (string) $procurement->due_date );
    }

    public function test_no_due_date_is_guessed_from_a_term_alone(): void
    {
        $procurement = $this->givenProcurement( [
            'general.credit_days' => 30,
        ] );

        $this->assertEmpty(
            $procurement->due_date,
            'A due date was invented without an invoice date to count from, which puts a wrong date in front of the person paying.'
        );
    }

    /**
     * The visible text of a cell, with the stacked markup taken back off.
     *
     * Cells that carry a pair of values render as two stacked divs, so a test that wants to know what
     * the cell says has to read through the markup rather than compare against it.
     */
    private function cellText( CrudEntry $entry, string $column ): string
    {
        $html = (string) $entry->$column;

        return trim( preg_replace( '/\s+/u', ' ', strip_tags( str_replace( '</div>', '</div> ', $html ) ) ) );
    }

    /**
     * The same short date the list itself prints.
     *
     * Asked of the list's own formatter rather than written out as a literal, because the year depends
     * on the shop's date settings: the live shop prints the Buddhist year, a fresh test install prints
     * the Gregorian one. A literal here would pass on one and fail on the other for no useful reason.
     */
    private function shortDateOf( string $date ): string
    {
        $method = new \ReflectionMethod( ProcurementCrud::class, 'shortDate' );
        $method->setAccessible( true );

        return (string) $method->invoke( new ProcurementCrud, $date );
    }

    /**
     * Builds one row of the list out of a purchase carrying every document value.
     */
    private function givenListedRow(): CrudEntry
    {
        $row = [
            'id' => 1,
            'name' => 'ใบรับของสำหรับชุดทดสอบ',
            'cost' => 1070,
            'tax_value' => 70,
            'value' => 0,
            'payment_status' => Procurement::PAYMENT_UNPAID,
            'delivery_status' => Procurement::PENDING,
            'purchase_order_number' => 'PO-2569-0001',
            'delivery_note_number' => 'DN-77001',
            'delivery_note_date' => '2026-09-18',
            'billing_note_number' => 'BN-88002',
            'billing_note_date' => '2026-09-19',
            'contact_person' => 'คุณสมชาย 081-234-5678',
            'credit_days' => 30,
            'due_date' => '2026-10-18',
            'invoice_date' => '2026-09-17',
            'providers_code' => 'SP530060',
            'providers_first_name' => 'หจก. ผู้ขายสำหรับชุดทดสอบ',
            'user_username' => 'admin',
        ];

        return ( new ProcurementCrud )->setActions( new CrudEntry( $row ) );
    }

    /**
     * Every value the two desks read has its own column, and every heading is short enough to sit on
     * one line at the compact size.
     *
     * Pairing values into shared cells was tried and rejected: the register read as though half of it
     * had gone missing. The problem was never the count — it was that headings were allowed to break,
     * and Thai carries no spaces, so a squeezed browser broke `เลขที่ใบวางบิล` into four stacked
     * fragments. The headings are pinned in ns-crud.vue instead, and kept short here so that pinning
     * them does not cost more width than it saves.
     */
    public function test_every_value_has_its_own_column_with_a_one_line_heading(): void
    {
        $this->attemptAuthenticate();

        $columns = ( new ProcurementCrud )->getColumns();

        $expected = array_merge( $this->documentColumns(), [
            'name',
            'providers_code',
            'providers_first_name',
            'net_goods_value',
            'tax_value',
            'cost',
            'delivery_status',
            'payment_status',
            'invoice_date',
            'user_username',
        ] );

        foreach ( $expected as $column ) {
            $this->assertArrayHasKey( $column, $columns, "The purchase list no longer shows {$column} as its own column." );
        }

        foreach ( $columns as $identifier => $definition ) {
            $label = $definition[ 'label' ] ?? '';

            $this->assertNotSame( '', $label, "Column {$identifier} has no heading." );
            $this->assertLessThanOrEqual(
                12,
                mb_strlen( $label ),
                "The heading of {$identifier} is long enough to push the table past a screen once pinned to one line: {$label}"
            );
        }
    }

    /**
     * No two columns may carry the same heading.
     *
     * Three of these columns hold a date, and shortening all three to `ลว.` — the Thai abbreviation of
     * "dated" — put two identical headings side by side with nothing to say which document each date
     * belonged to. The shop read the list and asked what they were. A heading has to name its own
     * column without needing the one beside it, so each says which document it dates.
     */
    public function test_no_two_columns_share_a_heading(): void
    {
        $this->attemptAuthenticate();

        $labels = array_map(
            fn ( array $definition ): string => $definition[ 'label' ] ?? '',
            ( new ProcurementCrud )->getColumns()
        );

        $duplicates = array_keys( array_filter( array_count_values( $labels ), fn ( int $count ): bool => $count > 1 ) );

        $this->assertSame(
            [],
            $duplicates,
            'Two columns carry the same heading, so neither one says which document it belongs to: ' . implode( ', ', $duplicates )
        );

        /**
         * A date column must read as a date. `ใบกำกับ` on its own names a document, not the day it
         * carries, which is how the invoice date column came to look like a document number column.
         */
        foreach ( [ 'billing_note_date', 'delivery_note_date', 'invoice_date', 'due_date' ] as $dateColumn ) {
            $label = $labels[ $dateColumn ] ?? '';

            $this->assertMatchesRegularExpression(
                '/^(วัน|ลว\.|ครบ)/u',
                $label,
                "The heading of {$dateColumn} does not read as a date: {$label}"
            );
        }
    }

    /**
     * Each column carries its own value and nothing else. A register is read down a column, so a value
     * sitting in the wrong one is worse than a missing one.
     */
    public function test_each_column_carries_its_own_value(): void
    {
        $this->attemptAuthenticate();

        $entry = $this->givenListedRow();

        $expected = [
            'name' => 'ใบรับของสำหรับชุดทดสอบ',
            'billing_note_number' => 'BN-88002',
            'billing_note_date' => $this->shortDateOf( '2026-09-19' ),
            'delivery_note_number' => 'DN-77001',
            'delivery_note_date' => $this->shortDateOf( '2026-09-18' ),
            'purchase_order_number' => 'PO-2569-0001',
            'providers_code' => 'SP530060',
            'providers_first_name' => 'หจก. ผู้ขายสำหรับชุดทดสอบ',
            'contact_person' => 'คุณสมชาย 081-234-5678',
            'credit_days' => '30',
            'due_date' => $this->shortDateOf( '2026-10-18' ),
            'invoice_date' => $this->shortDateOf( '2026-09-17' ),
            'user_username' => 'admin',
            'net_goods_value' => trim( (string) ns()->currency->define( 1000 )->format() ),
            'tax_value' => trim( (string) ns()->currency->define( 70 )->format() ),
            'cost' => trim( (string) ns()->currency->define( 1070 )->format() ),
            'delivery_status' => 'รอดำเนินการ',
            'payment_status' => 'ยังไม่ชำระ',
        ];

        foreach ( $expected as $column => $value ) {
            $this->assertSame(
                $value,
                $this->cellText( $entry, $column ),
                "The {$column} column does not read what it should."
            );
        }
    }

    /**
     * The three parts of the front end that keep the register readable at eighteen columns. Asserted
     * against the source because a heading that breaks mid-word is invisible to a PHP test.
     */
    public function test_the_table_pins_headings_and_shrinks_when_there_are_many_columns(): void
    {
        $crud = file_get_contents( base_path( 'resources/ts/components/ns-crud.vue' ) );

        $this->assertStringContainsString(
            'whitespace-nowrap">{{ column.label }}',
            $crud,
            'Headings are free to break again, which shreds Thai into one fragment per character.'
        );

        $this->assertStringContainsString(
            'v-if="column.$sort !== false"',
            $crud,
            'The sort arrow is reserving its box on unsortable columns again, which throws away 24px of every column.'
        );

        $this->assertStringContainsString(
            'isDenseTable ? \'ns-table-compact\' : \'\'',
            $crud,
            'A list with many columns no longer switches to the compact size.'
        );

        $this->assertMatchesRegularExpression(
            '/isDenseTable\(\)\s*\{\s*return Object\.keys\(\s*this\.columns \|\| \{\}\s*\)\.length > 12;/',
            $crud,
            'The compact threshold is gone, so the purchase list would render at the roomy size and overflow.'
        );

        foreach ( [ 'light', 'dark', 'phosphor' ] as $theme ) {
            $this->assertStringContainsString(
                '.ns-table-compact',
                file_get_contents( base_path( "resources/css/{$theme}/_table.css" ) ),
                "The {$theme} theme has no compact table style, so the list would overflow on that theme."
            );
        }
    }

    /**
     * The Set Paid option used to be offered on purchases that were already settled: the guard
     * compares against Procurement::PAYMENT_PAID, but payment_status had already been swapped for its
     * Thai label by the time the comparison ran, so it never matched.
     */
    public function test_a_settled_purchase_is_not_offered_set_paid_again(): void
    {
        $this->attemptAuthenticate();

        $paid = ( new ProcurementCrud )->setActions( new CrudEntry( [
            'id' => 1,
            'name' => 'จ่ายแล้ว',
            'cost' => 100,
            'tax_value' => 0,
            'value' => 0,
            'payment_status' => Procurement::PAYMENT_PAID,
            'delivery_status' => Procurement::STOCKED,
        ] ) );

        $this->assertNotContains(
            'set_paid',
            array_column( (array) ( $paid->{'$actions'} ?? [] ), 'identifier' ),
            'A purchase that is already paid is still being offered Set Paid.'
        );

        $unpaid = ( new ProcurementCrud )->setActions( new CrudEntry( [
            'id' => 2,
            'name' => 'ยังไม่จ่าย',
            'cost' => 100,
            'tax_value' => 0,
            'value' => 0,
            'payment_status' => Procurement::PAYMENT_UNPAID,
            'delivery_status' => Procurement::PENDING,
        ] ) );

        $this->assertContains(
            'set_paid',
            array_column( (array) ( $unpaid->{'$actions'} ?? [] ), 'identifier' ),
            'An unpaid purchase must still be settleable from the list.'
        );
    }

    /**
     * cost is the tax-inclusive total, so the net value of the goods is cost minus tax. Getting this
     * backwards would overstate what the shop owes on every line of the register.
     */
    public function test_the_net_goods_value_is_the_total_less_the_tax(): void
    {
        $this->attemptAuthenticate();

        $procurement = new Procurement;
        $procurement->cost = 1070;
        $procurement->tax_value = 70;
        $procurement->value = 0;
        $procurement->payment_status = Procurement::PAYMENT_UNPAID;
        $procurement->delivery_status = Procurement::PENDING;

        $entry = ( new ProcurementCrud )->setActions( new CrudEntry( $procurement->toArray() + [ 'id' => 1 ] ) );

        $this->assertSame(
            trim( (string) ns()->currency->define( 1000 )->format() ),
            $this->cellText( $entry, 'net_goods_value' ),
            'The net goods value is not the total less the tax.'
        );
    }

    public function test_an_absent_document_prints_blank_rather_than_a_dash(): void
    {
        $this->attemptAuthenticate();

        $procurement = new Procurement;
        $procurement->cost = 0;
        $procurement->tax_value = 0;
        $procurement->value = 0;
        $procurement->credit_days = 0;
        $procurement->payment_status = Procurement::PAYMENT_UNPAID;
        $procurement->delivery_status = Procurement::PENDING;

        $entry = ( new ProcurementCrud )->setActions( new CrudEntry( $procurement->toArray() + [ 'id' => 1 ] ) );

        /**
         * The paired cells are read through their markup: an absent document must leave the cell
         * saying nothing at all, not a dash, a nought, or a stray label like "ลว." with no date after it.
         */
        foreach ( [ 'billing_note_number', 'delivery_note_number', 'purchase_order_number', 'contact_person', 'credit_days' ] as $field ) {
            $this->assertSame( '', $this->cellText( $entry, $field ), "{$field} printed something when there is nothing to print." );
        }
    }

    public function test_the_list_offers_a_one_click_print(): void
    {
        $this->attemptAuthenticate();

        $procurement = $this->givenProcurement();

        $entry = ( new ProcurementCrud )->setActions( new CrudEntry( $procurement->toArray() ) );

        $identifiers = array_column( (array) ( $entry->{'$actions'} ?? [] ), 'identifier' );

        $this->assertContains( 'print', $identifiers, 'The list has no print action, so the desk has to open the document and hunt for a button.' );
    }

    public function test_the_printable_document_renders_with_the_numbers_on_it(): void
    {
        $procurement = $this->givenProcurement( [
            'general.delivery_note_number' => 'IV0011426',
            'general.billing_note_number' => '69090608',
            'general.contact_person' => 'คุณสมชาย',
            'general.invoice_date' => '2026-09-18',
            'general.credit_days' => 30,
        ] );

        /**
         * actingAs, not the session the api calls above use: the dashboard pages run through the
         * session guard and AuthenticateSession asks it for viaRemember(), which the request guard
         * Sanctum installs does not have. Every other test that opens a dashboard page does the same.
         */
        $response = $this->actingAs( $this->attemptGetAnyUserFromRole( 'admin' ), 'web' )
            ->get( '/dashboard/procurements/edit/' . $procurement->id . '/invoice' );

        $response->assertOk();

        $content = $response->getContent();

        $this->assertStringContainsString( 'IV0011426', $content, 'The delivery note number is not on the printable document.' );
        $this->assertStringContainsString( '69090608', $content, 'The billing note number is not on the printable document.' );
        $this->assertStringContainsString( 'คุณสมชาย', $content );
        $this->assertStringContainsString( '18/10/2026', $content, 'The due date is not on the printable document.' );
    }

    public function test_the_edit_screen_hands_back_the_saved_values(): void
    {
        $procurement = $this->givenProcurement( [
            'general.delivery_note_number' => 'IV0011426',
            'general.credit_days' => 30,
        ] );

        $response = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'GET', '/api/forms/ns.procurement/' . $procurement->id );

        $response->assertOk();

        $fields = collect( $response->json( 'tabs.general.fields' ) )->keyBy( 'name' );

        $this->assertSame( 'IV0011426', $fields[ 'delivery_note_number' ][ 'value' ] ?? null, 'The edit screen did not load the saved delivery note number.' );
        $this->assertSame( 30, (int) ( $fields[ 'credit_days' ][ 'value' ] ?? 0 ) );
    }

    public function test_the_edit_page_itself_opens(): void
    {
        $procurement = $this->givenProcurement();

        $this->actingAs( $this->attemptGetAnyUserFromRole( 'admin' ), 'web' )
            ->get( '/dashboard/procurements/edit/' . $procurement->id )
            ->assertOk();
    }

    /**
     * Puts one line on a purchase that points at a product id nothing answers to.
     *
     * This is the state the shop's own record is in. The catalogue was deleted and imported again, so
     * the products came back with new ids and every line of the purchase raised before that points at
     * an id that no longer exists.
     */
    private function givenLineWithAMissingProduct( Procurement $procurement, int $authorId ): ProcurementProduct
    {
        $line = new ProcurementProduct;
        $line->name = 'สินค้าที่ถูกลบออกจากระบบแล้ว';
        $line->procurement_id = $procurement->id;
        $line->product_id = ( (int) Product::max( 'id' ) ) + 100000;
        $line->quantity = 10;
        $line->purchase_price = 100;
        $line->total_purchase_price = 1000;
        $line->tax_type = 'inclusive';
        $line->unit_id = 0;
        $line->author_id = $authorId;
        $line->save();

        return $line;
    }

    public function test_the_edit_screen_opens_when_a_line_points_at_a_deleted_product(): void
    {
        $user = $this->attemptAuthenticate();
        $procurement = $this->givenProcurement();
        $this->givenLineWithAMissingProduct( $procurement, $user?->id ?? 1 );

        $response = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'GET', '/api/forms/ns.procurement/' . $procurement->id );

        $response->assertOk();

        $lines = $response->json( 'products' );

        $this->assertCount( 1, $lines, 'The line whose product is gone was dropped instead of shown.' );
        $this->assertTrue(
            (bool) ( $lines[ 0 ][ 'procurement' ][ '$invalid' ] ?? false ),
            'A line pointing at a deleted product must be flagged so the desk can see which one to fix.'
        );
        $this->assertSame( [], $lines[ 0 ][ 'unit_quantities' ] ?? null, 'A missing product has no units to offer.' );
    }

    public function test_the_edit_page_opens_when_a_line_points_at_a_deleted_product(): void
    {
        $user = $this->attemptAuthenticate();
        $procurement = $this->givenProcurement();
        $this->givenLineWithAMissingProduct( $procurement, $user?->id ?? 1 );

        $this->actingAs( $this->attemptGetAnyUserFromRole( 'admin' ), 'web' )
            ->get( '/dashboard/procurements/edit/' . $procurement->id )
            ->assertOk();
    }

    /**
     * The whole catalogue used to be read once per line and thrown away, because `load()` returns the
     * model and `get()` on a model starts a fresh query. Three lines must not cost more queries than
     * one purchase, one line set and one product lookup.
     */
    public function test_the_edit_screen_does_not_read_the_catalogue_once_per_line(): void
    {
        $user = $this->attemptAuthenticate();
        $procurement = $this->givenProcurement();

        foreach ( range( 1, 3 ) as $ignored ) {
            $this->givenLineWithAMissingProduct( $procurement, $user?->id ?? 1 );
        }

        $productReads = 0;

        DB::listen( function ( $query ) use ( &$productReads ) {
            if ( preg_match( '/\bfrom\s+[`"]?nexopos_products[`"]?/i', $query->sql ) === 1 ) {
                $productReads++;
            }
        } );

        $response = $this->withSession( $this->app[ 'session' ]->all() )
            ->json( 'GET', '/api/forms/ns.procurement/' . $procurement->id );

        $response->assertOk();
        $this->assertCount( 3, $response->json( 'products' ) );

        $this->assertLessThanOrEqual(
            2,
            $productReads,
            'The product table is being read once per line again; every line must be resolved in one lookup.'
        );
    }
}
