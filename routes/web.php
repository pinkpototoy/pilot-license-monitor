<?php

use App\Http\Controllers\Admin\ConfigController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\MfaController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\CredentialController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\MyRecordsController;
use App\Http\Controllers\RenewalController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\StudentController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/forgot-password', [PasswordController::class, 'requestForm'])->name('password.request');
    Route::post('/forgot-password', [PasswordController::class, 'sendLink'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordController::class, 'resetForm'])->name('password.reset');
    Route::post('/reset-password', [PasswordController::class, 'reset'])->middleware('throttle:5,1')->name('password.update');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    // MFA steps sit between password and the app, so they are outside the 'mfa' gate.
    Route::get('/mfa/enroll', [MfaController::class, 'enrollForm'])->name('mfa.enroll');
    Route::post('/mfa/enroll', [MfaController::class, 'enroll'])->middleware('throttle:10,1');
    Route::get('/mfa/challenge', [MfaController::class, 'challengeForm'])->name('mfa.challenge');
    Route::post('/mfa/challenge', [MfaController::class, 'challenge'])->middleware('throttle:10,1');

    Route::middleware('mfa')->group(function () {
        Route::get('/', fn () => request()->user()->role->isStaffSide()
            ? redirect()->route('dashboard') : redirect()->route('my.records'))->name('home');
        Route::get('/mfa/recovery-codes', [MfaController::class, 'recoveryCodes'])->name('mfa.recovery-codes');

        // Student self-service (UC-10)
        Route::get('/my', [MyRecordsController::class, 'show'])->name('my.records');

        // Shared by students (own records only) and staff — policies decide (UC-11 to UC-14).
        Route::post('/credentials/{credential}/renewals', [RenewalController::class, 'open'])->name('renewals.open');
        Route::get('/renewals/{case}', [RenewalController::class, 'show'])->name('renewals.show');
        Route::post('/renewals/{case}/documents', [RenewalController::class, 'upload'])->middleware('throttle:30,60')->name('renewals.upload');
        Route::post('/renewals/{case}/submit', [RenewalController::class, 'submit'])->name('renewals.submit');
        Route::post('/renewals/{case}/cancel', [RenewalController::class, 'cancel'])->name('renewals.cancel');
        Route::get('/documents/{document}/file', [DocumentController::class, 'file'])->name('documents.file');

        // In-app notifications and preferences (FR-053)
        Route::get('/inbox', [InboxController::class, 'index'])->name('inbox');
        Route::post('/inbox/read-all', [InboxController::class, 'readAll'])->name('inbox.read-all');
        Route::post('/inbox/{notification}/read', [InboxController::class, 'read'])->name('inbox.read');
        Route::get('/settings/notifications', [InboxController::class, 'preferences'])->name('preferences');
        Route::post('/settings/notifications', [InboxController::class, 'savePreferences']);

        // Staff side
        Route::middleware('staff')->group(function () {
            Route::get('/dashboard', DashboardController::class)->name('dashboard');

            Route::get('/students', [StudentController::class, 'index'])->name('students.index');
            Route::get('/students/new', [StudentController::class, 'create'])->name('students.create');
            Route::post('/students', [StudentController::class, 'store'])->name('students.store');
            Route::get('/students/{student}', [StudentController::class, 'show'])->name('students.show');
            Route::get('/students/{student}/edit', [StudentController::class, 'edit'])->name('students.edit');
            Route::put('/students/{student}', [StudentController::class, 'update'])->name('students.update');
            Route::post('/students/{student}/deactivate', [StudentController::class, 'deactivate'])->name('students.deactivate');
            Route::post('/students/{student}/reactivate', [StudentController::class, 'reactivate'])->name('students.reactivate');
            Route::post('/students/{student}/invite', [StudentController::class, 'invite'])->name('students.invite');

            Route::get('/students/{student}/credentials/new', [CredentialController::class, 'create'])->name('credentials.create');
            Route::post('/students/{student}/credentials', [CredentialController::class, 'store'])->name('credentials.store');
            Route::get('/credentials/{credential}/correct', [CredentialController::class, 'correctForm'])->name('credentials.correct');
            Route::put('/credentials/{credential}/correct', [CredentialController::class, 'correct']);
            Route::get('/credentials/{credential}/periods/new', [CredentialController::class, 'periodForm'])->name('credentials.period');
            Route::post('/credentials/{credential}/periods', [CredentialController::class, 'addPeriod']);
            Route::post('/credentials/{credential}/override', [CredentialController::class, 'override'])->name('credentials.override');
            Route::post('/credentials/{credential}/override/end', [CredentialController::class, 'endOverride'])->name('credentials.override.end');

            Route::get('/audit-log', [AuditLogController::class, 'index'])->name('audit.index');

            // Verification (UC-12, UC-14)
            Route::get('/verification', [RenewalController::class, 'queue'])->name('renewals.queue');
            Route::post('/renewals/{case}/claim', [RenewalController::class, 'claim'])->name('renewals.claim');
            Route::post('/renewals/{case}/release', [RenewalController::class, 'release'])->name('renewals.release');
            Route::post('/renewals/{case}/complete', [RenewalController::class, 'complete'])->name('renewals.complete');
            Route::post('/documents/{document}/review', [RenewalController::class, 'review'])->name('documents.review');

            // Reports (UC-15)
            Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
            Route::get('/reports/{code}', [ReportController::class, 'show'])->where('code', 'RPT-\\d{2}')->name('reports.show');

            // Import (UC-04)
            Route::get('/imports', [ImportController::class, 'index'])->name('imports.index');
            Route::get('/imports/template', [ImportController::class, 'template'])->name('imports.template');
            Route::post('/imports', [ImportController::class, 'store'])->name('imports.store');
            Route::get('/imports/{batch}', [ImportController::class, 'show'])->name('imports.show');
            Route::get('/imports/{batch}/problems', [ImportController::class, 'errors'])->name('imports.errors');
            Route::post('/imports/{batch}/commit', [ImportController::class, 'commit'])->name('imports.commit');
            Route::post('/imports/{batch}/rollback', [ImportController::class, 'rollback'])->name('imports.rollback');

            // Administration (UC-02, UC-06, UC-07)
            Route::prefix('admin')->name('admin.')->group(function () {
                Route::get('/users', [UserController::class, 'index'])->name('users');
                Route::post('/users', [UserController::class, 'store'])->name('users.store');
                Route::post('/users/{user}', [UserController::class, 'update'])->name('users.update');
                Route::get('/credential-types', [ConfigController::class, 'credentialTypes'])->name('credential-types');
                Route::post('/credential-types', [ConfigController::class, 'saveCredentialType'])->name('credential-types.store');
                Route::post('/credential-types/{type}', [ConfigController::class, 'saveCredentialType'])->name('credential-types.update');
                Route::post('/credential-types/{type}/checklist', [ConfigController::class, 'saveRequirement'])->name('checklist.save');
                Route::post('/programs/{program}/requirements', [ConfigController::class, 'saveProgramRequirements'])->name('programs.requirements');
                Route::post('/document-types', [ConfigController::class, 'saveDocumentType'])->name('document-types.store');
                Route::get('/reminders', [ConfigController::class, 'reminders'])->name('reminders');
                Route::post('/reminders', [ConfigController::class, 'saveRule'])->name('rules.store');
                Route::post('/reminders/{rule}', [ConfigController::class, 'saveRule'])->name('rules.update');
                Route::post('/templates/{template}', [ConfigController::class, 'saveTemplate'])->name('templates.update');
            });
        });
    });
});
