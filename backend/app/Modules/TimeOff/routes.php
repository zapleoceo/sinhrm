<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Middleware\EnsureUserIsActive;
use App\Modules\TimeOff\Http\Controllers\BalanceController;
use App\Modules\TimeOff\Http\Controllers\LeaveRequestController;
use App\Modules\TimeOff\Http\Controllers\SettingsController;
use App\Modules\TimeOff\Providers\TimeOffServiceProvider;
use Illuminate\Support\Facades\Route;

// /api/timeoff/*. Settings writes: gate timeoff-manage (superadmin, admin). Requests and balances: PeopleScope
// (self, managers above, admins) checked in the services/resolver.
Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function (): void {
    Route::get('types', [SettingsController::class, 'types'])->name('timeoff.types.index');
    Route::get('holidays', [SettingsController::class, 'holidays'])->name('timeoff.holidays.index');

    Route::middleware('can:'.TimeOffServiceProvider::MANAGE)->group(function (): void {
        Route::post('types', [SettingsController::class, 'storeType'])->name('timeoff.types.store');
        Route::patch('types/{leaveType}', [SettingsController::class, 'updateType'])->whereNumber('leaveType')->name('timeoff.types.update');
        Route::get('policies', [SettingsController::class, 'policies'])->name('timeoff.policies.index');
        Route::post('policies', [SettingsController::class, 'storePolicy'])->name('timeoff.policies.store');
        Route::patch('policies/{policy}', [SettingsController::class, 'updatePolicy'])->whereNumber('policy')->name('timeoff.policies.update');
        Route::post('holidays', [SettingsController::class, 'storeHoliday'])->name('timeoff.holidays.store');
        Route::patch('holidays/{holiday}', [SettingsController::class, 'updateHoliday'])->whereNumber('holiday')->name('timeoff.holidays.update');
        Route::delete('holidays/{holiday}', [SettingsController::class, 'destroyHoliday'])->whereNumber('holiday')->name('timeoff.holidays.destroy');
        Route::post('balances/adjust', [BalanceController::class, 'adjust'])->name('timeoff.balances.adjust');
    });

    Route::get('balances', [BalanceController::class, 'index'])->name('timeoff.balances.index');
    Route::get('balances/history', [BalanceController::class, 'history'])->name('timeoff.balances.history');

    Route::get('requests', [LeaveRequestController::class, 'index'])->name('timeoff.requests.index');
    Route::get('requests/preview', [LeaveRequestController::class, 'preview'])->name('timeoff.requests.preview');
    Route::post('requests', [LeaveRequestController::class, 'store'])->name('timeoff.requests.store');
    Route::get('requests/{leaveRequest}', [LeaveRequestController::class, 'show'])->whereNumber('leaveRequest')->name('timeoff.requests.show');
    Route::post('requests/{leaveRequest}/approve', [LeaveRequestController::class, 'approve'])->whereNumber('leaveRequest')->name('timeoff.requests.approve');
    Route::post('requests/{leaveRequest}/reject', [LeaveRequestController::class, 'reject'])->whereNumber('leaveRequest')->name('timeoff.requests.reject');
    Route::post('requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel'])->whereNumber('leaveRequest')->name('timeoff.requests.cancel');
    Route::get('approvals', [LeaveRequestController::class, 'approvals'])->name('timeoff.approvals');
    Route::get('calendar', [LeaveRequestController::class, 'calendar'])->name('timeoff.calendar');
});
