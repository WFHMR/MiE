<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The backup command is the safety net for bulk catalogue and stock imports, so a
 * silent failure here is worse than no backup at all: the operator would proceed
 * with a destructive import believing they could roll it back.
 *
 * These cover the two ways it can fail without mysqldump ever running, both of
 * which must surface as a non-zero exit rather than a reassuring message.
 */
class DatabaseBackupCommandTest extends TestCase
{
    public function test_it_refuses_a_non_mysql_connection(): void
    {
        /**
         * The suite runs on sqlite, which mysqldump cannot read. The command has to
         * say so instead of producing an empty file named like a real backup.
         */
        $this->assertSame( 'sqlite', config( 'database.default' ) );

        $this->artisan( 'db:backup' )
            ->expectsOutputToContain( 'only handles mysql connections' )
            ->assertExitCode( 1 );
    }

    public function test_it_reports_a_missing_mysqldump_binary(): void
    {
        config()->set( 'database.default', 'backup-testing' );
        config()->set( 'database.connections.backup-testing', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'does-not-exist',
            'username' => 'root',
            'password' => '',
        ] );

        $this->artisan( 'db:backup', [ '--mysqldump' => storage_path( 'framework/testing/absent-mysqldump.exe' ) ] )
            ->assertFailed();
    }

    /**
     * Discovery was written against Windows only: it looked the binary up with
     * "where" and searched C:/xampp and C:/laragon. On the Linux server that found
     * nothing, so the scheduled backup reported "Could not find mysqldump" on a host
     * that had mariadb-dump sitting in /usr/bin the whole time.
     *
     * The failure was silent in the worst way: the hourly schedule kept running and
     * kept producing no backup.
     */
    public function test_it_finds_the_dump_binary_on_this_platform(): void
    {
        $command = new \App\Console\Commands\DatabaseBackupCommand;

        $discover = new \ReflectionMethod( $command, 'discoverMysqldumpBinaries' );
        $discover->setAccessible( true );

        /** @var array<int, string> $binaries */
        $binaries = $discover->invoke( $command );

        $expected = str_starts_with( strtoupper( PHP_OS_FAMILY ), 'WIN' )
            ? [ 'mysqldump.exe', 'mariadb-dump.exe' ]
            : [ 'mysqldump', 'mariadb-dump' ];

        if ( $binaries === [] ) {
            $this->markTestSkipped(
                'No dump binary on this machine, so discovery cannot be verified here. Install one of: ' . implode( ', ', $expected )
            );
        }

        foreach ( $binaries as $binary ) {
            $this->assertFileExists( $binary );
            $this->assertContains(
                basename( $binary ),
                $expected,
                'Discovery returned a path that is not one of the accepted dump binaries.'
            );
        }
    }

    public function test_it_does_not_leave_a_backup_behind_when_the_dump_cannot_run(): void
    {
        $directory = storage_path( 'app/backups' );
        $before = File::isDirectory( $directory )
            ? collect( File::files( $directory ) )->map( fn ( $file ) => $file->getFilename() )->all()
            : [];

        config()->set( 'database.default', 'backup-testing' );
        config()->set( 'database.connections.backup-testing', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'does-not-exist',
            'username' => 'root',
            'password' => '',
        ] );

        $this->artisan( 'db:backup', [ '--mysqldump' => storage_path( 'framework/testing/absent-mysqldump.exe' ) ] )
            ->assertFailed();

        $after = File::isDirectory( $directory )
            ? collect( File::files( $directory ) )->map( fn ( $file ) => $file->getFilename() )->all()
            : [];

        $this->assertSame(
            $before,
            $after,
            'A failed backup must not add a file, otherwise the newest "backup" would be unusable.'
        );
    }
}
