<?php

namespace Tests\Feature;

use App\Exceptions\NotAllowedException;
use App\Models\User;
use App\Services\LabelPresetService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * The sticker sizes the label page prints on.
 *
 * The page described a label in pixels, held in the browser and forgotten on reload. A sticker is a
 * physical object, and pixels only become millimetres through a printer's own resolution, so a sheet
 * that fitted on one printer came out cropped on the next. The shop uses many sizes and no fixed one,
 * so the size has to be defined by whoever loads the printer, shared between the tills, and remembered
 * per branch — which is exactly what is pinned here.
 */
class LabelPresetTest extends TestCase
{
    use WithAuthentication;

    private LabelPresetService $presets;

    private ?User $actor = null;

    private ?int $branchBefore = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->presets = app()->make( LabelPresetService::class );

        $this->forgetPresets();
    }

    protected function tearDown(): void
    {
        $this->forgetPresets();

        if ( $this->actor instanceof User ) {
            DB::table( 'nexopos_users' )->where( 'id', $this->actor->id )->update( [ 'branch_id' => $this->branchBefore ] );
        }

        session()->forget( 'selected_branch_id' );

        parent::tearDown();
    }

    public function test_a_branch_that_has_chosen_nothing_gets_workable_defaults(): void
    {
        $selected = $this->presets->selectedFor( 1 );

        $this->assertNull( $selected[ 'name' ] );

        foreach ( [ 'label_width', 'label_height', 'columns', 'barcode_height', 'font_size', 'paper' ] as $key ) {
            $this->assertArrayHasKey( $key, $selected[ 'settings' ], sprintf( 'the defaults carry no %s', $key ) );
        }

        $this->assertGreaterThan( 0, $selected[ 'settings' ][ 'label_width' ] );
        $this->assertSame( 'a4', $selected[ 'settings' ][ 'paper' ] );
    }

    /**
     * These figures are written straight into print CSS as lengths, so a negative width or a thousand
     * columns has to be brought into range rather than rendered.
     */
    public function test_figures_out_of_range_are_brought_back_into_range(): void
    {
        $settings = $this->presets->sanitize( [
            'label_width' => -40,
            'label_height' => 99999,
            'columns' => 400,
            'gap_x' => 'not a number',
            'font_size' => 0,
            'paper' => 'origami',
            'show_store_name' => 'false',
            'unknown_key' => 'dropped',
        ] );

        $this->assertGreaterThanOrEqual( 10, $settings[ 'label_width' ] );
        $this->assertLessThanOrEqual( 300, $settings[ 'label_height' ] );
        $this->assertLessThanOrEqual( 12, $settings[ 'columns' ] );
        $this->assertIsInt( $settings[ 'columns' ] );
        $this->assertGreaterThanOrEqual( 4, $settings[ 'font_size' ] );

        $this->assertSame(
            'a4',
            $settings[ 'paper' ],
            'An unknown paper size was accepted, and it would reach @page as a nonsense value.'
        );

        $this->assertFalse( $settings[ 'show_store_name' ], '"false" was read as true' );
        $this->assertArrayNotHasKey( 'unknown_key', $settings, 'an unknown key survived into the print CSS' );

        /**
         * A field that was not supplied keeps its default rather than becoming null and printing a
         * label with no height.
         */
        $this->assertGreaterThan( 0, $settings[ 'gap_x' ] );
    }

    public function test_a_size_is_saved_under_a_name_and_read_back(): void
    {
        $this->presets->save( '50x25 สามดวง', [
            'label_width' => 50,
            'label_height' => 25,
            'columns' => 3,
            'paper' => 'a4',
        ], 2 );

        $found = $this->presets->find( '50x25 สามดวง' );

        $this->assertNotNull( $found );
        $this->assertSame( 50.0, (float) $found[ 'settings' ][ 'label_width' ] );
        $this->assertSame( 3, $found[ 'settings' ][ 'columns' ] );

        $this->assertSame(
            '50x25 สามดวง',
            $this->presets->selectedFor( 2 )[ 'name' ],
            'Saving a size did not hand it to the branch that saved it, so it has to be picked again.'
        );
    }

    public function test_saving_the_same_name_twice_corrects_it_rather_than_duplicating_it(): void
    {
        $this->presets->save( 'ม้วน 58', [ 'label_width' => 58, 'paper' => 'roll' ], 1 );
        $this->presets->save( 'ม้วน 58', [ 'label_width' => 40, 'paper' => 'roll' ], 1 );

        $this->assertCount( 1, $this->presets->all() );
        $this->assertSame( 40.0, (float) $this->presets->find( 'ม้วน 58' )[ 'settings' ][ 'label_width' ] );
    }

    /**
     * The requirement in one test: many sizes, none fixed, and each branch on the one loaded in its own
     * printer.
     */
    public function test_each_branch_keeps_its_own_choice(): void
    {
        $this->presets->save( 'แผ่น A4 สามดวง', [ 'label_width' => 50, 'columns' => 3 ], 1 );
        $this->presets->save( 'ม้วน 58 มม.', [ 'label_width' => 58, 'columns' => 1, 'paper' => 'roll' ], 2 );

        $this->assertSame( 'แผ่น A4 สามดวง', $this->presets->selectedFor( 1 )[ 'name' ] );
        $this->assertSame( 'ม้วน 58 มม.', $this->presets->selectedFor( 2 )[ 'name' ] );

        $this->assertSame(
            'roll',
            $this->presets->selectedFor( 2 )[ 'settings' ][ 'paper' ],
            'the second branch was handed the first branch\'s paper'
        );

        /**
         * Both sizes stay available to everyone: the head office defines them once and any branch can
         * pick any of them.
         */
        $this->assertCount( 2, $this->presets->all() );
    }

    public function test_deleting_a_size_releases_the_branches_that_were_using_it(): void
    {
        $this->presets->save( 'ชั่วคราว', [ 'label_width' => 30 ], 1 );
        $this->presets->save( 'ชั่วคราว', [ 'label_width' => 30 ], 2 );

        $this->presets->delete( 'ชั่วคราว' );

        $this->assertCount( 0, $this->presets->all() );

        foreach ( [ 1, 2 ] as $branchId ) {
            $this->assertNull(
                $this->presets->selectedFor( $branchId )[ 'name' ],
                'A branch is still pointing at a size that no longer exists, so it silently falls back'
                    . ' to the defaults with nothing said.'
            );
        }
    }

    public function test_a_size_must_be_named(): void
    {
        $this->expectException( NotAllowedException::class );

        $this->presets->save( '   ', [ 'label_width' => 40 ], 1 );
    }

    public function test_choosing_a_size_that_does_not_exist_is_refused(): void
    {
        $this->expectException( NotAllowedException::class );

        $this->presets->select( 'ไม่มีจริง', 1 );
    }

    // ------------------------------------------------------------------ through the API

    public function test_the_page_reads_and_writes_sizes_over_the_api(): void
    {
        $actor = $this->actor();

        DB::table( 'nexopos_users' )->where( 'id', $actor->id )->update( [ 'branch_id' => null ] );
        $actor->refresh();

        session( [ 'selected_branch_id' => 7 ] );

        $this->actingAs( $actor, 'web' )
            ->withSession( $this->app[ 'session' ]->all() )
            ->json( 'POST', '/api/products/label-presets', [
                'name' => 'ทดสอบผ่าน API',
                'settings' => [ 'label_width' => 40, 'label_height' => 20, 'columns' => 2, 'paper' => 'a5' ],
            ] )
            ->assertOk()
            ->assertJsonPath( 'status', 'success' )
            ->assertJsonPath( 'data.selected.name', 'ทดสอบผ่าน API' );

        $read = $this->actingAs( $actor, 'web' )
            ->withSession( $this->app[ 'session' ]->all() )
            ->json( 'GET', '/api/products/label-presets' );

        $read->assertOk()
            ->assertJsonPath( 'selected.name', 'ทดสอบผ่าน API' )
            ->assertJsonPath( 'selected.settings.paper', 'a5' );

        $this->assertSame(
            40.0,
            (float) $read->json( 'selected.settings.label_width' ),
            'the size the branch saved did not come back'
        );

        $this->actingAs( $actor, 'web' )
            ->withSession( $this->app[ 'session' ]->all() )
            ->json( 'DELETE', '/api/products/label-presets/' . rawurlencode( 'ทดสอบผ่าน API' ) )
            ->assertOk();

        $this->assertCount( 0, $this->presets->all() );
    }

    private function forgetPresets(): void
    {
        ns()->option->delete( LabelPresetService::PRESETS_OPTION );
        ns()->option->delete( LabelPresetService::SELECTION_OPTION );
    }

    private function actor(): User
    {
        if ( ! $this->actor instanceof User ) {
            $this->actor = $this->attemptGetAnyUserFromRole();
            $this->branchBefore = $this->actor->branch_id;
        }

        return $this->actor;
    }
}
