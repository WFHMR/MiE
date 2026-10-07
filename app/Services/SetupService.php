<?php

namespace App\Services;

use App\Events\UserAfterActivationSuccessfulEvent;
use App\Models\OrderPayment;
use App\Models\PaymentType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

class SetupService
{
    public Options $options;

    /**
     * Attempt database and save db informations
     *
     * @return void
     */
    public function saveDatabaseSettings( Request $request )
    {
        $databaseDriver = $request->input( 'database_driver' );

        config( [ 'database.connections.test' => [
            'driver' => $request->input( 'database_driver' ) ?: 'mysql',
            'host' => $request->input( 'hostname' ),
            'port' => $request->input( 'database_port' ) ?: env( 'DB_PORT', '3306' ),
            'database' => $request->input( 'database_driver' ) === 'sqlite' ? database_path( 'database.sqlite' ) : $request->input( 'database_name' ),
            'username' => $request->input( 'username' ),
            'password' => $request->input( 'password' ),
            'unix_socket' => env( 'DB_SOCKET', '' ),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => $request->input( 'database_prefix' ),
            'strict' => true,
            'engine' => null,
        ]] );

        try {
            DB::connection( 'test' )->getPdo();
        } catch ( \Exception $e ) {
            switch ( $e->getCode() ) {
                case 2002:
                    $message = [
                        'name' => 'hostname',
                        'message' => __( 'Unable to reach the host' ),
                        'status' => 'error',
                    ];
                    break;
                case 1045:
                    $message = [
                        'name' => 'username',
                        'message' => __( 'Unable to connect to the database using the credentials provided.' ),
                        'status' => 'error',
                    ];
                    break;
                case 1049:
                    $message = [
                        'name' => 'database_name',
                        'message' => __( 'Unable to select the database.' ),
                        'status' => 'error',
                    ];
                    break;
                case 1044:
                    $message = [
                        'name' => 'username',
                        'message' => __( 'Access denied for this user.' ),
                        'status' => 'error',
                    ];
                    break;
                case 1698:
                    $message = [
                        'name' => 'username',
                        'message' => __( 'Incorrect Authentication Plugin Provided.' ),
                        'status' => 'error',
                    ];
                    break;
                default:
                    $message = [
                        'name' => 'hostname',
                        'message' => $e->getMessage(),
                        'status' => 'error',
                    ];
                    break;
            }

            return response()->json( $message, 403 );
        }

        // we'll empty the database
        file_put_contents( database_path( 'database.sqlite' ), '' );

        $this->updateAppUrl();
        $this->updateAppDBConfiguration( $request->post() );

        /**
         * Link the resource storage
         */
        Artisan::call( 'storage:link', [ '--force' => true ] );

        return [
            'status' => 'success',
            'message' => __( 'The connexion with the database was successful' ),
        ];
    }

    /**
     * Records the URL the application was installed on.
     *
     * Both SESSION_DOMAIN and SANCTUM_STATEFUL_DOMAINS used to be pinned to the
     * host that happened to serve the installer. Move the app afterwards (staging
     * domain to local, or local to the real domain) and the cookie is scoped to a
     * host that no longer exists while Sanctum stops treating the dashboard as
     * stateful, so every /api/* call answers 401 "Unauthenticated." even though
     * the pages still render and the login succeeds.
     *
     * SESSION_DOMAIN is therefore left empty, which makes the browser scope the
     * cookie to whatever host is serving the request, and the stateful list leads
     * with Sanctum's current-host placeholder so it follows the app around.
     */
    public function updateAppURL()
    {
        $domain = parse_url( url()->to( '/' ) );

        $statefulDomains = collect( [
            Sanctum::$currentRequestHostPlaceholder,
            $domain[ 'host' ] . ( isset( $domain[ 'port' ] ) ? ':' . $domain[ 'port' ] : '' ),
            $domain[ 'host' ],
            'localhost',
            '127.0.0.1',
        ] )->filter()->unique()->join( ',' );

        ns()->envEditor->set( 'APP_URL', url()->to( '/' ) );
        ns()->envEditor->set( 'SESSION_DOMAIN', '' );
        ns()->envEditor->set( 'SANCTUM_STATEFUL_DOMAINS', $statefulDomains );

        ns()->envEditor->set( 'REVERB_APP_ID', 'app-key-' . Str::random( 10 ) );
        ns()->envEditor->set( 'REVERB_APP_KEY', 'app-key-' . Str::random( 10 ) );
        ns()->envEditor->set( 'REVERB_APP_SECRET', Str::uuid() );
    }

