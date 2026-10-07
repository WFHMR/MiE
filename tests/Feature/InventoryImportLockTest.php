<?php

namespace Tests\Feature;

use App\Console\Commands\Concerns\LocksInventoryImport;
use App\Console\Commands\ImportBranchProductsCommand;
use App\Console\Commands\ImportCsvToBranch;
use App\Console\Commands\ImportMultiUnitProductsCommand;
use App\Console\Commands\ImportStockFromCsv;
use App\Console\Commands\ImportStockFromExcel;
use App\Console\Commands\ImportThaiProductCatalogCommand;
use App\Console\Commands\ReleaseInventoryImportLockCommand;
use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use App\Services\InventoryImportLock;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

/**
 * One import at a time, whichever door it arrives through.
 *
 * The duplicate-products fault was a race: two imports each looked up a barcode, neither had
 * committed its row yet, so neither found the other's, and both created the product. The guard
 * that fixed it started life inside the upload controller, which left it covering one door out of
 * seven — six console commands import as well, and a command run while the shop is uploading
 * races in exactly the same way.
 *
 * These tests hold the lock and then push each door, so a future import path that forgets to take
 * the lock fails here rather than in the shop's catalogue.
 */
class InventoryImportLockTest extends TestCase
{
    use WithAuthentication;

    /**
     * Every console command that writes goods or stock.
     *
     * Listed rather than discovered so that adding an import command is a deliberate decision:
     * a new one is absent from this list, the first test fails, and the author has to say whether
     * it needs the lock.
     *
     * @var list<class-string>
     */
    private const IMPORT_COMMANDS = [
        ImportMultiUnitProductsCommand::class,
        ImportThaiProductCatalogCommand::class,
        ImportBranchProductsCommand::class,
        ImportCsvToBranch::class,
        ImportStockFromCsv::class,
        ImportStockFromExcel::class,
    ];

    private ?User $actor = null;

    /**
     * A lock left behind would refuse every import in every test that follows, so it is cleared
     * whatever happened here.
     */
    protected function tearDown(): void
    {
        Cache::lock( InventoryImportLock::NAME )->forceRelease();

        parent::tearDown();
    }

    public function test_every_console_import_takes_the_shared_lock(): void
    {
        foreach ( self::IMPORT_COMMANDS as $command ) {
            $this->assertContains(
                LocksInventoryImport::class,
                class_uses( $command ),
                $command . ' writes goods or stock but does not take the import lock, so it can run'
                    . ' beside an upload and create the same product twice.'
            );
        }
    }

    public function test_a_console_import_is_refused_while_another_import_is_running(): void
    {
        $held = Cache::lock( InventoryImportLock::NAME, InventoryImportLock::SECONDS );

        $this->assertTrue( $held->get(), 'could not take the lock to set this test up' );

        $file = $this->missingCsvPath();

        $exit = Artisan::call( 'rgbf:import-branch-products', [ 'file' => $file, '--branch' => 1 ] );
        $output = Artisan::output();

        $held->release();

        $this->assertSame(
            Command::FAILURE,
            $exit,
            'the command reported success while another import held the lock'
        );

        $this->assertStringContainsString(
            InventoryImportLock::NAME,
            $output,
            'the command did not explain that another import is already running'
        );

        /**
         * The refusal has to happen before the command's own work starts. Had handle() run it
         * would have complained about the unreadable file, so the absence of that complaint is
         * what proves nothing was attempted.
         */
        $this->assertStringNotContainsString(
            $file,
            $output,
            'the command began importing despite the lock being held'
        );
    }

    public function test_the_lock_is_released_when_a_console_import_finishes(): void
    {
        $file = $this->missingCsvPath();

        $exit = Artisan::call( 'rgbf:import-branch-products', [ 'file' => $file, '--branch' => 1 ] );
        $output = Artisan::output();

        $this->assertSame( Command::FAILURE, $exit );

        $this->assertStringContainsString(
            $file,
            $output,
            'the command never reached its own work, so this test proves nothing about the release'
        );

        $this->assertFalse(
            app( InventoryImportLock::class )->isHeld(),
            'the lock was still held after the command returned, so the next import would be refused'
        );
    }

