<?php

namespace App\Console\Commands;

use App\Services\InventoryImportLock;
use Illuminate\Console\Command;

/**
 * Frees an inventory import lock that no run is behind any more.
 *
 * Deliberately does not use the LocksInventoryImport trait. A command whose job is to release the
 * lock cannot be one that refuses to start because the lock is held.
 *
 * The need is real rather than theoretical. A release normally happens in a finally block, and a
 * fatal error is covered by a shutdown function, but a worker killed by a signal runs neither. On
 * 2026-09-15 php-fpm was reloaded during an upload: the request died as a 502 and the operator's
 * next two attempts were refused by a lock with nothing behind it. Without this command the only
 * remedies were waiting a quarter of an hour or opening tinker on a production machine.
 */
class ReleaseInventoryImportLockCommand extends Command
{
    protected $signature = 'rgbf:import-unlock
        {--force : ปล่อยทันทีโดยไม่ถามยืนยัน สำหรับสคริปต์}';

    protected $description = 'ปล่อย lock การนำเข้าสินค้าที่ค้างอยู่ ใช้เมื่อไม่มีการนำเข้าทำงานอยู่จริง';

    public function handle( InventoryImportLock $lock ): int
    {
        if ( ! $lock->isHeld() ) {
            $this->info( 'ไม่มี lock ค้างอยู่ การนำเข้าพร้อมใช้งานได้ทันที' );

            return self::SUCCESS;
        }

        $this->warn( sprintf( 'พบ lock ค้างอยู่ (%s)', InventoryImportLock::NAME ) );

        /**
         * Said plainly, because releasing a lock that a real import is holding puts the shop back
         * into the duplicate-products race this lock exists to prevent.
         */
        $this->line( 'ถ้ามีการนำเข้าทำงานอยู่จริง การปล่อย lock จะทำให้เกิดการนำเข้าซ้อนกัน และสินค้าอาจถูกสร้างซ้ำ' );
        $this->line( 'ให้ตรวจก่อนว่าไม่มีใครกำลังอัปโหลดไฟล์ และไม่มีคำสั่งนำเข้าทำงานอยู่' );

        if ( ! $this->option( 'force' ) && ! $this->confirm( 'ยืนยันปล่อย lock ตอนนี้?', false ) ) {
            $this->info( 'ยกเลิก ไม่ได้ปล่อย lock' );

            return self::FAILURE;
        }

        $lock->forceRelease();

        if ( $lock->isHeld() ) {
            $this->error( 'ปล่อย lock ไม่สำเร็จ ยังมี lock ค้างอยู่' );

            return self::FAILURE;
        }

        $this->info( 'ปล่อย lock แล้ว การนำเข้าพร้อมใช้งาน' );

        return self::SUCCESS;
    }
}
