<?php

namespace App\Console\Commands\Concerns;

use App\Services\InventoryImportLock;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Makes a console import take the same lock the upload page takes.
 *
 * Without it the guard is only half a guard: the upload page is locked, but a command run while
 * someone is uploading reads the catalogue, finds nothing, and creates the goods a second time —
 * the same race, through a different door.
 *
 * This wraps execute() rather than handle() on purpose. handle() is where each command's work
 * lives, and those bodies are long and return from several places; wrapping them would mean
 * restructuring six of them and hoping every exit still released the lock. execute() is the one
 * funnel every command already passes through, so the release sits in a single finally that cannot
 * be bypassed, and the commands themselves keep their bodies exactly as they were.
 *
 * A trait may call parent:: — it resolves against the class using the trait, which here is always
 * an Illuminate console command.
 */
trait LocksInventoryImport
{
    protected function execute( InputInterface $input, OutputInterface $output ): int
    {
        $importLock = app( InventoryImportLock::class );
        $lock = $importLock->attempt();

        /**
         * Refused rather than queued. An import can take minutes and a command left waiting looks
         * indistinguishable from one that has hung, which is the confusion that caused the
         * duplicates in the first place.
         */
        if ( $lock === null ) {
            $this->error( $importLock->messageForConsole() );

            return self::FAILURE;
        }

        try {
            return parent::execute( $input, $output );
        } finally {
            $lock->release();
        }
    }
}
