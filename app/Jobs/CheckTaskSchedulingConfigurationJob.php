<?php

namespace App\Jobs;

use App\Enums\NotificationsEnum;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckTaskSchedulingConfigurationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    /**
     * Reaching this method is itself the proof the check is looking for: a job that is being
     * handled means dispatching works, so the warning about task scheduling can go.
     *
     * The body used to be skipped whenever QUEUE_CONNECTION was "sync", which defeated the
     * purpose. On a sync connection a dispatched job runs inline, so scheduled work does
     * execute as long as cron is alive — but ns_jobs_last_activity was never written, so
     * CoreService::checkTaskSchedulingConfiguration() found it missing on every request and
     * raised "Task Scheduling Disabled" again. That produced a standing warning in the
     * administrator's notifications and around a thousand log lines a day on an installation
     * whose scheduling was in fact working.
     */
    public function handle()
    {
        /**
         * @var NotificationService
         */
        $notification = app()->make( NotificationService::class );
        $notification->deleteHavingIdentifier( NotificationsEnum::NSWORKERDISABLED );

        ns()->option->set( 'ns_jobs_last_activity', ns()->date->toDateTimeString() );
    }
}
