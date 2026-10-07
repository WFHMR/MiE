<?php
use App\Classes\Hook;
use App\Classes\Output;
use App\Events\RenderBeforePasswordRecoveryFormEvent;
use App\Events\RenderAfterPasswordRecoveryFormEvent;
use App\Events\RenderPasswordLostFooterEvent;
?>

@extends( 'layout.base' )

@section( 'layout.base.body' )
    <div id="page-container" class="h-full w-full flex items-center overflow-y-auto pb-10 bg-gray-300">
        <div class="container mx-auto p-4 md:p-0 flex-auto items-center justify-center flex">
            <div id="sign-in-box" class="w-full md:w-3/5 lg:w-2/5">
                <div class="flex justify-center items-center py-6">
                    <h1 class="font-black text-transparent bg-clip-text bg-gradient-to-b from-teal-200 to-teal-400 text-3xl">RGBF</h1>
                </div>
                {!! Output::dispatch( RenderBeforePasswordRecoveryFormEvent::class ); !!}
                @include( '/common/auth/password-recovery-form' )
                {!! Output::dispatch( RenderAfterPasswordRecoveryFormEvent::class ); !!}
            </div>
        </div>
    </div>
@endsection

@section( 'layout.base.footer' )
    @parent
    {!! Output::dispatch( RenderPasswordLostFooterEvent::class ); !!}
    @vite([ 'resources/ts/auth.ts' ])
@endsection
