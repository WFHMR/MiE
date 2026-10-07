<?php

use App\Http\Controllers\Dashboard\MediasController;
use App\Http\Middleware\NsRestrictMiddleware;
use Illuminate\Support\Facades\Route;

Route::get( 'medias', [ MediasController::class, 'getMedias' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.see.medias' ) );

/**
 * DELETE medias/{id} was declared here pointing at MediasController::deleteMedia, which does not exist,
 * so anyone holding nexopos.delete.medias who called it got a 500. The media library deletes through
 * medias/bulk-delete below — both ns-media.vue and ns-media-library.vue send the selected ids there,
 * a single file included — so nothing was reaching the broken route.
 */
Route::put( 'medias/{media}', [ MediasController::class, 'updateMedia' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.update.medias' ) );
Route::post( 'medias/bulk-delete/', [ MediasController::class, 'bulkDeleteMedias' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.delete.medias' ) );
Route::post( 'medias', [ MediasController::class, 'uploadMedias' ] )->middleware( NsRestrictMiddleware::arguments( 'nexopos.upload.medias' ) );
