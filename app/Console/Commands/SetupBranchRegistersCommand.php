<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Register;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the tills a branch needs and assigns every till to a branch.
 *
 * NexoPOS refuses the POS screen to any cashier who does not hold an open till, and the
 * till picker previously listed every till in the system. The Tha Chang cashier was shown
 * the main branch till, tried to open it, and was refused because the administrator was
 * already holding it open.
 */
class SetupBranchRegistersCommand extends Command
{
    protected $signature = 'rgbf:setup-branch-registers
        {--branch= : Branch id the tills belong to}
        {--count=3 : How many tills the branch should have}
        {--name= : Base name, numbered when count is above one}
        {--author=1 : User id recorded as author}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Create tills for a branch and make sure every till is assigned to one.';

    public function handle(): int
    {
        if ( ! Schema::hasColumn( 'nexopos_registers', 'branch_id' ) ) {
            $this->error( 'ตาราง nexopos_registers ยังไม่มีคอลัมน์ branch_id — รัน migrate ก่อน' );

            return self::FAILURE;
        }

        $branchId = $this->option( 'branch' );

        if ( $branchId === null || $branchId === '' ) {
            $this->error( 'ต้องระบุ --branch=<id>' );

            return self::FAILURE;
        }

        $branchId = (int) $branchId;
        $branch   = Branch::find( $branchId );

        if ( $branch === null ) {
            $this->error( "ไม่พบสาขา id={$branchId}" );

            return self::FAILURE;
        }

        $count    = max( 1, (int) $this->option( 'count' ) );
        $authorId = (int) $this->option( 'author' );
        $dryRun   = (bool) $this->option( 'dry-run' );
        $baseName = (string) ( $this->option( 'name' ) ?: 'จุดคิดเงิน ' . $branch->name );

        $this->info( 'สาขา    : ' . $branch->name . " (id={$branchId})" );
        $this->info( "ต้องการ : {$count} จุด" );
        $this->info( 'โหมด    : ' . ( $dryRun ? 'ทดลอง' : 'เขียนข้อมูลจริง' ) );
        $this->newLine();

        $this->line( 'ลิ้นชักทั้งหมดก่อนดำเนินการ' );
        $this->render();

        $existing = Register::where( 'branch_id', $branchId )->count();

        if ( $existing >= $count ) {
            $this->info( "สาขานี้มีลิ้นชัก {$existing} จุดอยู่แล้ว ไม่ต้องสร้างเพิ่ม" );

            return self::SUCCESS;
        }

        $toCreate = $count - $existing;
        $this->warn( "จะสร้างเพิ่ม {$toCreate} จุด" );

        if ( $dryRun ) {
            for ( $i = 1; $i <= $toCreate; $i++ ) {
                $this->line( '  จะสร้าง: ' . $this->buildName( $baseName, $existing + $i, $count ) );
            }

            return self::SUCCESS;
        }

        for ( $i = 1; $i <= $toCreate; $i++ ) {
            $name = $this->buildName( $baseName, $existing + $i, $count );

            if ( Register::where( 'name', $name )->exists() ) {
                $this->line( "  ข้าม (มีชื่อนี้แล้ว): {$name}" );

                continue;
            }

            $register              = new Register;
            $register->name        = $name;
            $register->branch_id   = $branchId;
            $register->status      = Register::STATUS_CLOSED;
            $register->description = 'ลิ้นชักของ' . $branch->name;
            $register->used_by     = null;
            $register->balance     = 0;
            $register->author_id   = $authorId;
            $register->save();

            $this->info( "  สร้างแล้ว: {$name} (id={$register->id})" );
        }

        $this->newLine();
        $this->line( 'ลิ้นชักทั้งหมดหลังดำเนินการ' );
        $this->render();

        $orphans = Register::whereNull( 'branch_id' )->count();

        if ( $orphans > 0 ) {
            $this->warn( "ยังมีลิ้นชัก {$orphans} จุดที่ไม่ได้ผูกสาขา — จะไม่ปรากฏให้ผู้ใช้สาขาใดเห็น" );
        }

        return self::SUCCESS;
    }

    private function buildName( string $base, int $index, int $total ): string
    {
        return $total > 1 ? $base . ' ' . $index : $base;
    }

    private function render(): void
    {
        $rows = Register::orderBy( 'id' )
            ->get()
            ->map( static fn ( Register $r ): array => [
                $r->id,
                $r->name,
                $r->branch_id ?? 'ไม่ผูกสาขา',
                $r->status,
                $r->used_by ?? '-',
                number_format( (float) $r->balance, 2 ),
            ] )
            ->all();

        $this->table( [ 'id', 'ชื่อ', 'สาขา', 'สถานะ', 'ใช้โดย', 'ยอดเงิน' ], $rows );
    }
}
