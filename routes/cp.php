<?php

use Bpmore\StatamicDelegated\Http\Controllers\AssignmentsController;
use Bpmore\StatamicDelegated\Http\Controllers\TerritoriesController;
use Illuminate\Support\Facades\Route;

// Registered by Statamic inside its authenticated control-panel group, so
// `statamic.cp.authenticated` already applies. The permission is
// Delegated's own; super users pass it by definition.
Route::middleware('can:manage territories')->prefix('delegated')->name('delegated.')->group(function () {
    Route::get('territories', [TerritoriesController::class, 'index'])->name('territories.index');
    Route::post('territories', [TerritoriesController::class, 'store'])->name('territories.store');
    Route::patch('territories/{territory}', [TerritoriesController::class, 'update'])->name('territories.update');
    Route::delete('territories/{territory}', [TerritoriesController::class, 'destroy'])->name('territories.destroy');

    Route::post('territories/{territory}/assignments', [AssignmentsController::class, 'store'])->name('territories.assignments.store');
    Route::delete('territories/{territory}/assignments', [AssignmentsController::class, 'destroy'])->name('territories.assignments.destroy');
});