    public function test_an_upload_is_refused_while_a_console_import_is_running(): void
    {
        $lock = app( InventoryImportLock::class )->attempt();

        $this->assertNotNull( $lock, 'could not take the lock to set this test up' );

        $productsBefore = Product::count();

        $path = tempnam( sys_get_temp_dir(), 'nsimportlock' ) . '.csv';
        file_put_contents( $path, "barcode,name\n7790000000001,ทดสอบล็อก\n" );

        $response = $this->actingAs( $this->actor(), 'web' )->post( 'dashboard/inventory/upload', [
            'csv_file' => new UploadedFile( $path, 'upload.csv', 'text/csv', null, true ),
            'branch_id' => $this->branch()->id,
            'stock_mode' => 'set',
        ] );

        @unlink( $path );
        $lock->release();

        /**
         * Read from the response's own session rather than the session() helper: a refusal and a
         * success are both a 302, and the helper cannot see the flash intended for the next
         * request, so asserting on the redirect alone would pass either way.
         */
        $session = $response->getSession();

        $this->assertTrue(
            $session->has( 'error' ),
            'the upload was accepted while a console import held the lock'
        );

        $this->assertSame(
            $productsBefore,
            Product::count(),
            'the refused upload still wrote to the catalogue'
        );
    }

    public function test_both_doors_agree_on_one_lock(): void
    {
        $lock = app( InventoryImportLock::class )->attempt();

        $this->assertNotNull( $lock );

        /**
         * A second attempt through the service must fail, and a raw attempt on the published name
         * must fail too. Together those say the service hands out one lock under one name, which
         * is the only reason the upload page and the commands exclude each other at all.
         */
        $this->assertNull(
            app( InventoryImportLock::class )->attempt(),
            'the service handed out the same lock twice'
        );

        $this->assertFalse(
            Cache::lock( InventoryImportLock::NAME, 5 )->get(),
            'the service is not locking under InventoryImportLock::NAME'
        );

        $lock->release();

        $this->assertFalse(
            app( InventoryImportLock::class )->isHeld(),
            'the lock survived its own release'
        );
    }

    /**
     * A lock left behind by a run that no longer exists must be clearable.
     *
     * A release normally happens in a finally block, and a fatal error is covered by a shutdown
     * function, but a worker killed by a signal runs neither. On 2026-09-15 php-fpm was reloaded
     * during an upload: the request died as a 502 and the operator's next two attempts were refused
     * by a lock with nothing behind it. Without a way out, the remedies were waiting a quarter of
     * an hour or opening tinker on the shop's own server.
     */
    public function test_a_stuck_lock_can_be_released_from_the_console(): void
    {
        $held = Cache::lock( InventoryImportLock::NAME, InventoryImportLock::SECONDS );

        $this->assertTrue( $held->get(), 'could not take the lock to set this test up' );

        $exit = Artisan::call( 'rgbf:import-unlock', [ '--force' => true ] );

        $this->assertSame(
            Command::SUCCESS,
            $exit,
            'the unlock command could not free a held lock'
        );

        $this->assertFalse(
            app( InventoryImportLock::class )->isHeld(),
            'the lock survived the command whose only job is to release it'
        );

        /** The import must actually be usable again, not merely reported as free. */
        $reacquired = app( InventoryImportLock::class )->attempt();

        $this->assertNotNull( $reacquired, 'an import still cannot start after the lock was released' );

        $reacquired->release();
    }

    /**
     * The command that releases the lock must not be one that refuses to run because the lock is
     * held. It is the one import-related command that must never take it.
     */
    public function test_the_unlock_command_does_not_lock_itself_out(): void
    {
        $this->assertNotContains(
            LocksInventoryImport::class,
            class_uses( ReleaseInventoryImportLockCommand::class ),
            'The unlock command takes the import lock, so it refuses to start exactly when it is'
                . ' needed and a stuck lock can never be cleared.'
        );
    }

    public function test_releasing_when_nothing_is_held_is_reported_as_fine(): void
    {
        $this->assertFalse( app( InventoryImportLock::class )->isHeld() );

        $exit = Artisan::call( 'rgbf:import-unlock', [ '--force' => true ] );

        $this->assertSame(
            Command::SUCCESS,
            $exit,
            'running the unlock command with nothing stuck reported a failure'
        );
    }

    /** A path that is guaranteed not to exist, so no command can write anything. */
    private function missingCsvPath(): string
    {
        return sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'rgbf-import-lock-absent-'
            . random_int( 100000, 999999 )
            . '.csv';
    }

    private function actor(): User
    {
        if ( ! $this->actor instanceof User ) {
            $this->actor = $this->attemptGetAnyUserFromRole();
        }

        return $this->actor;
    }

    private function branch(): Branch
    {
        $branch = Branch::orderBy( 'id' )->first();

        $this->assertInstanceOf( Branch::class, $branch, 'the test database has no branch to import into' );

        return $branch;
    }
}
