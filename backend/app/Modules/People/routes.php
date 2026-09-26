<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Middleware\EnsureUserIsActive;
use App\Modules\People\Http\Controllers\ChangeRequestController;
use App\Modules\People\Http\Controllers\EmployeeHistoryController;
use App\Modules\People\Http\Controllers\HireController;
use App\Modules\People\Http\Controllers\MyEmployeeController;
use App\Modules\People\Http\Controllers\PeopleController;
use App\Modules\People\Providers\PeopleServiceProvider;
use Illuminate\Support\Facades\Route;

// /api/* of the People module. Reading the directory: any active user; job/PII tiers: PeopleScope;
// writing employees: gate people-manage (superadmin, admin — they act as HR). No DELETE: people are terminated.
Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function (): void {
    Route::get('people', [PeopleController::class, 'index'])->name('people.index');
    Route::get('people/org-chart', [PeopleController::class, 'orgChart'])->name('people.org-chart');
    Route::get('people/change-requests', [ChangeRequestController::class, 'index'])->name('people.change-requests.index');
    Route::post('people/change-requests/{changeRequest}/approve', [ChangeRequestController::class, 'approve'])
        ->whereNumber('changeRequest')->name('people.change-requests.approve');
    Route::post('people/change-requests/{changeRequest}/reject', [ChangeRequestController::class, 'reject'])
        ->whereNumber('changeRequest')->name('people.change-requests.reject');
    Route::get('people/{employee}', [PeopleController::class, 'show'])->whereNumber('employee')->name('people.show');

    Route::middleware('can:'.PeopleServiceProvider::MANAGE)->group(function (): void {
        Route::post('people', [PeopleController::class, 'store'])->name('people.store');
        Route::patch('people/{employee}', [PeopleController::class, 'update'])->whereNumber('employee')->name('people.update');
        Route::get('people/{employee}/history', EmployeeHistoryController::class)->whereNumber('employee')->name('people.history');
        Route::post('people/{employee}/terminate', [PeopleController::class, 'terminate'])
            ->whereNumber('employee')->name('people.terminate');
    });

    Route::get('me/employee', [MyEmployeeController::class, 'show'])->name('people.me');
    Route::post('me/employee/change-requests', [MyEmployeeController::class, 'submitChange'])->name('people.me.change-requests');

    // Recruiting → People: create the employee from a hired application (idempotent).
    Route::post('applications/{application}/hire', HireController::class)->whereNumber('application')->name('people.hire');
});
