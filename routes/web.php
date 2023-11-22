
<?php

// namespace App\Http\Controllers;
use App\Http\Controllers\CandidatesController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\InvoicesController;

use App\Http\Controllers\Admin\ProjectsController;
// Route::get('students',[StudentController::class,'index']);

Route::get('/', function () {
    return view('welcome');
});

Route::get('/students', [CandidatesController::class, 'index']);
Route::get('/candidates/app', [CandidatesController::class, 'index_app'])->name('candidates.index_app');

// Route::get('/candidates', 'CandidatesController@index')->name('candidates.index');

Route::group(['middleware' => ['web', 'auth']], function () {
    // Existing routes
    Route::get('/candidates/onboarding', 'CandidatesController@onboarding')->name('candidates.onboarding');

    // New routes for the additional methods
    Route::post('/candidates/update_spi_data', 'CandidatesController@update_spi_data');
    Route::post('/candidates/update_role_data', 'CandidatesController@update_role_data');
    Route::post('/candidates/get_call_vu_data', 'CandidatesController@get_call_vu_data');
    Route::post('/candidates/get_response_from_call_vu', 'CandidatesController@get_response_from_call_vu');
    Route::get('/candidates/training', 'CandidatesController@training')->name('candidates.training');
    Route::get('/candidates/result', 'CandidatesController@result')->name('candidates.result');
    Route::get('/candidates/iresult', 'CandidatesController@iresult')->name('candidates.iresult');
    Route::get('/candidates/candidate', 'CandidatesController@candidate')->name('candidates.candidate');
    Route::get('/candidates/candidate_list', 'CandidatesController@candidate_list')->name('candidates.candidate_list');
    Route::get('/candidates/profile/{id}', 'CandidatesController@profile')->name('candidates.profile');
    Route::post('/candidates/profile_update/{id}', 'CandidatesController@profile_update');
    Route::get('/candidates/trainee', 'CandidatesController@trainee')->name('candidates.trainee');
    Route::get('/candidates/agent', 'CandidatesController@agent')->name('candidates.agent');
    Route::post('/candidates/update_gigwage', 'CandidatesController@update_gigwage');
    Route::get('/candidates/access_guard', 'CandidatesController@access_guard')->name('candidates.access_guard');
    Route::get('/candidates/payment_dashboard', 'CandidatesController@payment_dashboard')->name('candidates.payment_dashboard');

    Route::get('/candidates/get_alert_intake', 'CandidatesController@getAlertIntake')->name('candidates.get_alert_intake');
    Route::get('/candidates/gl_test', 'CandidatesController@glTest')->name('candidates.gl_test');
    Route::get('/candidates/gl_prod', 'CandidatesController@glProd')->name('candidates.gl_prod');
    Route::get('/candidates/onboard_iframe', 'CandidatesController@assessmentIframe')->name('candidates.assessment_iframe');
    Route::get('/candidates/phone_verify_modal', 'CandidatesController@phoneVerifyModal')->name('candidates.phone_verify_modal');
    Route::post('/candidates/update_phone_number', 'CandidatesController@updatePhoneNumber')->name('candidates.update_phone_number');

    Route::post('/candidates/resend_verification_code', 'CandidatesController@resendVerificationCode')->name('candidates.resend_verification_code');
    Route::post('/candidates/verify_phone_number', 'CandidatesController@verifyPhoneNumber')->name('candidates.verify_phone_number');
    Route::post('/candidates/initiate_bgv', 'CandidatesController@initiateBGV')->name('candidates.initiate_bgv');
    Route::get('/candidates/send_notification/{staffid}', 'CandidatesController@sendNotification')->name('candidates.send_notification');

    Route::get('/candidates/contactus_list', 'CandidatesController@contactusList')->name('candidates.contactus_list');
    Route::post('/candidates/save_encrypted_payload', 'CandidatesController@saveEncryptedPayload')->name('candidates.save_encrypted_payload');
    Route::post('/candidates/save_assessment_payload', 'CandidatesController@saveAssessmentPayload')->name('candidates.save_assessment_payload');

    Route::get('chat', 'CandidatesController@chat');
    Route::get('demo-pwa', 'CandidatesController@demoPWA');
    Route::get('show-training', 'CandidatesController@showTraining');
    Route::get('download-flex360', 'CandidatesController@downloadFlex360');

    Route::post('call-vu-data', 'CandidatesController@getCallVuDataByDynamicPayload')
    ->name('call-vu-data');

    Route::post('/candidates/webhook-request', 'CandidatesController@webhookRequest')
    ->name('candidates.webhookRequest');

// Get All Invite
Route::get('/candidates/get-all-invite', 'CandidatesController@getAllInvite')
    ->name('candidates.getAllInvite');

// Get Invitation Status
Route::get('/candidates/get-invitation-status', 'CandidatesController@getInvitationStatus')
    ->name('candidates.getInvitationStatus');


    Route::get('/candidates/bgv-status', 'CandidatesController@getBGVStatus')
    ->name('candidates.getBGVStatus');

Route::get('/candidates/update-candidate-uid', 'CandidatesController@updateCandidateUID')
    ->name('candidates.updateCandidateUID');

Route::get('/candidates/download-app', 'CandidatesController@downloadApp')
    ->name('candidates.downloadApp');

Route::get('/candidates/course-result/{email}', 'CandidatesController@getCourseResult')
    ->name('candidates.getCourseResult');

    Route::get('/candidates/update-training-result', 'CandidatesController@updateTrainingResult')
    ->name('candidates.updateTrainingResult');

Route::get('/candidates/ddsetup', 'CandidatesController@ddSetup')
    ->name('candidates.ddSetup');

Route::post('/candidates/ddsetup-submit', 'CandidatesController@ddSetupSubmit')
    ->name('candidates.ddSetupSubmit');

Route::get('/candidates/chat-init-push-notification', 'CandidatesController@chatInitPushNotification')
    ->name('candidates.chatInitPushNotification');

Route::get('/candidates/send-invitation/{contractor_id?}', 'CandidatesController@sendInvitation')
    ->name('candidates.sendInvitation');




    Route::post('/validate-qrcode', 'YourControllerName@validateQrCode');
Route::post('/admin-validate-qrcode', 'YourControllerName@adminValidateQrCode');
Route::post('/verify-ssn', 'YourControllerName@verifySsn');
Route::post('/verify-code', 'YourControllerName@verifyCode');
Route::get('/home', 'YourControllerName@home');
Route::post('/keycloak-logout', 'YourControllerName@keycloakLogout');
Route::post('/generate-qr-ajax', 'YourControllerName@generateQRAjax');
// routes/web.php



Route::get('invoices/{id?}', [InvoicesController::class, 'index']);
Route::get('invoices/list_invoices/{id?}', [InvoicesController::class, 'listInvoices']);
Route::get('invoices/recurring/{id?}', [InvoicesController::class, 'recurring']);
Route::get('invoices/table/{clientId?}', [InvoicesController::class, 'table']);
Route::get('invoices/client_change_data/{customerId}/{currentInvoice?}', [InvoicesController::class, 'clientChangeData']);
// ... (add other routes)



    Route::get('/projects', [ProjectsController::class, 'index']);
    Route::get('/projects/table/{clientid?}', [ProjectsController::class, 'table']);
    Route::get('/staff-projects', [ProjectsController::class, 'staffProjects']);
    Route::get('/expenses/{id}', [ProjectsController::class, 'expenses']);



});