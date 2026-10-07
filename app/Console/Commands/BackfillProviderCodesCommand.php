<?php

namespace App\Console\Commands;

use App\Models\Provider;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Fills in the supplier code on suppliers that were created before the column existed.
 *
 * The importer built every supplier from the company name alone and never read the code column, so
 * the shop ended up with hundreds of suppliers identified only by long Thai names. The codes were
 * never lost — they are still in the catalogue the suppliers were imported from — so they are read
 * back out of that file and matched by name.
 *
 * Matching on the name is safe here, and only here, because the catalogue proves the two identify
 * each other: across the rows that carry a code there are as many distinct codes as distinct names,
 * with no code against two names and no name against two codes. That check is repeated at run time
 * and the command refuses to write anything if it ever stops holding.
 *
 * Nothing is overwritten. A supplier that already carries a code is left exactly as it is, which
 * makes the command safe to run again and safe to run after a partial run.
 */
class BackfillProviderCodesCommand extends Command
{
    protected $signature = 'providers:backfill-codes
                            {file : path to the catalogue csv that carries the supplier codes}
                            {--code-column=11 : zero based index of the supplier code column}
                            {--name-column=12 : zero based index of the company name column}
                            {--create-missing : also create the suppliers in the file that the database does not have}
                            {--dry-run : report what would change and write nothing}';

    protected $description = 'Fills the supplier code on suppliers imported before the code column existed, and can create the suppliers the catalogue lists but the database never got.';

    public function handle(): int
    {
        $file = (string) $this->argument( 'file' );

        if ( ! is_readable( $file ) ) {
            $this->error( "ไม่พบไฟล์ หรืออ่านไม่ได้: {$file}" );

            return self::FAILURE;
        }

        $codeColumn = (int) $this->option( 'code-column' );
        $nameColumn = (int) $this->option( 'name-column' );
        $dryRun = (bool) $this->option( 'dry-run' );
        $createMissing = (bool) $this->option( 'create-missing' );

        $this->line( 'อ่านไฟล์ : ' . basename( $file ) );
        $this->line( sprintf( 'คอลัมน์  : รหัส [%d] ชื่อ [%d]', $codeColumn, $nameColumn ) );

        $map = $this->readMapping( $file, $codeColumn, $nameColumn );

        if ( $map === null ) {
            return self::FAILURE;
        }

        $this->line( 'รหัสที่อ่านได้ : ' . count( $map ) );

        $result = $this->apply( $map, $dryRun );

        if ( $result === self::SUCCESS && $createMissing ) {
            $result = $this->createMissing( $map, $dryRun );
        }

        return $result;
    }

    /**
     * Creates the suppliers the catalogue lists that the database has never had.
     *
     * Run after the codes have been filled in, never before: a supplier already on the database
     * under a slightly different name is recognised by the code once the code is there, and creating
     * first would have added a second row for the same trading partner.
     *
     * A supplier is only created when neither its code nor its name is already present. Matching on
     * both matters — the code catches a supplier whose name was edited, the name catches one whose
     * code was filled in by hand — and either match means the shop already has that supplier.
     *
     * @param array<string, string> $map code => name
     */
    private function createMissing( array $map, bool $dryRun ): int
    {
        $author = User::whereHas( 'roles', fn ( $query ) => $query->where( 'namespace', Role::ADMIN ) )
            ->orderBy( 'id' )
            ->first();

        if ( ! $author instanceof User ) {
            $this->error( 'ไม่พบบัญชีผู้ดูแลระบบ จึงไม่มีผู้สร้างให้บันทึก' );

            return self::FAILURE;
        }

        /** Read once rather than per row: 726 lookups against 500+ rows is a needless round trip each. */
        $existingCodes = Provider::whereNotNull( 'code' )->pluck( 'code' )->all();
        $existingCodes = array_flip( array_map( 'strval', $existingCodes ) );

        $existingNames = [];

        foreach ( Provider::pluck( 'first_name' ) as $name ) {
            $existingNames[ $this->normalise( (string) $name ) ] = true;
        }

        $created = 0;
        $skippedByCode = 0;
        $skippedByName = 0;
        $samples = [];

        foreach ( $map as $code => $name ) {
            $code = (string) $code;

            if ( isset( $existingCodes[ $code ] ) ) {
                $skippedByCode++;

                continue;
            }

            if ( isset( $existingNames[ $this->normalise( $name ) ] ) ) {
                $skippedByName++;

                continue;
            }

            if ( ! $dryRun ) {
                $provider = new Provider;
                /**
                 * Truncated to the same lengths the column and the importer use, so a long registered
                 * name cannot fail the insert halfway through the run.
                 */
                $provider->code = mb_substr( $code, 0, 50 );
                $provider->first_name = mb_substr( $name, 0, 190 );
                $provider->description = 'สร้างจากตารางรหัสผู้จัดหา';
                $provider->author_id = $author->id;
                $provider->save();
            }

            if ( count( $samples ) < 10 ) {
                $samples[] = sprintf( '%-12s %s', $code, $name );
            }

            $created++;
        }

        $this->newLine();
        $this->line( '--- สร้างผู้จัดหาที่ยังไม่มี ---' );
        $this->line( 'สร้างใหม่               : ' . $created . ( $dryRun ? '  (ทดลอง ยังไม่เขียน)' : '' ) );
        $this->line( 'มีรหัสนี้อยู่แล้ว ข้ามไป : ' . $skippedByCode );
        $this->line( 'มีชื่อนี้อยู่แล้ว ข้ามไป  : ' . $skippedByName );

        if ( $samples !== [] ) {
            $this->newLine();
            $this->line( 'ตัวอย่างที่' . ( $dryRun ? 'จะ' : '' ) . 'สร้าง:' );

            foreach ( $samples as $sample ) {
                $this->line( '  ' . $sample );
            }
        }

        return self::SUCCESS;
    }

