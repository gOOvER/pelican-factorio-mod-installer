<?php

use Illuminate\Support\Facades\Route;
use gOOvER\FactorioModInstaller\Http\Controllers\FactorioModController;
use App\Http\Middleware\Activity\ServerSubject;
use App\Http\Middleware\Api\Client\Server\AuthenticateServerAccess;
use App\Http\Middleware\Api\Client\Server\ResourceBelongsToServer;

// Routes are automatically registered under 'api/client' prefix with appropriate middleware by the plugin system
Route::prefix('/servers/{server:uuid}')
    ->middleware([ServerSubject::class, AuthenticateServerAccess::class, ResourceBelongsToServer::class])
    ->group(function () {
        Route::prefix('factorio-mods')->group(function () {
            Route::get('/', [FactorioModController::class, 'index'])->name('api.client.servers.factorio-mods.index');
            Route::get('/browse', [FactorioModController::class, 'browse'])->name('api.client.servers.factorio-mods.browse');
            Route::get('/{modName}', [FactorioModController::class, 'show'])->name('api.client.servers.factorio-mods.show');
            Route::post('/', [FactorioModController::class, 'store'])->name('api.client.servers.factorio-mods.store');
            Route::delete('/{modName}', [FactorioModController::class, 'destroy'])->name('api.client.servers.factorio-mods.destroy');
            Route::patch('/{modName}/toggle', [FactorioModController::class, 'toggle'])->name('api.client.servers.factorio-mods.toggle');
        });
    });
