<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Bulk catalogue and stock imports rewrite tens of thousands of rows in one pass,
 * and a mistake in one of them is not something the application can undo. A dump
 * taken immediately before the write is the only way back, so the importers call
 * this command rather than trusting that the operator remembered to take one.
 */
class DatabaseBackupCommand extends Command
{
    protected $signature = 'db:backup
                            {--label= : Suffix added to the file name, to mark why the backup was taken}
                            {--keep=20 : How many backups to retain, oldest pruned first. 0 keeps everything}
                            {--mysqldump= : Explicit path to the mysqldump binary}';

    protected $description = 'Dump the database to storage/app/backups';

    /**
     * A dump that fails halfway still leaves a plausible-looking file behind. mysqldump
     * closes with this marker, so its presence is what separates a usable backup from a
     * truncated one.
     */
    private const DUMP_COMPLETION_MARKER = 'Dump completed on';

    public function handle(): int
    {
        $connectionName = config( 'database.default' );
        $connection = config( "database.connections.{$connectionName}" );

        if ( ( $connection[ 'driver' ] ?? null ) !== 'mysql' ) {
            $this->error( sprintf(
                'This command only handles mysql connections, "%s" uses "%s".',
                $connectionName,
                $connection[ 'driver' ] ?? 'unknown'
            ) );

            return self::FAILURE;
        }

        $binary = $this->resolveMysqldump();

        if ( $binary === null ) {
            $this->error( 'Could not find mysqldump or mariadb-dump.' );
            $this->line( 'Install the client tools, or point at the binary explicitly:' );
            $this->line( '  Debian/Ubuntu : apt install mariadb-client   (or mysql-client)' );
            $this->line( '  Then either   : php artisan db:backup --mysqldump=/usr/bin/mariadb-dump' );
            $this->line( '  Or persist it : BACKUP_MYSQLDUMP_PATH=/usr/bin/mariadb-dump in .env' );

            return self::FAILURE;
        }

        $this->line( 'Using: ' . $binary );

        $directory = storage_path( 'app/backups' );

        if ( ! File::isDirectory( $directory ) ) {
            File::makeDirectory( $directory, 0755, true );
        }

        $label = $this->option( 'label' );
        $suffix = empty( $label ) ? '' : '-' . preg_replace( '/[^A-Za-z0-9_-]/', '-', $label );
        $target = sprintf( '%s/%s-%s%s.sql', $directory, $connection[ 'database' ], now()->format( 'Y-m-d_His' ), $suffix );

        $this->info( 'Backing up "' . $connection[ 'database' ] . '"...' );

        $process = new Process( [
            $binary,
            '--host=' . ( $connection[ 'host' ] ?? '127.0.0.1' ),
            '--port=' . ( $connection[ 'port' ] ?? 3306 ),
            '--user=' . ( $connection[ 'username' ] ?? 'root' ),
            '--password=' . ( $connection[ 'password' ] ?? '' ),
            '--single-transaction',
            '--quick',
            '--routines',
            '--events',
            '--triggers',
            '--default-character-set=utf8mb4',
            '--result-file=' . $target,
            $connection[ 'database' ],
        ] );

        /**
         * A dump of a catalogue this size takes minutes, well past the default
         * 60 second process timeout.
         */
        $process->setTimeout( 3600 );
        $process->run();

        if ( ! $process->isSuccessful() ) {
            $this->error( 'mysqldump failed:' );
            $this->line( trim( $process->getErrorOutput() ) ?: trim( $process->getOutput() ) );

            if ( File::exists( $target ) ) {
                File::delete( $target );
                $this->warn( 'Removed the incomplete dump.' );
            }

            return self::FAILURE;
        }

        if ( ! $this->dumpIsComplete( $target ) ) {
            $this->error( 'The dump is missing its completion marker, so it is truncated. Removing it.' );
            File::delete( $target );

            return self::FAILURE;
        }

        $this->info( sprintf( 'Saved %s (%s)', $target, $this->humanFilesize( File::size( $target ) ) ) );

        $this->prune( $directory, (int) $this->option( 'keep' ) );

        return self::SUCCESS;
    }

    /**
     * Reads only the tail of the file, because these dumps are far too large to
     * pull into memory just to check how they end.
     */
    private function dumpIsComplete( string $path ): bool
    {
        if ( ! File::exists( $path ) || File::size( $path ) === 0 ) {
            return false;
        }

        $handle = fopen( $path, 'rb' );

        if ( $handle === false ) {
            return false;
        }

        $tailLength = min( 512, File::size( $path ) );
        fseek( $handle, -$tailLength, SEEK_END );
        $tail = (string) fread( $handle, $tailLength );
        fclose( $handle );

        return str_contains( $tail, self::DUMP_COMPLETION_MARKER );
    }