    public function updateAppDBConfiguration( $data )
    {
        ns()->envEditor->set( 'DB_CONNECTION', $data[ 'database_driver' ] );

        if ( $data[ 'database_driver' ] === 'sqlite' ) {
            ns()->envEditor->set( 'DB_DATABASE', database_path( 'database.sqlite' ) );
            ns()->envEditor->set( 'DB_PREFIX', $data[   'database_prefix' ] );
        } elseif ( $data[ 'database_driver' ] === 'mysql' ) {
            ns()->envEditor->set( 'DB_HOST', $data[ 'hostname' ] );
            ns()->envEditor->set(
                key: 'DB_DATABASE',
                value: $data[ 'database_name' ] ?: database_path( 'database.sqlite' ),
                quoted: true
            );
            ns()->envEditor->set( 'DB_USERNAME', $data[ 'username' ] );
            ns()->envEditor->set( 'DB_PASSWORD', $data[ 'password' ] );
            ns()->envEditor->set( 'DB_PREFIX', $data[   'database_prefix' ] );
            ns()->envEditor->set( 'DB_PORT', $data[ 'database_port' ] ?: 3306 );
        }
    }

    /**
     * Run migration
     *
     * @param Http Request
     * @return void
     */
    public function runMigration( $fields )
    {
        /**
         * We assume so far the application is installed
         * then we can launch option service
         */
        $configuredLanguage = $fields[ 'language' ] ?? 'en';

        App::setLocale( $configuredLanguage );

        /**
         * We're running this simple migration call to ensure
         * default tables are created. Those table are located at the
         * root of the database folder.
         */
        Artisan::call( 'migrate', [
            '--force' => true,
        ] );

        /**
         * RGBF uses Sanctum, we're making sure to publish the package.
         */
        Artisan::call( 'vendor:publish', [
            '--force' => true,
            '--provider' => 'Laravel\Sanctum\SanctumServiceProvider',
        ] );

        Artisan::call( 'ns:translate', [
            '--symlink' => true,
        ] );

        /**
         * we'll register all "update" migration
         * as already run as these migration are supposed
         * to be integrated on "create" files.
         */
        ns()->update
            ->getMigrations(
                directories: [ 'core', 'create' ],
                ignoreMigrations: true
            )
            ->each( function ( $file ) {
                ns()->update->executeMigrationFromFileName( $file );
            } );

        /**
         * The update migrations shouldn't be executed.
         * This should improve the speed during the installation.
         */
        ns()->update
            ->getMigrations(
                directories: [ 'update' ],
                ignoreMigrations: true
            )
            ->each( function ( $file ) {
                ns()->update->assumeExecuted( $file );
            } );

        /**
         * clear all cache
         */
        Artisan::call( 'cache:clear' );

        /**
         * From this moment, new permissions has been created.
         * However Laravel gates aren't aware of them. We'll fix this here.
         */
        ns()->registerGatePermissions();

        $userID = rand( 1, 99 );
        $user = new User;
        $user->id = $userID;
        $user->username = $fields[ 'admin_username' ];
        $user->password = Hash::make( $fields[ 'password' ] );
        $user->email = $fields[ 'admin_email' ];
        $user->author_id = $userID;
        $user->active = true; // first user active by default;
        $user->save();
        $user->assignRole( 'admin' );

        /**
         * define default user language
         */
        $user->attribute()->create( [
            'language' => $fields[ 'language' ] ?? 'en',
        ] );

        UserAfterActivationSuccessfulEvent::dispatch( $user );

        $this->createDefaultPayment( $user );
        $this->createDefaultAccounting();

        $this->options = app()->make( Options::class );
        $this->options->setDefault();
        $this->options->set( 'ns_store_language', $configuredLanguage );
        $this->options->set( 'ns_media_library_layout', 'modern' );

        return [
            'status' => 'success',
            'message' => __( 'RGBF has been successfully installed.' ),
        ];
    }

