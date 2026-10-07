<?php

namespace App\Services;

use App\Exceptions\CoreException;
use App\Exceptions\NotAllowedException;
use App\Models\Role;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MarketplaceService
{
    public function __construct(
        protected ModulesService $moduleService
    ) {}

    /**
     * This method will return the list of modules available in the marketplace, it will also handle the authentication
     * if we have a valid access token and refresh it if it's expired
     */
    public function getModules( array $data = [] ): array
    {
        /**
         * The marketplace is an optional integration. When it isn't configured we
         * return an empty result set so the listing keeps its contract instead of
         * failing the whole request.
         */
        if ( ! $this->isConfigured() ) {
            return $this->emptyModulesResult( (int) ( $data[ 'per_page' ] ?? 12 ) );
        }

        $queryParams = [
            'per_page' => $data[ 'per_page' ] ?? 12,
            'page' => $data[ 'page' ] ?? 1,
            'type' => 'zip',
        ];

        if ( isset( $data['categories'] ) ) {
            $queryParams['categories'] = $data['categories'];
        }

        if ( isset( $data['search'] ) ) {
            $queryParams['search'] = $data['search'];
        }

        $request = Http::accept( 'application/json' );

        /**
         * if we have access and refresh token, we'll test the token if that works if it's not the case we'll try to refresh it if the refresh fails
         * we'll just return the public modules without the ones that are only available for authenticated users
         */
        $this->authenticateRequest( $request );

        $response = $request->accept( 'application/json' )
            ->withoutVerifying()
            ->get( $this->marketplaceDomain() . '/api/nexoplatform/marketplace/items?' . http_build_query( $queryParams ) );

        if ( $response->failed() ) {
            throw new CoreException( $response->json( 'message' ) ?: __( 'Failed to retrieve modules from marketplace.' ) );
        }

        /**
         * We'll indicate if the latest version is the vers that is currently installed.
         * This will also enable/disable the installation button.
         */
        $response = $response->json();

        foreach ( $response[ 'data' ] as &$module ) {
            $installedModule = $this->moduleService->get( $module[ 'namespace' ] );

            if ( $installedModule ) {
                $module[ 'is_installed' ] = true;
                $module[ 'is_up_to_date' ] = version_compare( $installedModule[ 'version' ], $module[ 'latest_version' ][ 'version' ], '>=' );
            } else {
                $module[ 'is_installed' ] = false;
                $module[ 'is_up_to_date' ] = false;
            }
        }

        return $response;
    }

    /**
     * This method will check if we have a valid access token and if it's not expired,
     * if it's expired it will try to refresh it using the refresh token,
     * if the refresh token is also expired or invalid it will just return without authenticating the request
     */
    public function authenticateRequest(
        PendingRequest $request,
        string $method = '',
        string $endpoint = '',
        array $body = []
    ): void {
        $accessToken = ns()->option->get( 'mynexopos_access_token' );
        $refreshToken = ns()->option->get( 'mynexopos_refresh_token' );
        $tokenExpiresAt = ns()->option->get( 'mynexopos_token_expires_in' );

        $identity = ns()->getIdentity();

        $installationId = $identity['installation_id'] ?? '';
        $timestamp = now()->timestamp;
        $nonce = bin2hex( random_bytes( 32 ) );

        /**
         * Important:
         * For GET requests, the body should be empty.
         * For POST/PUT/PATCH requests, encode the body exactly the same way
         * you will send it using ->withBody().
         */
        $jsonBody = empty( $body )
            ? ''
            : json_encode( $body, JSON_UNESCAPED_SLASHES );

        $bodyHash = hash( 'sha256', $jsonBody );

        $privateKey = base64_decode(
            decrypt( $identity['private_key'] ?? '' )
        );

        /**
         * This replaces your old:
         *
         * $message = json_encode($identity, JSON_UNESCAPED_SLASHES);
         *
         * The old message only proved the installation identity.
         * This new canonical message proves the exact request.
         */
        $message = implode( "\n", [
            strtoupper( $method ),
            '/' . ltrim( $endpoint, '/' ),
            $timestamp,
            $nonce,
            $installationId,
            $bodyHash,
        ] );

        $signature = base64_encode(
            sodium_crypto_sign_detached( $message, $privateKey )
        );

        $request->withHeader( 'X-NEXOPOS-DOMAIN', $identity['domain'] ?? request()->getHost() );
        $request->withHeader( 'X-NEXOPOS-INSTALLATION-ID', $installationId );
        $request->withHeader( 'X-NEXOPOS-FINGERPRINT', $identity['fingerprint'] ?? '' );
        $request->withHeader( 'X-NEXOPOS-TIMESTAMP', $timestamp );
        $request->withHeader( 'X-NEXOPOS-NONCE', $nonce );
        $request->withHeader( 'X-NEXOPOS-BODY-SHA256', $bodyHash );
        $request->withHeader( 'X-NEXOPOS-SIGNATURE', $signature );

        if ( env( 'APP_ENV' ) === 'local' ) {
            $request->withoutVerifying();
        }

        if (
            $accessToken &&
            $refreshToken &&
            $tokenExpiresAt &&
            Carbon::now()->lessThan( Carbon::parse( $tokenExpiresAt ) )
        ) {
            $request->withToken( $accessToken );
        } elseif ( $refreshToken ) {
            try {
                $this->refreshAccessToken( $request, $refreshToken );
            } catch ( CoreException $e ) {
                // If refreshing the token fails, we can choose to log the error
                // or ignore it and proceed without authentication.
            }
        }
    }

    /**
     * This method will try to refresh the access token using the refresh token, if it fails it will throw an exception.
     */
    public function refreshAccessToken( PendingRequest $request, string $refreshToken ): void
    {
        $response = $request->accept( 'application/json' )
            ->withoutVerifying()
            ->post( $this->marketplaceDomain() . '/oauth/token', [
                'grant_type' => 'refresh_token',
                'client_id' => env( 'MARKETPLACE_CLIENT_ID', '019eac04-114e-711d-8433-2cde59066bad' ),
                'refresh_token' => $refreshToken,
            ] );

        if ( $response->failed() ) {
            throw new CoreException( __( 'Failed to refresh access token.' ) );
        }

        ns()->option->set( 'mynexopos_access_token', $response->json( 'access_token' ) );
        ns()->option->set( 'mynexopos_refresh_token', $response->json( 'refresh_token' ) );
        ns()->option->set( 'mynexopos_token_type', $response->json( 'token_type' ) );
        ns()->option->set( 'mynexopos_token_expires_in', Carbon::now()->addSeconds( $response->json( 'expires_in' ) ) );

        $request->withToken( $response->json( 'access_token' ) );
    }

    /**
     * This method will redirect the user to the marketplace authorization page,
     * it will generate a code verifier and code challenge for PKCE authentication and store the code verifier
     * and state in the session for later use in the callback.
     */
    public function authorize(): Redirector|RedirectResponse
    {
        /**
         * Enrolling against an unconfigured marketplace would send the user to a
         * broken URL. We send them back with an explicit reason instead.
         */
        if ( ! $this->isConfigured() ) {
            return redirect()
                ->route( 'ns.dashboard.modules-marketplace' )
                ->with( 'error', $this->notConfiguredMessage() );
        }

        $identity = ns()->getIdentity();

        $codeVerifier = Str::random( 96 );

        $codeChallenge = rtrim( strtr(
            base64_encode( hash( 'sha256', $codeVerifier, true ) ),
            '+/',
            '-_'
        ), '=' );

        $state = Str::random( 64 );

        Session::put( 'mynexopos_oauth_state', $state );
        Session::put( 'mynexopos_code_verifier', $codeVerifier );

        return redirect()->away( $this->marketplaceDomain() . '/oauth/nexopos/enroll?' . http_build_query( [
            'domain' => request()->getHost(),
            'client_id' => env( 'MARKETPLACE_CLIENT_ID', '019eac04-114e-711d-8433-2cde59066bad' ),
            'scope' => 'read-profile read-licenses update-licenses manage-cart download-products',

            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',

            'public_key' => $identity[ 'public_key' ],
            'installation_id' => $identity[ 'installation_id' ],
            'fingerprint' => $identity[ 'fingerprint' ],

            'state' => $state,
            'callback_url' => route( ns()->routeName( 'ns.oauth.mynexopos.callback' ) ),
        ] ) );
    }

    /**
     * This method will handle the callback from the marketplace after the user has authorized the application,
     * it will validate the provided code and state, then it will request the access token using
     * the provided code and code_verification.
     */
    public function handleCallback( array $data = [] ): Redirector|RedirectResponse
    {
        /**
         * we now need to request the access token using the provided code and code_verification
         */
        if ( $data[ 'state' ] !== Session::get( 'mynexopos_oauth_state' ) ) {
            throw new NotAllowedException( __( 'Invalid state parameter.' ) );
        }

        $request = Http::withHeader( 'X-NEXOPOS-DOMAIN', request()->getHost() );

        if ( env( 'APP_ENV' ) === 'local' ) {
            $request->withoutVerifying();
        }

        $response = $request->accept( 'application/json' )
            ->withoutVerifying()
            ->post( $this->marketplaceDomain() . '/oauth/token', [
                'grant_type' => 'authorization_code',
                'client_id' => env( 'MARKETPLACE_CLIENT_ID', '019eac04-114e-711d-8433-2cde59066bad' ),
                'code' => $data[ 'code' ],
                'code_verifier' => Session::get( 'mynexopos_code_verifier' ),
                'redirect_uri' => $this->marketplaceDomain() . '/oauth/nexopos/callback',
            ] );

        if ( $response->failed() ) {
            throw new CoreException( __( 'Failed to retrieve access token.' ) );
        }

        /**
         * We now need to store the access token and refresh token in the options table
         */
        ns()->option->set( 'mynexopos_access_token', $response->json( 'access_token' ) );
        ns()->option->set( 'mynexopos_refresh_token', $response->json( 'refresh_token' ) );
        ns()->option->set( 'mynexopos_token_type', $response->json( 'token_type' ) );
        ns()->option->set( 'mynexopos_token_expires_in', Carbon::now()->addSeconds( $response->json( 'expires_in' ) ) );

        /**
         * if the user was invited to authenticate from a specific page,
         * we should redirect him back to this page after authentication.
         */
        if ( session()->has( 'marketplace_auth_redirect_url' ) ) {
            $redirectUrl = session()->pull( 'marketplace_auth_redirect_url' );

            return redirect( $redirectUrl )->with( 'success', __( 'Successfully connected to My RGBF.' ) );
        }

        return redirect( route( ns()->routeName( 'ns.dashboard.modules-marketplace' ) ) )->with( 'success', __( 'Successfully connected to My RGBF.' ) );
    }

    public function addToCart( int|string $productId )
    {
        $request = Http::accept( 'application/json' );

        $payload = [
            'product_id' => $productId,
            'quantity' => 1,
            'meta' => [],
        ];

        $endpoint = '/api/nexoplatform/oauth/user/cart/items';

        $this->authenticateRequest(
            request: $request,
            method: 'POST',
            endpoint: $endpoint,
            body: $payload
        );

        /**
         * We might need to check if the item is already added to the cart. If it's the case
         * we'll just foward the user to the card for the payment.
         */
        $cartCheck = $this->hasItemOnCart( $request, $productId );

        if ( isset( $cartCheck[ 'data' ][ 'hasItem' ] ) && $cartCheck[ 'data' ][ 'hasItem' ] ) {
            return [
                'status' => 'success',
                'message' => __( 'Item is already on the cart.' ),
                'data' => [
                    'action' => 'already-on-cart',
                    'redirect' => $cartCheck[ 'data' ][ 'cartUrl' ],
                ],
            ];
        }

        $response = $request->accept( 'application/json' )
            ->withoutVerifying()
            ->withBody( json_encode( $payload, JSON_UNESCAPED_SLASHES ), 'application/json' )
            ->post( $this->marketplaceDomain() . $endpoint );

        if ( $response->failed() ) {
            throw new CoreException( $response->json( 'message' ) ?: __( 'Failed to add item to cart.' ) );
        }

        return [
            'status' => 'success',
            'message' => __( 'Item added to cart successfully.' ),
            'data' => [
                'action' => 'added-to-cart',
                'redirect' => $response->json( 'data.cartUrl' ),
            ],
        ];
    }

    /**
     * Will check if the item is already
     * on the user cart to prevent multiple addition.
     */
    public function hasItemOnCart( PendingRequest $request, $productId ): array
    {
        $request = Http::accept( 'application/json' );

        $payload = [
            'product_id' => $productId,
        ];

        $endpoint = '/api/nexoplatform/oauth/user/cart/has-item';

        $this->authenticateRequest(
            request: $request,
            method: 'POST',
            endpoint: $endpoint,
            body: $payload
        );

        $response = $request->withBody( json_encode( $payload, JSON_UNESCAPED_SLASHES ), 'application/json' )
            ->post( $this->marketplaceDomain() . $endpoint );

        if ( $response->failed() ) {
            throw new CoreException( $response->json( 'message' ) ?: __( 'Failed to retrieve cart information.' ) );
        }

        return $response->json();
    }

    public function getLicenses( int|string $itemId ): Collection
    {
        $request = Http::accept( 'application/json' );

        $this->authenticateRequest( $request );

        $response = $request->accept( 'application/json' )
            ->withoutVerifying()
            ->get( $this->marketplaceDomain() . '/api/nexoplatform/oauth/user/licenses/for-product/' . $itemId );

        if ( $response->failed() ) {
            throw new CoreException( $response->json( 'message' ) ?: __( 'Failed to retrieve licenses.' ) );
        }

        /**
         * we need to check for each available check the version that
         * can be downloaded for the current license
         */
        return collect( $response->json() )->map( function ( $license ) {
            return $license;
        } );
    }

    public function downloadModule( int|string $itemId, string $licenseId )
    {
        $request = Http::accept( 'application/json' );

        $payload = [
            'product_id' => $itemId,
            'license_id' => $licenseId,
        ];

        $this->authenticateRequest(
            request: $request,
            method: 'POST',
            endpoint: '/api/nexoplatform/oauth/user/download',
            body: $payload
        );

        $response = $request->accept( 'application/json' )
            ->withoutVerifying()
            ->withBody( json_encode( $payload, JSON_UNESCAPED_SLASHES ), 'application/json' )
            ->post( $this->marketplaceDomain() . '/api/nexoplatform/oauth/user/download', $payload );

        if ( $response->failed() ) {
            throw new CoreException( $response->json( 'message' ) ?: __( 'Failed to download module.' ) );
        }

        /**
         * at this point the $response should be the binary content of the module zip file.
         * We need to store it on a temporary directory and upload it.
         */
        Storage::disk( 'ns-modules-temp' )->put( $itemId . '-' . $licenseId . '.zip', $response->body() );

        $uploadFile = new UploadedFile(
            Storage::disk( 'ns-modules-temp' )->path( $itemId . '-' . $licenseId . '.zip' ),
            $itemId . '-' . $licenseId . '.zip',
            'application/zip',
            null,
            true
        );

        return $this->moduleService->upload( $uploadFile );
    }

    public function testConnection()
    {
        /**
         * Not configured isn't an authentication failure, so we bail out quietly
         * rather than spamming administrators with a notification.
         */
        if ( ! $this->isConfigured() ) {
            return false;
        }

        try {
            $request = Http::accept( 'application/json' );

            $this->authenticateRequest( $request );

            $response = $request->accept( 'application/json' )
                ->withToken( ns()->option->get( 'mynexopos_access_token' ) )
                ->get( $this->marketplaceDomain() . '/api/nexoplatform/oauth/user' );

            /**
             * The request failed. We need to inform the user of what might have
             * happened, this gives more details than just redirecting with no clear guidance.
             */
            if ( $response->failed() ) {
                $parsedResponse = $this->parseHttpErrorResponse( $response );

                ns()->option->delete( 'mynexopos_access_token' );
                ns()->option->delete( 'mynexopos_refresh_token' );

                ns()->notification->create(
                    title: __( 'My RGBF: Authentication Failed' ),
                    description: sprintf( __( 'We\'re unable to retrieve the user details from my.nexopos.com. %s.' ), $parsedResponse ),
                    url: route( 'ns.dashboard.modules-list' ),
                    identifier: 'marketplace.authentication.feedback'
                )->dispatchForGroup( Role::ADMIN );

                return false;
            }

            return true;
        } catch ( Exception $exception ) {
            ns()->notification->create(
                title: __( 'My RGBF: Authentication Failed' ),
                description: sprintf( __( 'An unexpected error occured: %s.' ), $exception->getMessage() ),
                url: route( 'ns.dashboard.modules-list' ),
                identifier: 'marketplace.authentication.feedback'
            )->dispatchForGroup( Role::ADMIN );

            return false;
        }
    }

    private function parseHttpErrorResponse( Response $response )
    {
        $body = trim( $response->body() );

        if ( $body === '' ) {
            return 'HTTP request failed with status ' . $response->status();
        }

        $json = $response->json();

        if ( is_array( $json ) ) {
            return $json['message']
                ?? $json['error']
                ?? $json['detail']
                ?? data_get( $json, 'errors.0' )
                ?? json_encode( $json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        }

        if ( is_string( $json ) ) {
            return $json;
        }

        return $body;
    }

    /**
     * Returns the configured marketplace base URL.
     *
     * Every call used to fall back to "#" when MARKETPLACE_DOMAIN was unset, which
     * produced URLs such as "#/api/nexoplatform/..." and made Guzzle raise
     * 'The scheme "" is not allowed by the protocols request option', surfacing as
     * a 500 on the marketplace pages. A missing optional integration should report
     * itself clearly instead.
     *
     * @throws NotAllowedException when the marketplace has not been configured
     */
    protected function marketplaceDomain(): string
    {
        $domain = $this->resolveMarketplaceDomain();

        if ( $domain === null ) {
            throw new NotAllowedException( $this->notConfiguredMessage() );
        }

        return $domain;
    }

    /**
     * Whether the marketplace can be reached at all.
     *
     * Lets callers skip the feature gracefully rather than catching an exception.
     */
    public function isConfigured(): bool
    {
        return $this->resolveMarketplaceDomain() !== null;
    }

    /**
     * Normalizes MARKETPLACE_DOMAIN, returning null when it cannot produce a
     * usable absolute URL.
     */
    private function resolveMarketplaceDomain(): ?string
    {
        $domain = trim( (string) env( 'MARKETPLACE_DOMAIN', '' ) );

        if ( $domain === '' || ! preg_match( '#^https?://\S+$#i', $domain ) ) {
            return null;
        }

        return rtrim( $domain, '/' );
    }

    private function notConfiguredMessage(): string
    {
        return __( 'The marketplace is not configured. Please set MARKETPLACE_DOMAIN on the .env file using a full URL, such as "https://my.nexopos.com".' );
    }

    /**
     * Mirrors the paginated payload the marketplace returns so the front-end
     * keeps working when the integration is unavailable.
     *
     * @return array{data: array, meta: array{links: array, current_page: int, last_page: int, per_page: int, total: int}, message: string}
     */
    private function emptyModulesResult( int $perPage ): array
    {
        return [
            'data' => [],
            'meta' => [
                'links' => [],
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => $perPage,
                'total' => 0,
            ],
            'message' => $this->notConfiguredMessage(),
        ];
    }

    public function getCategories()
    {
        /**
         * Same rationale as getModules(): no marketplace means no categories.
         */
        if ( ! $this->isConfigured() ) {
            return [];
        }

        $request = Http::accept( 'application/json' );

        $this->authenticateRequest( $request );

        $response = $request->accept( 'application/json' )
            ->withoutVerifying()
            ->get( $this->marketplaceDomain() . '/api/nexoplatform/marketplace/categories' );

        if ( $response->failed() ) {
            throw new CoreException( $response->json( 'message' ) ?: __( 'Failed to retrieve categories from marketplace.' ) );
        }

        return $response->json();
    }
}
