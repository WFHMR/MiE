<?php

use App\Classes\Output;
use App\Classes\Hook;
use App\Events\RenderSignUpFooterEvent;
?>

@extends( 'layout.base' )

@section( 'layout.base.body' )
    <div id="page-container" class="h-full w-full overflow-y-auto pb-10">
        <div class="container mx-auto p-4 md:p-0 flex-auto items-center justify-center flex">
            <div id="sign-in-box" class="w-full md:w-3/5 lg:w-2/5">
                <div class="flex justify-center items-center py-6">
                    <img src="{{ ns_logo( 'square' ) }}" alt="{{ ns()->option->get( 'ns_store_name', 'RGBF' ) }}" class="w-24 h-24 rounded-xl object-cover">
                </div>
                <ns-register></ns-register>
            </div>
        </div>
    </div>
@endsection


@section( 'layout.base.footer' )
    @parent
    {!! Output::dispatch( RenderSignUpFooterEvent::class ) !!}
    @vite([ 'resources/ts/auth.ts' ])
@endsection
