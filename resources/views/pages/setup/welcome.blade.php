@extends( 'layout.base' )

@section( 'layout.base.body' )
<div id="nexopos-setup" class="justify-center h-full w-full items-center overflow-y-auto py-10 bg-gray-300">
    <div class="container mx-auto p-4 md:p-0 flex-auto items-center justify-center flex">
        <div id="setup" class="w-full md:w-4/5 lg:w-3/5 flex flex-col">
            <div class="flex flex-shrink-0 justify-center items-center py-6">
                <h1 class="font-black text-transparent bg-clip-text bg-gradient-to-b from-teal-200 to-teal-400 text-3xl">RGBF</h1>
            </div>
            <router-view>
                
            </router-view>
        </div>
    </div>
</div>
@endsection

@section( 'layout.base.footer' )
    @parent
    <script>
        const nsLanguages   =   @json( $languages );
        const nsLang = '{{ $lang }}';
    </script>
    @vite([ 'resources/ts/setup.ts' ])
@endsection
