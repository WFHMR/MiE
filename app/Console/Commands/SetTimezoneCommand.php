<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Sets the timezone the application records everything in.
 *
 * AppServiceProvider reads this from the "ns_datetime_timezone" option and falls
 * back to Europe/London when it has never been set, so a fresh install silently
 * stamps every order, report and receipt with UK time. On a Thai till that is six
 * hours out in summer and seven in winter, which means an evening sale lands on the
 * wrong calendar day and the daily and VAT reports for that day are both wrong.
 *
 * The offset changing with UK daylight saving is what makes this worth a command
 * rather than a note: the error is not even constant, so it cannot be reasoned
 * about after the fact.
 *
 * Available through Settings > General > Date TimeZone as well. This exists so the
 * value can be set and verified over SSH during a deploy, before any trading has
 * happened.
 */
class SetTimezoneCommand extends Command
{
    protected $signature = 'pos:set-timezone
                            {timezone? : An identifier such as Asia/Bangkok. Omit to show the current one}
                            {--search= : List supported identifiers containing this text}';

    protected $description = 'Set the timezone used for orders, reports and receipts';

    public function handle(): int
    {
        /** @var array<string, string> $supported */
        $supported = config( 'nexopos.timezones', [] );

        $search = $this->option( 'search' );

        if ( ! empty( $search ) ) {
            return $this->listMatching( $supported, $search );
        }

        $timezone = $this->argument( 'timezone' );

        $current = ns()->option->get( 'ns_datetime_timezone' );

        $this->info( 'Current setting' );
        $this->line( '  ns_datetime_timezone : ' . ( $current ?: 'not set, falling back to Europe/London' ) );
        $this->line( '  app.timezone         : ' . config( 'app.timezone' ) );
        $this->line( '  time now             : ' . now()->format( 'Y-m-d H:i:s P' ) );
        $this->newLine();

        if ( $timezone === null ) {
            $this->line( 'Pass an identifier to change it, for example:' );
            $this->line( '  php artisan pos:set-timezone Asia/Bangkok' );
            $this->line( 'Or search the supported list:' );
            $this->line( '  php artisan pos:set-timezone --search=Bangkok' );

            return self::SUCCESS;
        }

        if ( ! array_key_exists( $timezone, $supported ) ) {
            $this->error( sprintf( '"%s" is not one of the supported identifiers.', $timezone ) );
            $this->line( 'Search for the right one with --search, e.g. --search=Bangkok' );

            return self::FAILURE;
        }

        ns()->option->set( 'ns_datetime_timezone', $timezone );

        $this->info( sprintf( 'Timezone set to %s', $timezone ) );
        $this->newLine();

        $this->warn( 'Records written before now still carry the old timezone. Nothing' );
        $this->warn( 'rewrites them, so check any orders taken before this change.' );
        $this->newLine();

        $this->line( 'Clear the caches so the running site picks this up:' );
        $this->line( '  php artisan config:clear && php artisan config:cache' );
        $this->newLine();
        $this->line( 'Then confirm with:' );
        $this->line( '  php artisan pos:set-timezone' );

        return self::SUCCESS;
    }

    /**
     * @param array<string, string> $supported
     */
    private function listMatching( array $supported, string $search ): int
    {
        $matches = array_filter(
            $supported,
            fn ( $label, $identifier ) => mb_stripos( $identifier . ' ' . $label, $search ) !== false,
            ARRAY_FILTER_USE_BOTH
        );

        if ( $matches === [] ) {
            $this->error( sprintf( 'Nothing supported matches "%s".', $search ) );

            return self::FAILURE;
        }

        $this->info( sprintf( '%d match(es):', count( $matches ) ) );

        foreach ( $matches as $identifier => $label ) {
            $this->line( sprintf( '  %-28s %s', $identifier, $label ) );
        }

        return self::SUCCESS;
    }
}