    /**
     * Builds the code => name mapping, and refuses to hand back anything if the file does not
     * actually identify one supplier per code.
     *
     * @return array<string, string>|null
     */
    private function readMapping( string $file, int $codeColumn, int $nameColumn ): ?array
    {
        $handle = fopen( $file, 'r' );

        if ( $handle === false ) {
            $this->error( 'เปิดไฟล์ไม่ได้' );

            return null;
        }

        $codeToNames = [];
        $nameToCodes = [];

        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            $code = $this->cell( $row[ $codeColumn ] ?? null );
            $name = $this->cell( $row[ $nameColumn ] ?? null );

            /**
             * The title row, the header row and the totals row all fail this, which is why the
             * header is never located: a row is taken seriously only when the code column holds
             * something shaped like a code.
             */
            if ( ! preg_match( '/^SP/iu', $code ) || $name === '' ) {
                continue;
            }

            $codeToNames[ $code ][ $name ] = true;
            $nameToCodes[ $name ][ $code ] = true;
        }

        fclose( $handle );

        if ( $codeToNames === [] ) {
            $this->error( 'ไม่พบรหัสที่ขึ้นต้นด้วย SP เลย อาจระบุหมายเลขคอลัมน์ผิด' );

            return null;
        }

        $codeClashes = array_filter( $codeToNames, fn( array $names ): bool => count( $names ) > 1 );
        $nameClashes = array_filter( $nameToCodes, fn( array $codes ): bool => count( $codes ) > 1 );

        if ( $codeClashes !== [] || $nameClashes !== [] ) {
            $this->error( 'ไฟล์นี้จับคู่รหัสกับชื่อไม่ตรงตัวต่อตัว จึงไม่เติมรหัสให้เพื่อกันข้อมูลสลับกัน' );

            foreach ( array_slice( $codeClashes, 0, 5, true ) as $code => $names ) {
                $this->line( "  รหัส {$code} ชี้ไปหลายชื่อ: " . implode( ' | ', array_keys( $names ) ) );
            }

            foreach ( array_slice( $nameClashes, 0, 5, true ) as $name => $codes ) {
                $this->line( "  ชื่อ {$name} มีหลายรหัส: " . implode( ', ', array_keys( $codes ) ) );
            }

            return null;
        }

        $map = [];

        foreach ( $codeToNames as $code => $names ) {
            $map[ (string) $code ] = (string) array_key_first( $names );
        }

        return $map;
    }

    /**
     * @param array<string, string> $map code => name
     */
    private function apply( array $map, bool $dryRun ): int
    {
        /** Keyed on the collapsed name: the spreadsheet holds double spaces the importer kept. */
        $byName = [];

        foreach ( $map as $code => $name ) {
            $byName[ $this->normalise( $name ) ] = $code;
        }

        $filled = 0;
        $alreadySet = 0;
        $noCode = [];
        $taken = [];

        foreach ( Provider::orderBy( 'id' )->cursor() as $provider ) {
            if ( $provider->code !== null && $provider->code !== '' ) {
                $alreadySet++;

                continue;
            }

            $key = $this->normalise( (string) $provider->first_name );
            $code = $byName[ $key ] ?? null;

            if ( $code === null ) {
                $noCode[ $provider->id ] = (string) $provider->first_name;

                continue;
            }

            /**
             * The column is unique, so a code already sitting on another supplier would make the
             * write fail. Reported instead, because two suppliers answering to one code is a
             * question about the data rather than something to resolve automatically.
             */
            $owner = Provider::where( 'code', $code )->first();

            if ( $owner instanceof Provider ) {
                $taken[] = sprintf( '%s -> id %d (%s) ถือรหัสนี้อยู่แล้ว', $code, $owner->id, $owner->first_name );

                continue;
            }

            if ( ! $dryRun ) {
                $provider->code = $code;
                $provider->save();
            }

            $filled++;
        }

        $this->newLine();
        $this->line( 'เติมรหัสได้            : ' . $filled . ( $dryRun ? '  (ทดลอง ยังไม่เขียน)' : '' ) );
        $this->line( 'มีรหัสอยู่แล้ว ข้ามไป  : ' . $alreadySet );
        $this->line( 'ไม่พบรหัสในไฟล์        : ' . count( $noCode ) );
        $this->line( 'รหัสถูกใช้โดยรายอื่น    : ' . count( $taken ) );

        if ( $noCode !== [] ) {
            $this->newLine();
            $this->line( 'รายที่ไม่มีรหัสในไฟล์ (ต้องกรอกเองถ้าต้องการ):' );

            foreach ( array_slice( $noCode, 0, 20, true ) as $id => $name ) {
                $this->line( sprintf( '  id %-5d %s', $id, $name ) );
            }

            if ( count( $noCode ) > 20 ) {
                $this->line( '  ... และอีก ' . ( count( $noCode ) - 20 ) . ' ราย' );
            }
        }

        foreach ( $taken as $line ) {
            $this->warn( '  ' . $line );
        }

        return self::SUCCESS;
    }

    private function cell( mixed $value ): string
    {
        return trim( (string) $value );
    }

    private function normalise( string $value ): string
    {
        return trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
    }
}
