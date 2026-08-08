<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SubmissionExportController;
use App\Livewire\Forms\FormBuilder;
use App\Livewire\Forms\FormIndex;
use App\Livewire\Forms\PublicForm;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::get('/f/{token}', PublicForm::class)->name('forms.public');

Route::middleware(['auth', 'verified'])->prefix('dashboard')->group(function () {
    Route::get('/forms', FormIndex::class)->name('forms.index');
    Route::get('/forms/{form}/builder', FormBuilder::class)->name('forms.builder');
    Route::get('/forms/{form}/submissions/export', SubmissionExportController::class)
        ->name('forms.submissions.export');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
