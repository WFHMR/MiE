<?php

namespace App\Console\Commands;

use App\Models\Branch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lists and removes branches.
 *
 * Branches are referenced by registers, users, orders, stock rows and stock
 * history, all through a nullable branch_id. Deleting a branch that something
 * still points at does not fail: it leaves those rows pointing at an id that no
 * longer exists, and a stock row orphaned that way stops being readable by the
 * branch-aware queries in ProductService. So the delete refuses unless the branch
 * is genuinely unused, and says what is holding it.
 *
 * Usage:
 *   php artisan pos:branches
 *   php artisan pos:branches --delete=3 --delete=4
 *   php artisan pos:branches --delete=3 --dry-run
 */
class ManageBranchesCommand extends Command
{
    protected $signature = 'pos:branches
                            {--delete=* : Branch ids to remove}
                            {--dry-run : Report what would happen without writing}';

    protected $description = 'List branches, or remove ones that are not in use';

    public function handle(): int
    {
        if ( ! Schema::hasTable( 'nexopos_branches' ) ) {
            $this->error( 'This install has no branches table.' );

            return self::FAILURE;
        }

        $ids = array_filter( (array) $this->option( 'delete' ) );

        if ( $ids === [] ) {
            return $this->listBranches();
        }

        return $this->deleteBranches( array_map( 'intval', $ids ) );
    }

    private function listBranches(): int
    {
        $branches = Branch::orderBy( 'id' )->get();

        if ( $branches->isEmpty() ) {
            $this->info( 'No branches.' );

            return self::SUCCESS;
        }

        $this->info( sprintf( '%d branch(es):', $branches->count() ) );
        $this->newLine();

        foreach ( $branches as $branch ) {
            $usage = $this->usage( $branch );
            $flags = [];

            if ( $branch->is_main ) {
                $flags[] = 'main';
            }

            if ( $branch->is_main_warehouse ) {
                $flags[] = 'main warehouse';
            }

            if ( ! $branch->is_active ) {
                $flags[] = 'inactive';
            }

            $this->line( sprintf(
                '  id=%-4d %-24s %s',
                $branch->id,
                $branch->name,
                $flags === [] ? '' : '[' . implode( ', ', $flags ) . ']'
            ) );

            if ( $usage === [] ) {
                $this->line( '         not referenced by anything, safe to remove' );
            } else {
                foreach ( $usage as $what => $count ) {
                    $this->line( sprintf( '         %s: %d', $what, $count ) );
                }
            }
        }

        $this->newLine();
        $this->line( 'Remove one with:  php artisan pos:branches --delete=<id>' );
        $this->line( 'Check first with: php artisan pos:branches --delete=<id> --dry-run' );

        return self::SUCCESS;
    }

    /**
     * @param array<int, int> $ids
     */
    private function deleteBranches( array $ids ): int
    {
        $dryRun = (bool) $this->option( 'dry-run' );
        $problems = 0;
        $removable = [];

        foreach ( $ids as $id ) {
            $branch = Branch::find( $id );

            if ( ! $branch instanceof Branch ) {
                $this->error( sprintf( 'No branch with id %d.', $id ) );
                $problems++;

                continue;
            }

            if ( $branch->is_main ) {
                $this->error( sprintf( 'Branch %d ("%s") is the main branch and cannot be removed.', $id, $branch->name ) );
                $problems++;

                continue;
            }

            $usage = $this->usage( $branch );

            if ( $usage !== [] ) {
                $this->error( sprintf( 'Branch %d ("%s") is still in use:', $id, $branch->name ) );

                foreach ( $usage as $what => $count ) {
                    $this->line( sprintf( '  %s: %d', $what, $count ) );
                }

                $this->line( '  Move or clear those first. Nothing was deleted.' );
                $problems++;

                continue;
            }

            $removable[] = $branch;
        }

        if ( $problems > 0 ) {
            $this->newLine();
            $this->error( 'Refusing to delete anything while some of the requested branches cannot be removed.' );

            return self::FAILURE;
        }

        if ( $dryRun ) {
            $this->newLine();
            $this->warn( '[dry run] Would remove:' );

            foreach ( $removable as $branch ) {
                $this->line( sprintf( '  id=%d %s', $branch->id, $branch->name ) );
            }

            return self::SUCCESS;
        }

        foreach ( $removable as $branch ) {
            $name = $branch->name;
            $id = $branch->id;
            $branch->delete();
            $this->info( sprintf( 'Removed branch %d ("%s").', $id, $name ) );
        }

        return self::SUCCESS;
    }

    /**
     * Everything that would be left pointing at a missing branch.
     *
     * @return array<string, int>
     */
    private function usage( Branch $branch ): array
    {
        $checks = [
            'orders' => 'nexopos_orders',
            'registers' => 'nexopos_registers',
            'users' => 'nexopos_users',
            'stock rows' => 'nexopos_products_unit_quantities',
            'stock history' => 'nexopos_products_histories',
        ];

        $usage = [];

        foreach ( $checks as $label => $table ) {
            if ( ! Schema::hasTable( $table ) || ! Schema::hasColumn( $table, 'branch_id' ) ) {
                continue;
            }

            $count = DB::table( $table )->where( 'branch_id', $branch->id )->count();

            if ( $count > 0 ) {
                $usage[ $label ] = $count;
            }
        }

        return $usage;
    }
}
