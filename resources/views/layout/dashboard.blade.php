<?php
use App\Models\UserAttribute;
use App\Services\DateService;
use App\Services\MenuService;
use Illuminate\Support\Facades\Auth;

/**
 * @var MenuService $menus
 */
$menus  =   app()->make( MenuService::class );

/**
 * @var MenuService $menus
 */
$dateService  =   app()->make( DateService::class );

/**
 * A user has no attribute row until one is created for them, and reading the theme off
 * null aborts the whole dashboard with "Attempt to read property theme on null". Newly
 * created accounts hit this on their first sign in. layout/base.blade.php already guards
 * the same read, so this matches it.
 */
if ( Auth::check() && Auth::user()->attribute instanceof UserAttribute ) {
    $theme  =   Auth::user()->attribute->theme ?: ns()->option->get( 'ns_default_theme', 'light' );
} else {
    $theme  =   ns()->option->get( 'ns_default_theme', 'light' );
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="{{ $theme }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ App\Services\Helper::pageTitle( $title ?? __( 'Unnamed Page' ) ) }}</title>
    @include( 'layout._header-injection')
    @vite([
        'resources/scss/line-awesome/1.3.0/scss/line-awesome.scss',
        'resources/css/animations.css',
        'resources/css/fonts.css',
        'resources/css/' . $theme . '.css'
    ])
    @yield( 'layout.dashboard.header' )
    @include( 'layout._header-script' )
    @vite([ 'resources/ts/vue-runtime.ts' ])
    @vite([ 'resources/ts/lang-loader.ts' ])
</head>
<body <?php echo in_array( app()->getLocale(), config( 'nexopos.rtl-languages' ) ) ? 'dir="rtl"' : "";?>>
    <div class="h-full w-full flex flex-col">
        <div class="overflow-hidden flex flex-auto">
            <div id="dashboard-aside">
                <div v-if="sidebar === 'visible'" v-cloak  class="w-64 z-50 absolute md:static flex-shrink-0 h-full flex-col overflow-hidden">
                    <div class="ns-scrollbar overflow-y-auto h-full text-sm">
                        <div class="logo py-4 flex justify-center items-center">
                            <img src="{{ ns_logo( 'rectangle' ) }}" class="w-16 h-16 rounded-xl object-cover" alt="{{ ns()->option->get( 'ns_store_name', 'RGBF' ) }}"/>
                        </div>
                        <ul id="aside-menu" class="mr-2">
                            @foreach( $menus->getMenus() as $identifier => $menu )
                                <ns-menu identifier="{{ $identifier }}" toggled="{{ $menu[ 'toggled' ] ?? '' }}" label="{{ @$menu[ 'label' ] }}" icon="{{ @$menu[ 'icon' ] }}" href="{{ @$menu[ 'href' ] }}" notification="{{ isset( $menu[ 'notification' ] ) ? $menu[ 'notification' ] : 0 }}" id="menu-{{ $identifier }}">
                                    @if ( isset( $menu[ 'childrens' ] ) )
                                        @foreach( $menu[ 'childrens' ] as $identifier => $menu )
                                        <ns-submenu :active="{{ ( isset( $menu[ 'active' ] ) ? ( $menu[ 'active' ] ? 'true' : 'false' ) : 'false' ) }}" href="{{ $menu[ 'href' ] }}" id="submenu-{{ $identifier }}">{{ $menu[ 'label' ] }}</ns-submenu>
                                        @endforeach        
                                    @endif
                                </ns-menu>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
            <div id="dashboard-overlay">
                <div v-if="sidebar === 'visible'" @click="closeMenu()" class="z-40 w-full h-full md:hidden absolute" style="background: rgb(51 51 51 / 25%)"></div>
            </div>
            @hasSection( 'layout.dashboard.canvas' )
                @yield( 'layout.dashboard.canvas' )
            @else
            <div id="dashboard-body" class="flex flex-auto flex-col overflow-hidden">
                <div class="overflow-y-auto flex-auto">
                    @hasSection( 'layout.dashboard.body' )
                        @yield( 'layout.dashboard.body' )
                    @endif

                    @hasSection( 'layout.dashboard.body.with-header' )
                        @include( 'common.dashboard.with-header' )
                    @endif

                    @hasSection( 'layout.dashboard.with-header' )
                        @include( 'common.dashboard.with-header' )
                    @endif

                    @hasSection( 'layout.dashboard.body.with-title' )
                        @include( 'common.dashboard.with-title' )
                    @endif

                    @hasSection( 'layout.dashboard.with-title' )
                        @include( 'common.dashboard.with-title' )
                    @endif
                </div>
                <div class="p-2 text-xs flex justify-end text-gray-500">
                    <span>RGBF v{{ config( 'nexopos.version' ) }}</span>
                </div>
            </div>
            @endif
        </div>
    </div>
    @section( 'layout.dashboard.footer' )
        @include( 'common.popups' )
        @include( 'common.dashboard-footer' )
    @show
</body>
</html>
