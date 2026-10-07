<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * One inventory import at a time, whichever door it comes through.
 *
 * Every import path decides whether goods already exist by reading the catalogue and then writing
 * if it found nothing. That is safe on its own. It is not safe twice at once: two runs look up the
 * same barcode, neither has committed that row yet, so neither finds it, and both create the
 * product. Each copy then carries its own stock rows and the branch's stock is counted once per
 * copy.
 *
 * That is not hypothetical. On 2026-09-14 a large upload outlasted the browser's patience, nginx
 * recorded 499 twice while PHP carried on importing, the operator pressed the button again, and 40
 * of 686 products came out twice — 954 units of stock that never existed.
 *
 * The guard lived in the upload controller first. It is here now because the upload page is only
 * one of the doors: six console commands import as well, and a command run while someone is
 * uploading races in precisely the same way. Holding the name, the timeout and the wording in one
 * place is what makes the guard actually shared, rather than six copies that drift apart.
 *
 * The lock is deliberately not scoped to a branch. The lookup is by barcode and the catalogue is
 * shared, so two branches importing the same goods at once collide just as readily.
 */
class InventoryImportLock
{
    /**
     * Kept as the original upload-only name so a deploy cannot leave a run holding one name while
     * the next process checks another, which would open the very window this closes.
     */
    public const NAME = 'ns-inventory-upload';

    /**
     * A backstop for a worker killed mid import, not a budget for the import itself. Long enough
     * that a genuinely large file finishes first, short enough that a crash does not lock the shop
     * out for the rest of the day.
     */
    public const SECONDS = 900;

    /**
     * Takes the lock, or returns null if an import is already running.
     *
     * The caller owns the release. Returning the lock rather than a boolean is what makes that
     * possible: only the holder may release it, so a second caller cannot free someone else's run.
     */
    public function attempt(): ?Lock
    {
        $lock = Cache::lock( self::NAME, self::SECONDS );

        return $lock->get() ? $lock : null;
    }

    public function isHeld(): bool
    {
        $probe = Cache::lock( self::NAME, 1 );

        if ( ! $probe->get() ) {
            return true;
        }

        $probe->release();

        return false;
    }

    /**
     * Frees the lock without owning it, for a lock left behind by a run that no longer exists.
     *
     * Ordinary releases go through the holder, and deliberately so. But a worker can be killed
     * outright — the time limit exhausted, the process reloaded under it, the machine restarted —
     * and none of those run a finally block or a shutdown function. The lock then stands until it
     * expires, and the shop is told an import is running when nothing is.
     *
     * That happened on 2026-09-15: php-fpm was reloaded during an upload, the request died as a
     * 502, and the next two attempts were refused by a lock with nothing behind it.
     *
     * This is why the artisan command that calls it asks for confirmation. Used while an import is
     * genuinely running, it hands the shop straight back to the race this class exists to prevent.
     */
    public function forceRelease(): void
    {
        Cache::lock( self::NAME )->forceRelease();
    }

    /**
     * What the shop is told on the upload page.
     *
     * It says what to do, because the person who reaches this line is someone who pressed the
     * button twice after seeing nothing happen, and telling them the first run is still working is
     * the whole point.
     */
    public function messageForOperator(): string
    {
        return 'มีการนำเข้าไฟล์อีกรอบที่ยังทำงานอยู่ ระบบจึงยังไม่รับไฟล์นี้ '
            . 'ไฟล์ใหญ่ใช้เวลาหลายนาทีและหน้าเว็บอาจดูเหมือนค้างแม้ระบบยังทำงานอยู่ '
            . 'กรุณารอให้รอบก่อนเสร็จแล้วเปิดหน้ารายการสินค้าเพื่อตรวจผลก่อนอัปโหลดใหม่ '
            . 'อย่ากดอัปโหลดซ้ำระหว่างรอ เพราะจะทำให้สินค้าถูกสร้างซ้ำ';
    }

    /**
     * What a developer at a terminal is told. Shorter, and it names the lock so the state can be
     * inspected rather than guessed at.
     */
    public function messageForConsole(): string
    {
        return sprintf(
            'มีการนำเข้าอีกรอบที่ยังทำงานอยู่ (lock: %s) จึงไม่เริ่มรอบนี้ '
            . 'อาจเป็นการอัปโหลดจากหน้าเว็บที่ยังไม่เสร็จ รอให้จบก่อนแล้วรันใหม่ '
            . 'ถ้ามั่นใจว่าไม่มีอะไรทำงานอยู่ lock จะหมดอายุเองใน %d วินาที',
            self::NAME,
            self::SECONDS
        );
    }
}
