<?php
use App\Classes\Hook;
use App\Services\DateService;
use Illuminate\Support\Facades\Auth;

$dateService    =   app()->make( DateService::class );
?>
<script>
        /**
         * constant where is registered
         * global custom components
         * @param {Object}
         */
        window.nsExtraComponents     =   new Object;

        /**
         * describe a global RGBF object
         * @param {object} ns
         */
        window.ns   =   { nsExtraComponents };

        /**
         * store the server date
         * @param {string}
         */
        window.ns.date  =   {
            current : '{{ $dateService->toDateTimeString() }}',
            serverDate : '{{ $dateService->toDateTimeString() }}',
            timeZone: '{{ ns()->option->get( "ns_datetime_timezone", "Europe/London" ) }}',
            format: `{{ $dateService->convertFormatToMomment( ns()->option->get( 'ns_datetime_format', 'Y-m-d H:i:s' ) ) }}`
        }

        /**
         * Let's define the actual theme used
         */
        window.ns.theme     =   `{{ $theme }}`;

        /**
         * define the current language selected by the user or
         * the language that applies to the system by default.
         */
        window.ns.language      =   '{{ app()->getLocale() }}';

        /**
         * key ด้านล่างคือ namespace ของ string แปลระดับ core
         * ต้องตรงกับ CORE_LANG_NAMESPACE ใน resources/ts/libraries/lang.ts เสมอ
         * ถ้าแก้ที่นี่ต้องแก้ที่นั่นด้วย ไม่งั้น __() จะ fallback เป็น English key เงียบๆ
         *
         * เคยใช้ชื่อโปรเจกต์ต้นน้ำเป็น key ตัวนี้ และถูกยกเว้นไว้ใน NoUpstreamBrandingTest
         * ด้วยเหตุผลว่าเป็น key ภายในที่ไม่มีใครเห็น ซึ่งไม่จริง เพราะ json_encode พิมพ์มันลง
         * หน้าเว็บทุกหน้าของ dashboard คนกด view source ก็อ่านได้ทันที
         *
         * ตอนนี้ใช้ `core` เป็นตัวพิมพ์เล็ก จึงชนกับ namespace ของ module ไม่ได้
         * เพราะ namespace ของ module มาจาก ucwords( Str::camel( ชื่อ module ) ) ซึ่งขึ้นต้นตัวใหญ่เสมอ
         *
         * module ลงทะเบียนไฟล์แปลของตัวเองเพิ่มผ่าน filter นี้ โดยใช้ namespace ของ module
         */
        window.ns.langFiles     =   <?php echo json_encode( Hook::filter( 'ns.langFiles', [
            'core'   =>  asset( "/lang/" . app()->getLocale() . ".json" ),
        ]));?>

        @auth
        /**
         * We display only fillable values for the
         * logged user. The password might be displayed on an encrypted form.
         */
        window.ns.user              =   <?php echo json_encode( ns()->getUserDetails() );?>;
        window.ns.user.attributes   =   <?php echo json_encode( Auth::user()->attribute );?>;
        @endauth
        
        window.ns.cssFiles          =   <?php echo json_encode( ns()->simplifyManifest() );?>;

        /**
         * Most requests from the dashboard go to routes in the api group, which authenticate with
         * a token and are exempt from CSRF. A few post to routes in the web group instead — the
         * branch picker is one — and those are verified. Axios is configured with
         * withCredentials but no token, and this application ships no csrf-token meta tag, so the
         * value is published here once rather than being repeated in every Blade view that needs it.
         */
        window.ns.csrfToken         =   '<?php echo csrf_token(); ?>';

        /**
         * We'll store here the file mime types
         * that are supported by the media manager.
         */
        window.ns.medias            =   {
            mimes:  <?php echo json_encode( ns()->mediaService->getMimes() )?>,
            imageMimes: <?php echo json_encode( ns()->mediaService->getImageMimes() );?>,
            mediaLayout: '<?php echo ns()->option->get( 'ns_media_library_layout', 'modern' ) === 'modern' ? 'modern' : 'classic'; ?>'  
        }
    </script>
