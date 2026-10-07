<?php

namespace Tests\Feature;

use App\Enums\NotificationsEnum;
use App\Jobs\CheckTaskSchedulingConfigurationJob;
use App\Services\Options;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The standing "Task Scheduling Disabled" warning.
 *
 * CoreService::checkTaskSchedulingConfiguration() raises that notification whenever
 * ns_jobs_last_activity is missing or older than an hour. Nothing ever wrote that option: the
 * job responsible for it was not scheduled, and its body was skipped entirely when
 * QUEUE_CONNECTION was "sync". The result on the live installation was a permanent warning in
 * the administrator's notifications and roughly a thousand log lines a day, on a system whose
 * scheduling was in fact working.
 */
class TaskSchedulingNoticeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_handling_the_job_records_that_jobs_run(): void
    {
        $options = app()->make( Options::class );
        $options->delete( 'ns_jobs_last_activity' );

        ( new CheckTaskSchedulingConfigurationJob )->handle();

        $this->assertNotEmpty(
            app()->make( Options::class )->get( 'ns_jobs_last_activity' ),
            'Reaching the job proves dispatching works, so the activity has to be recorded.'
        );
    }

    /**
     * The guard that used to skip the body read QUEUE_CONNECTION, so the fix has to hold on a
     * sync connection, which is what this installation runs.
     */
    public function test_it_records_activity_on_a_sync_connection(): void
    {
        config( [ 'queue.default' => 'sync' ] );

        $options = app()->make( Options::class );
        $options->delete( 'ns_jobs_last_activity' );

        ( new CheckTaskSchedulingConfigurationJob )->handle();

        $this->assertNotEmpty( app()->make( Options::class )->get( 'ns_jobs_last_activity' ) );
    }

    public function test_handling_the_job_clears_the_standing_warning(): void
    {
        DB::table( 'nexopos_notifications' )->insert( [
            'title' => 'Task Scheduling Disabled',
            'identifier' => NotificationsEnum::NSWORKERDISABLED,
            'description' => 'test row',
            'source' => 'system',
            'url' => '#',
            'dismissable' => true,
            'user_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ] );

        $this->assertGreaterThan(
            0,
            DB::table( 'nexopos_notifications' )->where( 'identifier', NotificationsEnum::NSWORKERDISABLED )->count()
        );

        ( new CheckTaskSchedulingConfigurationJob )->handle();

        $this->assertSame(
            0,
            DB::table( 'nexopos_notifications' )->where( 'identifier', NotificationsEnum::NSWORKERDISABLED )->count(),
            'The warning survived a job that proves scheduling works.'
        );
    }

    /**
     * The job has to be on the schedule, or nothing keeps the record fresh and the warning
     * comes back an hour later.
     */
    public function test_the_job_is_on_the_schedule(): void
    {
        $schedule = app()->make( Schedule::class );

        $found = collect( $schedule->events() )->contains(
            fn ( $event ): bool => str_contains( $event->getSummaryForDisplay(), 'CheckTaskSchedulingConfigurationJob' )
                || str_contains( (string) $event->description, 'CheckTaskSchedulingConfigurationJob' )
        );

        $this->assertTrue( $found, 'CheckTaskSchedulingConfigurationJob is not scheduled.' );
    }
}