    private function resolveMysqldump(): ?string
    {
        $candidates = array_filter( [
            $this->option( 'mysqldump' ),
            env( 'BACKUP_MYSQLDUMP_PATH' ),
        ] );

        foreach ( $candidates as $candidate ) {
            if ( is_file( $candidate ) ) {
                return $candidate;
            }
        }

        /**
         * mysqldump refuses to dump a server newer than itself, and a machine with
         * both Laragon and XAMPP installed has two binaries of different vintages.
         * Preferring the one whose major version matches the running server avoids
         * picking the older of the two.
         */
        $discovered = $this->discoverMysqldumpBinaries();

        if ( $discovered === [] ) {
            return null;
        }

        $serverMajor = $this->serverMajorVersion();

        if ( $serverMajor !== null ) {
            foreach ( $discovered as $path ) {
                if ( $this->binaryMajorVersion( $path ) === $serverMajor ) {
                    return $path;
                }
            }
        }

        return $discovered[ 0 ];
    }

    /**
     * Windows and Linux need different lookups, and the dump binary is not always
     * called mysqldump: MariaDB renamed it to mariadb-dump and ships mysqldump only
     * as a compatibility symlink, which some builds omit.
     *
     * @return array<int, string>
     */
    private function discoverMysqldumpBinaries(): array
    {
        $onWindows = str_starts_with( strtoupper( PHP_OS_FAMILY ), 'WIN' );
        $names = $onWindows
            ? [ 'mysqldump.exe', 'mariadb-dump.exe' ]
            : [ 'mysqldump', 'mariadb-dump' ];

        $found = [];

        foreach ( $names as $name ) {
            $lookup = new Process( $onWindows ? [ 'where', $name ] : [ 'which', $name ] );
            $lookup->run();

            if ( ! $lookup->isSuccessful() ) {
                continue;
            }

            foreach ( preg_split( '/\r?\n/', trim( $lookup->getOutput() ) ) as $line ) {
                $line = trim( $line );

                if ( $line !== '' && is_file( $line ) ) {
                    $found[] = $line;
                }
            }
        }

        /**
         * Fall back to well known locations. On a developer machine the binary is
         * bundled with Laragon or XAMPP and never on PATH; on a server PATH is
         * usually enough, but a cron job runs with a minimal PATH that can miss
         * /usr/local/bin.
         */
        $directories = $onWindows
            ? [ 'C:/laragon/bin/mysql', 'C:/xampp/mysql/bin', 'C:/Program Files/MySQL', 'C:/Program Files/MariaDB' ]
            : [ '/usr/bin', '/usr/local/bin', '/usr/local/mysql/bin', '/opt/homebrew/bin' ];

        foreach ( $directories as $directory ) {
            if ( ! File::isDirectory( $directory ) ) {
                continue;
            }

            foreach ( $names as $name ) {
                /**
                 * Windows installs nest the binary under a version folder, so those
                 * are searched recursively. The Linux directories are flat, and
                 * walking /usr/bin recursively would be needlessly slow.
                 */
                if ( $onWindows ) {
                    foreach ( File::allFiles( $directory ) as $file ) {
                        if ( $file->getFilename() === $name ) {
                            $found[] = $file->getPathname();
                        }
                    }

                    continue;
                }

                $candidate = $directory . '/' . $name;

                if ( is_file( $candidate ) ) {
                    $found[] = $candidate;
                }
            }
        }

        return array_values( array_unique( $found ) );
    }

    private function serverMajorVersion(): ?string
    {
        try {
            $version = DB::selectOne( 'select version() as version' )->version ?? null;
        } catch ( \Throwable $exception ) {
            return null;
        }

        return $version === null ? null : $this->majorFrom( $version );
    }

    private function binaryMajorVersion( string $path ): ?string
    {
        $process = new Process( [ $path, '--version' ] );
        $process->run();

        if ( ! $process->isSuccessful() ) {
            return null;
        }

        return $this->majorFrom( $process->getOutput() );
    }

    private function majorFrom( string $text ): ?string
    {
        return preg_match( '/(\d+)\.\d+\.\d+/', $text, $matches ) === 1 ? $matches[ 1 ] : null;
    }

    private function prune( string $directory, int $keep ): void
    {
        if ( $keep <= 0 ) {
            return;
        }

        $backups = collect( File::files( $directory ) )
            ->filter( fn ( $file ) => $file->getExtension() === 'sql' )
            ->sortByDesc( fn ( $file ) => $file->getMTime() )
            ->values();

        if ( $backups->count() <= $keep ) {
            return;
        }

        $stale = $backups->slice( $keep );

        foreach ( $stale as $file ) {
            File::delete( $file->getPathname() );
        }

        $this->line( sprintf( 'Pruned %d old backup(s), kept the newest %d.', $stale->count(), $keep ) );
    }

    private function humanFilesize( int $bytes ): string
    {
        $units = [ 'B', 'KB', 'MB', 'GB' ];
        $index = 0;
        $size = (float) $bytes;

        while ( $size >= 1024 && $index < count( $units ) - 1 ) {
            $size /= 1024;
            $index++;
        }

        return round( $size, 2 ) . ' ' . $units[ $index ];
    }
}
