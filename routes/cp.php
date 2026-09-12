<?php

use Bpmore\StatamicDelegated\Http\Controllers\AssignmentsController;
use Bpmore\StatamicDelegated\Http\Controllers\AuditController;
use Bpmore\StatamicDelegated\Http\Controllers\MyTerritoriesController;
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

    Route::get('audit', [AuditController::class, 'all'])->name('audit.index');
});

// Territory admin self-service. No permission: the controller admits anyone
// who administers at least one territory, and every write goes through the
// invariants.
Route::prefix('delegated')->name('delegated.')->group(function () {
    Route::get('my-territories', [MyTerritoriesController::class, 'index'])->name('my-territories.index');
    Route::patch('my-territories/{territory}', [MyTerritoriesController::class, 'update'])->name('my-territories.update');
    Route::post('my-territories/{territory}/members', [MyTerritoriesController::class, 'addMember'])->name('my-territories.members.store');
    Route::delete('my-territories/{territory}/members', [MyTerritoriesController::class, 'removeMember'])->name('my-territories.members.destroy');
    Route::patch('my-territories/{territory}/members/{user}/roles', [MyTerritoriesController::class, 'setRoles'])->name('my-territories.members.roles');
    Route::get('my-territories/{territory}/audit', [AuditController::class, 'territory'])->name('my-territories.audit');
});