    public function createDefaultAccounting()
    {
        /**
         * @var TransactionService $service
         */
        $service = app()->make( TransactionService::class );
        $service->createDefaultAccounts();
    }

    /**
     * Creates the payment channels a Thai retail shop takes at the till.
     *
     * Ordered by priority so the payment popup is deterministic: the stock installer used
     * to leave all three defaults on priority 0, which left the ordering to whatever the
     * database returned first.
     *
     * The customer account channel is created inactive. It is still created, because the
     * refund path settles a refund to store credit through that identifier and
     * RegisterHistory rows resolve a payment type by identifier — a missing row would
     * break both. Inactive is enough to keep it off the till: OrdersController only sends
     * active types to the POS and OrdersService::__checkOrderPayments builds its whitelist
     * from PaymentType::active(). A shop that wants to sell on account can switch it back
     * on from the payment types screen.
     *
     * Kept in step with database/migrations/update/2026_09_08_000005_setup_thai_payment_types.php,
     * which does the same thing for an installation that already exists.
     */
    public function createDefaultPayment( $user )
    {
        $channels = [
            [
                'identifier' => OrderPayment::PAYMENT_CASH,
                'label' => __( 'เงินสด' ),
                'priority' => 0,
                'active' => true,
            ],
            [
                'identifier' => OrderPayment::PAYMENT_PROMPTPAY,
                'label' => __( 'พร้อมเพย์' ),
                'priority' => 1,
                'active' => true,
            ],
            [
                'identifier' => OrderPayment::PAYMENT_BANK,
                'label' => __( 'โอนเงินผ่านธนาคาร' ),
                'priority' => 2,
                'active' => true,
            ],
            [
                'identifier' => OrderPayment::PAYMENT_WELFARE_CARD,
                'label' => __( 'บัตรสวัสดิการแห่งรัฐ' ),
                'priority' => 3,
                'active' => true,
            ],
            [
                'identifier' => OrderPayment::PAYMENT_ACCOUNT,
                'label' => __( 'เครดิตลูกค้า' ),
                'priority' => 99,
                'active' => false,
            ],
        ];

        foreach ( $channels as $channel ) {
            $paymentType = new PaymentType;
            $paymentType->label = $channel[ 'label' ];
            $paymentType->identifier = $channel[ 'identifier' ];
            $paymentType->priority = $channel[ 'priority' ];
            $paymentType->active = $channel[ 'active' ];
            $paymentType->readonly = true;
            $paymentType->author_id = $user->id;
            $paymentType->save();
        }
    }

    public function testDBConnexion()
    {
        try {
            $DB = DB::connection( env( 'DB_CONNECTION', 'mysql' ) )->getPdo();

            return [
                'status' => 'success',
                'message' => __( 'Database connection was successful.' ),
            ];
        } catch ( \Exception $e ) {
            switch ( $e->getCode() ) {
                case 2002:
                    $message = [
                        'name' => 'hostname',
                        'message' => __( 'Unable to reach the host' ),
                        'status' => 'error',
                    ];
                    break;
                case 1045:
                    $message = [
                        'name' => 'username',
                        'message' => __( 'Unable to connect to the database using the credentials provided.' ),
                        'status' => 'error',
                    ];
                    break;
                case 1049:
                    $message = [
                        'name' => 'database_name',
                        'message' => __( 'Unable to select the database.' ),
                        'status' => 'error',
                    ];
                    break;
                case 1044:
                    $message = [
                        'name' => 'username',
                        'message' => __( 'Access denied for this user.' ),
                        'status' => 'error',
                    ];
                    break;
                case 1698:
                    $message = [
                        'name' => 'username',
                        'message' => __( 'Incorrect Authentication Plugin Provided.' ),
                        'status' => 'error',
                    ];
                    break;
                default:
                    $message = [
                        'name' => 'hostname',
                        'message' => $e->getMessage(),
                        'status' => 'error',
                    ];
                    break;
            }

            return response()->json( $message, 403 );
        }
    }
}
