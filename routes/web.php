<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LogsController;
use App\Http\Controllers\OralController;
use App\Http\Controllers\PosterController;
use App\Http\Controllers\QuizController;
use App\Http\Controllers\Reference\CriteriaController;
use App\Http\Controllers\Reference\JudgesController;
use App\Http\Controllers\Reference\ParticipantsController;
use App\Http\Controllers\Reference\RoundController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('auth.login');
});

Auth::routes();

Route::group(['middleware' => ['auth']], function () {
    Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/quiz', [QuizController::class, 'index'])->name('quiz');
    Route::get('/quiz/view-details', [QuizController::class, 'viewDetails'])->name('quiz.view-details');

    Route::get('/oral', [OralController::class, 'index'])->name('oral');
    Route::get('/poster', [PosterController::class, 'index'])->name('poster');

    /* -------------------------------- Reference ------------------------------- */
    //CRITERIA
    Route::get('/reference/criteria', [CriteriaController::class, 'index'])->name('reference.criteria');
    Route::post('/reference/save-criteria', [CriteriaController::class, 'saveCriteria'])->name('save-criteria');

    //JUDGES
    Route::get('/reference/judges', [JudgesController::class, 'index'])->name('reference.judges');
    Route::post('/regerence/save-judges', [JudgesController::class, 'saveJudges'])->name('save-judge');

    //PARTICIPANT
    Route::get('/reference/participants', [ParticipantsController::class, 'index'])->name('reference.participants');
    Route::post('/reference/save-participant', [ParticipantsController::class, 'saveParticipant'])->name('save-participant');

    //ROUND
    Route::get('/reference/round', [RoundController::class, 'index'])->name('reference.round');
    Route::post('/reference/save-round', [RoundController::class, 'saveRound'])->name('save-round');
    /* -------------------------------- Reference ------------------------------- */

    Route::get('/logs', [LogsController::class, 'index'])->name('logs');

    //LED MANAGEMENT
    Route::view('/display-management', 'display-management')->name('display-management');
});


// -----------------------------------LED WALL DISPLAY-----------------------------------

//ORAL
Route::get('/display_oral', [App\Http\Controllers\DisplayController::class, 'oral'])->name('display_oral');

//QUIZ
Route::get('/display_quiz', [App\Http\Controllers\DisplayQuizController::class, 'quiz'])->name('display_quiz');
Route::get('/display_quiz_score', [App\Http\Controllers\DisplayQuizController::class, 'quiz_score'])->name('display_quiz_score');

//POSTER
Route::get('/display_poster', [App\Http\Controllers\DisplayPosterController::class, 'poster'])->name('display_poster');
Route::get('/display_poster_output/{id}', [App\Http\Controllers\DisplayPosterController::class, 'output'])->name('display_poster_output');

//LED WALL DISPLAY
Route::view('/display_led', 'led_display.dynamic_led')->name('display_led');

/* ----------------------------- Livewire Route ----------------------------- */

Livewire::setScriptRoute(function ($handle) {
    return Route::get('/tabulation/livewire/livewire.js', $handle);
});
Livewire::setUpdateRoute(function ($handle) {
    return Route::post('/tabulation/livewire/update', $handle);
});
