<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception;

class MemberImportService
{
    public function branches()
    {
        return Branch::active()->when( ! ns()->hasRole( 'admin' ), fn ( $q ) => $q->where( 'id', auth()->user()->branch_id ?: -1 ) );
    }

    public function authorizeBranch( int $branch ): void
    {
        abort_unless( $this->branches()->whereKey( $branch )->exists(), 403, 'ไม่มีสิทธิ์ใช้งานสาขานี้' );
    }

    public function read( string $path, string $extension ): array
    {
        $reader = IOFactory::createReader( match ( strtolower( $extension ) ) {
            'xlsx' => 'Xlsx', 'xls' => 'Xls', 'csv' => 'Csv',
            default => throw ValidationException::withMessages( ['file' => 'รองรับ Excel (.xlsx/.xls) และ CSV เท่านั้น'] ),
        } );
        try {
            $book = $reader->load( $path );
        } catch ( Exception $exception ) {
            throw ValidationException::withMessages( ['file' => 'อ่านไฟล์ไม่ได้ กรุณาบันทึกเป็น Excel หรือ CSV ใหม่แล้วลองอีกครั้ง'] );
        }
        try {
            $sheet = $book->getSheet( 0 );
            if ( $sheet->getHighestDataRow() > 5001 || Coordinate::columnIndexFromString( $sheet->getHighestDataColumn() ) > 26 ) {
                throw ValidationException::withMessages( ['file' => 'นำเข้าได้ไม่เกิน 5,000 รายการและ 26 คอลัมน์ต่อไฟล์'] );
            }

            // Never calculate formulas supplied in an uploaded workbook.
            return $sheet->toArray( null, false, true, false );
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function normalize( array $rows ): array
    {
        $aliases = [
            'name' => ['name', 'ชื่อ', 'ชื่อสกุล', 'ชื่อ-นามสกุล', 'ชื่อ นามสกุล', 'ชื่อลูกค้า', 'ชื่อสมาชิก'],
            'phone' => ['phone', 'เบอร์โทร', 'เบอร์โทรศัพท์', 'โทรศัพท์', 'เบอร์โทรศัพท์มือถือ'],
            'code' => ['member_code', 'รหัสสมาชิก', 'รหัสลูกค้า'],
            'points' => ['points', 'คะแนน', 'คะแนนสะสม'],
        ];
        $header = null;
        foreach ( array_slice( $rows, 0, 20, true ) as $index => $row ) {
            $map = [];
            foreach ( $row as $column => $label ) {
                $label = trim( str_replace( "\xEF\xBB\xBF", '', (string) $label ) );
                foreach ( $aliases as $key => $names ) {
                    if ( in_array( $label, $names, true ) ) {
                        $map[$key] = $column;
                    }
                }
            }
            if ( isset( $map['name'], $map['phone'] ) ) {
                $header = $index;
                break;
            }
        }
        if ( $header === null ) {
            throw ValidationException::withMessages( ['file' => 'ไม่พบหัวคอลัมน์ ชื่อ และ เบอร์โทร กรุณาใช้ไฟล์ตัวอย่าง'] );
        }
        $result = [];
        $phones = $codes = [];
        foreach ( array_slice( $rows, $header + 1, null, true ) as $index => $row ) {
            if ( count( array_filter( $row, fn ( $v ) => trim( (string) $v ) !== '' ) ) === 0 ) {
                continue;
            }
            $name = trim( (string) ( $row[$map['name']] ?? '' ) );
            $phone = preg_replace( '/[\s\-()]/u', '', trim( (string) ( $row[$map['phone']] ?? '' ) ) );
            $code = isset( $map['code'] ) ? trim( (string) ( $row[$map['code']] ?? '' ) ) : '';
            $points = isset( $map['points'] ) ? str_replace( ',', '', trim( (string) ( $row[$map['points']] ?? '' ) ) ) : '0';
            $points = $points === '' ? '0' : $points;
            $errors = [];
            if ( $name === '' || mb_strlen( $name ) > 180 || preg_match( '/^[=+@]/', $name ) ) {
                $errors[] = 'ชื่อไม่ถูกต้อง';
            }
            if ( ! preg_match( '/^0[0-9]{8,9}$/D', $phone ) ) {
                $errors[] = 'เบอร์โทรต้องมีเลข 0 นำหน้าและมี 9–10 หลัก (ตั้งช่อง Excel เป็นข้อความ)';
            }
            if ( $code !== '' && ( ! preg_match( '/^[\pL\pN_\-]+$/u', $code ) || mb_strlen( $code ) > 80 ) ) {
                $errors[] = 'รหัสสมาชิกไม่ถูกต้อง';
            }
            if ( ! ctype_digit( $points ) || (float) $points > 100000000 ) {
                $errors[] = 'คะแนนต้องเป็นจำนวนเต็มตั้งแต่ 0 ถึง 100,000,000';
            }
            if ( isset( $phones[$phone] ) || ( $code !== '' && isset( $codes[$code] ) ) ) {
                $errors[] = 'เบอร์โทรหรือรหัสสมาชิกซ้ำในไฟล์';
            }
            $phones[$phone] = true;
            if ( $code !== '' ) {
                $codes[$code] = true;
            }
            $result[] = ['line' => $index + 1, 'name' => $name, 'phone' => $phone, 'code' => $code, 'points' => (int) $points, 'errors' => $errors];
        }
        if ( ! $result || count( $result ) > 5000 ) {
            throw ValidationException::withMessages( ['file' => 'ต้องมีข้อมูล 1–5,000 รายการ'] );
        }

        return $result;
    }

    public function check( array $rows, int $branch ): array
    {
        foreach ( $rows as &$row ) {
            $matches = Customer::where( 'phone', $row['phone'] )->get();
            $row['customer_id'] = null;
            if ( $matches->count() > 1 ) {
                $row['errors'][] = 'พบลูกค้าเดิมใช้เบอร์นี้มากกว่าหนึ่งราย ต้องตรวจสอบก่อน';
            } elseif ( $matches->count() === 1 ) {
                $customer = $matches->first();
                $names = [trim( $customer->first_name . ' ' . $customer->last_name )];
                if ( ! in_array( $row['name'], $names, true ) ) {
                    $row['errors'][] = 'เบอร์โทรตรงกับลูกค้าเดิมแต่ชื่อต่างกัน ต้องตรวจสอบก่อน';
                } else {
                    $row['customer_id'] = $customer->id;
                }
            }
            $account = DB::table( 'nexopos_loyalty_accounts' )->where( function ( $q ) use ( $row ): void {
                $q->where( 'customer_id', $row['customer_id'] ?? 0 );
                if ( $row['code'] !== '' ) {
                    $q->orWhere( 'member_code', $row['code'] );
                }
            } )->first();
            if ( $account ) {
                $row['errors'][] = 'มีสมาชิกหรือรหัสนี้ในระบบแล้ว ใช้คะแนนร่วมทุกสาขา ไม่บวกคะแนนตั้งต้นซ้ำ';
            }
        }

        return $rows;
    }

    public function preview( array $raw, int $branch, int $group ): string
    {
        $this->authorizeBranch( $branch );
        CustomerGroup::findOrFail( $group );
        $rows = $this->check( $this->normalize( $raw ), $branch );
        if ( collect( $rows )->contains( fn ( $row ) => $row['points'] > 0 ) ) {
            ns()->restrict( 'nexopos.update.rewards' );
        }
        $id = (string) Str::uuid();
        DB::table( 'nexopos_member_imports' )->insert( ['id' => $id, 'branch_id' => $branch, 'group_id' => $group, 'author_id' => auth()->id(), 'rows' => json_encode( $rows, JSON_UNESCAPED_UNICODE ), 'status' => 'preview', 'created_at' => now(), 'updated_at' => now()] );

        return $id;
    }

    public function confirm( string $id ): int
    {
        return DB::transaction( function () use ( $id ): int {
            $batch = DB::table( 'nexopos_member_imports' )->where( 'id', $id )->lockForUpdate()->firstOrFail();
            abort_unless( (int) $batch->author_id === auth()->id(), 403 );
            $this->authorizeBranch( (int) $batch->branch_id );
            if ( $batch->status === 'confirmed' ) {
                return count( json_decode( $batch->rows, true ) );
            }
            if ( now()->diffInHours( $batch->created_at, true ) > 24 ) {
                throw ValidationException::withMessages( ['file' => 'พรีวิวหมดอายุ กรุณาอัปโหลดใหม่'] );
            }
            CustomerGroup::findOrFail( $batch->group_id );
            // Serialize imports, including ones targeting different branches sharing a customer.
            DB::table( 'nexopos_roles' )->where( 'namespace', Role::STORECUSTOMER )->lockForUpdate()->firstOrFail();
            $rows = $this->check( json_decode( $batch->rows, true ), (int) $batch->branch_id );
            if ( collect( $rows )->contains( fn ( $row ) => $row['points'] > 0 ) ) {
                ns()->restrict( 'nexopos.update.rewards' );
            }
            if ( collect( $rows )->contains( fn ( $row ) => count( $row['errors'] ) > 0 ) ) {
                throw ValidationException::withMessages( ['file' => 'พบข้อมูลผิดหรือซ้ำ ยังไม่นำเข้าแม้แต่รายการเดียว กรุณาแก้ไฟล์แล้วพรีวิวใหม่'] );
            }
            foreach ( $rows as $row ) {
                $customerId = $row['customer_id'];
                if ( ! $customerId ) {
                    $customerId = app( CustomerService::class )->create( [
                        'first_name' => $row['name'], 'last_name' => '', 'phone' => $row['phone'],
                        'email' => 'member-' . Str::uuid() . '@import.invalid', 'username' => 'member-' . Str::uuid(),
                        'password' => bcrypt( Str::random( 64 ) ), 'group_id' => $batch->group_id, 'active' => true, 'address' => [],
                    ] )['data']['customer']->id;
                }
                app( BranchLoyaltyService::class )->lockCustomer( (int) $customerId );
                if ( DB::table( 'nexopos_loyalty_accounts' )->where( 'customer_id', $customerId )->exists() ) {
                    throw ValidationException::withMessages( ['file' => 'สมาชิกนี้ลงทะเบียนแล้วระหว่างตรวจข้อมูล กรุณาพรีวิวใหม่'] );
                }
                $account = DB::table( 'nexopos_loyalty_accounts' )->insertGetId( ['branch_id' => $batch->branch_id, 'customer_id' => $customerId, 'member_code' => $row['code'] ?: null, 'points' => $row['points'], 'created_at' => now(), 'updated_at' => now()] );
                DB::table( 'nexopos_loyalty_entries' )->insert( ['account_id' => $account, 'branch_id' => $batch->branch_id, 'shared_balance' => $row['points'], 'reference' => 'import:' . $id . ':' . $row['line'], 'points' => $row['points'], 'balance' => $row['points'], 'description' => 'คะแนนตั้งต้นจากการนำเข้าสมาชิก', 'author_id' => auth()->id(), 'created_at' => now()] );
            }
            DB::table( 'nexopos_member_imports' )->where( 'id', $id )->update( ['status' => 'confirmed', 'confirmed_at' => now(), 'updated_at' => now()] );

            return count( $rows );
        } );
    }
}
