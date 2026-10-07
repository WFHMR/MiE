<?php
use App\Services\DateService;
use Illuminate\Support\Facades\Auth;

/**
 * @var DateService $dateService
 */
$dateService = app()->make(DateService::class);

if (Auth::check()) {
    $theme = Auth::user()->attribute->theme ?: ns()->option->get('ns_default_theme', 'light');
} else {
    $theme = ns()->option->get('ns_default_theme', 'light');
}

// HR Menu - แยกจาก NexoPOS
$hrMenus = [
    'nexohr-dashboard' => [
        'label' => 'แดชบอร์ด HR',
        'icon' => 'la-tachometer-alt',
        'href' => url('/admin/dashboard'),
        'toggled' => 'false',
        'notification' => 0,
    ],
    'nexohr-office-locations' => [
        'label' => 'พื้นที่สำนักงาน',
        'icon' => 'la-map-marker',
        'href' => url('/admin/office-locations'),
        'toggled' => 'false',
        'notification' => 0,
    ],
    'nexohr-attendances' => [
        'label' => 'ลงเวลาเข้า-ออก',
        'icon' => 'la-clock',
        'href' => url('/admin/attendances'),
        'toggled' => 'false',
        'notification' => 0,
    ],
    'nexohr-overtimes' => [
        'label' => 'โอที',
        'icon' => 'la-business-time',
        'href' => url('/admin/overtimes'),
        'toggled' => 'false',
        'notification' => 0,
    ],
    'nexohr-leaves' => [
        'label' => 'ลางาน',
        'icon' => 'la-calendar-times',
        'href' => url('/admin/leaves'),
        'toggled' => 'false',
        'notification' => 0,
    ],
    'nexohr-payrolls' => [
        'label' => 'เงินเดือน',
        'icon' => 'la-money-bill-wave',
        'href' => url('/admin/payrolls'),
        'toggled' => 'false',
        'notification' => 0,
    ],
    'nexohr-qr-code' => [
        'label' => 'QR Code เข้างาน',
        'icon' => 'la-qrcode',
        'href' => url('/admin/qr-code'),
        'toggled' => 'false',
        'notification' => 0,
    ],
    'nexohr-settings' => [
        'label' => 'ตั้งค่า',
        'icon' => 'la-cog',
        'href' => url('/admin/settings'),
        'toggled' => 'false',
        'notification' => 0,
    ],
];
?>
<!DOCTYPE html>
<html lang="th" data-theme="{{ $theme }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ App\Services\Helper::pageTitle( $title ?? __( 'ระบบ HR' ) ) }}</title>
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
                        <div class="logo py-4 flex justify-center items-center flex-col">
                            @if ( ns()->option->get( 'ns_store_rectangle_logo' ) )
                            <img src="{{ ns()->option->get( 'ns_store_rectangle_logo' ) }}" class="w-16 h-16 rounded-xl object-cover" alt="logo"/>
                            @else
                            <h1 class="font-black text-transparent bg-clip-text bg-gradient-to-b from-teal-200 to-teal-400 text-3xl">RGBF</h1>
                            @endif
                            <span class="text-xs text-gray-500 mt-2">ระบบ HR</span>
                        </div>
                        <ul id="aside-menu" class="mr-2">
                            @foreach( $hrMenus as $identifier => $menu )
                                <ns-menu identifier="{{ $identifier }}" toggled="{{ $menu[ 'toggled' ] ?? '' }}" label="{{ @$menu[ 'label' ] }}" icon="{{ @$menu[ 'icon' ] }}" href="{{ @$menu[ 'href' ] }}" notification="{{ isset( $menu[ 'notification' ] ) ? $menu[ 'notification' ] : 0 }}" id="menu-{{ $identifier }}">
                                    @if ( isset( $menu[ 'childrens' ] ) )
                                        @foreach( $menu[ 'childrens' ] as $childIdentifier => $childMenu )
                                        <ns-submenu :active="{{ ( isset( $childMenu[ 'active' ] ) ? ( $childMenu[ 'active' ] ? 'true' : 'false' ) : 'false' ) }}" href="{{ $childMenu[ 'href' ] }}" id="submenu-{{ $childIdentifier }}">{{ $childMenu[ 'label' ] }}</ns-submenu>
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
                    <span>NexoHR v1.0 | RGBF v{{ config( 'nexopos.version' ) }}</span>
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
