<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use App\Models\StaffCandidates;
use App\Models\Staff;
use App\Models\ContactUs;
use App\Models\Announcements;

class CandidatesController extends Controller
{
    public $fetch_callvue_response;

    public function __construct()
    {
        // $this->middleware('auth'); // Assuming you want to protect this controller with authentication.

        // $this->middleware('admin'); // You can define 'admin' middleware as needed.

        // $this->middleware('set_user_cookies'); // Create a custom middleware for setting cookies.

        // $this->middleware('load_admin_language'); // Create a custom middleware for loading language.

        // $this->middleware('get_callvu_response'); // Create a custom middleware for getting CallVu response.
    }

    /* List all copy of clients */
    public function index()
    {
        $data = [];
        $data['dashboard'] = true;
        $data['staff_announcements'] = Announcements::all();
        $data['total_undismissed_announcements'] = Announcements::totalUndismissedAnnouncements();
        $data = apply_filters('before_dashboard_render', $data); // Implement this hook as needed.

        if (session('_callvue_dob_ssn_data') || true) {
            $end_point_name = "CALL_VU_REGISTRATION";
            // $this->callvu->submit_payload(session('_callvue_dob_ssn_data'), $end_point_name);
            // $this->session->forget('_callvue_dob_ssn_data');
        }

        return view('admin.candidates.index', $data);
    }
    public function index_app()
    {
        $data = [];
        $data['dashboard'] = true;
        $data['staff_announcements'] = Announcements::all();
        $data['total_undismissed_announcements'] = Announcements::get_total_undismissed_announcements();
        $data = apply_filters('before_dashboard_render', $data); // Implement this hook as needed.

        $callVu_payload = null;
        if (Session::has('_callvue_dob_ssn_data') || true) {
            $end_point_name = "CALL_VU_REGISTRATION";
            // $this->callvu->submit_payload(session('_callvue_dob_ssn_data'), $end_point_name);
            // Session::forget('_callvue_dob_ssn_data');
        }

        return view('admin.candidates.index_app', $data);
    }

    public function onboarding()
    {
        $data = [];
        $data['candidate'] = Staff::find(auth()->user()->id);
        $data['staff_candidate_columns'] = StaffCandidates::get_columns();
        $data['column_result'] = StaffCandidates::get_column_result(auth()->user()->id);
        $data['user'] = Staff::find(auth()->user()->id);
        $data['countries'] = get_all_countries();

        // Get Assessment URL
        $assessment_url = "https://assessment.attflex.com/home?flex_assessment_key=";
        $assessment_url_db = get_option('assessment_url');
        if (!empty($assessment_url_db)) {
            $assessment_url = $assessment_url_db;
        }
        $domain = $_SERVER['HTTP_HOST'];

        $data['assessment_url'] = $assessment_url . auth()->user()->assessment_key . '&host=' . $domain;
        $data['CourseProgress'] = [];
        if (!empty($data['candidate']->email)) {
            $data['CourseProgress'] = $this->get_course_result($data['candidate']->email);
        }

        return view('admin.candidates.onboarding', $data);
    }

    public function update_spi_data()
    {
        try {
            $staff_id = request('staff_id');

            return Staff::updateSpiData($staff_id);
        } catch (\Exception $ex) {
            return response()->json(['error' => $ex->getMessage()], 500);
        }
    }

    public function update_role_data()
    {
        try {
            $staff_id = request('staff_id');

            return Staff::updateRoleData($staff_id);
        } catch (\Exception $ex) {
            return response()->json(['error' => $ex->getMessage()], 500);
        }
    }

    public function get_call_vu_data()
    {
        try {
            $responseText = request('responseText');

            return Staff::insertCallVuData($responseText);
        } catch (\Exception $ex) {
            return response()->json(['error' => $ex->getMessage()], 500);
        }
    }

    public function get_response_from_call_vu()
    {
        try {
            // Get Call Vu Data
            $callVuPayload = [
                'Flex360ID' => "35",
            ];

            $callVueSuccess = CallVu::submitPayloadCallVu($callVuPayload, 'CALL_VU_GET_USER_DETAIL');
            return response()->json($callVueSuccess);
        } catch (\Exception $ex) {
            return response()->json(['error' => $ex->getMessage()], 500);
        }
    }

    public function training()
    {
        $data = [];
        $data['user'] = Staff::find(auth()->user()->id);
        return view('admin.candidates.training', $data);
    }

    public function result()
    {
        $data = [];
        return view('admin.candidates.result', $data);
    }

    public function iresult()
    {
        $data = [];
        return view('admin.candidates.iresult', $data);
    }

    public function candidate()
    {
        $data = [];
        $data['make_role_id'] = '5';

        if (request()->ajax()) {
            // Implement the data retrieval logic for AJAX requests here.
        }

        $data['staff_members'] = Staff::where(['active' => 1, 'role' => 1])->get();
        $data['title'] = __('Candidates');
        $data['make_role_lable'] = __('Trainee');

        return view('admin.candidates.candidates', $data);
    }

    public function candidate_list()
    {
        $data = [];
        $data['make_role_id'] = '5';

        if (request()->ajax()) {
            // Implement the data retrieval logic for AJAX requests here.
        }

        $data['roles'] = Roles::all();
        $data['staff_members'] = Staff::where(['active' => 1, 'role' => 1])->get();
        $data['title'] = __('Candidates');
        $data['make_role_lable'] = __('Trainee');

        return view('admin.candidates.candidates', $data);
    }

    public function profile($id)
    {
        $data = [];

        $data['candidate'] = Staff::find($id);
        $data['staff_candidate_columns'] = StaffCandidates::get_columns();
        $data['column_result'] = StaffCandidates::get_column_result($id);
        $data['title'] = $data['candidate']->full_name;

        return view('admin.candidates.candidate_detail', $data);
    }

    public function profile_update($id)
    {
        if (request()->isMethod('post')) {
            $data = request()->input();
            StaffCandidates::updateData($id, $data['option']);
            set_alert('success', __('Data updated successfully'));
            return redirect(admin_url('candidates/profile/' . $id));
        }

        return redirect(admin_url('candidates/candidate_list'));
    }

    public function trainee()
    {
        $data = [];
        $data['make_role_id'] = '2';

        if (request()->ajax()) {
            // Implement the data retrieval logic for AJAX requests here.
        }

        $data['staff_members'] = Staff::where(['active' => 1, 'role' => 5])->get();
        $data['title'] = __('Candidates');
        $data['make_role_lable'] = __('Agent');

        return view('admin.candidates.candidates', $data);
    }

    public function agent()
    {
        $data = [];

        if (request()->ajax()) {
            // Implement the data retrieval logic for AJAX requests here.
        }

        $data['staff_members'] = Staff::where(['active' => 1, 'role' => 2])->get();
        $data['title'] = __('Candidates');

        return view('admin.candidates.candidates', $data);
    }

    public function update_gigwage()
    {
        $staffData = Staff::getStaff(get_staff_user_id());
        $email = explode('@', $staffData->email);

        if ($email[1] == get_option('TESTING_EMAIL')) {
            $response = [
                'message' => __('Updated successfully'),
                'success' => 1,
            ];
            return response()->json($response);
        } else {
            $response = [
                'success' => 0,
                'message' => __('Something went wrong'),
            ];

            $response['dob'] = date('mdY', strtotime(request('dob')));
            $postData = request()->input();

            if (!empty($postData['dob']) && !empty($postData['ssn'])) {
                $staff = Staff::where('staffid', get_staff_user_id())->first();

                // Implement the rest of the logic for updating data

                $response = [
                    'message' => __('Updated successfully', __('Yardstik')),
                    'success' => 1,
                ];

                if (Session::has('_callvue_dob_ssn_data')) {
                    Session::forget('_callvue_dob_ssn_data');
                }

                $callVuPayload = [
                    'FLEX_360_ID' => $staff->staffid,
                    'STATUS' => 'Assessment_Pass',
                    'SUBMIT_FIELD_GLASS_FLAG' => 'Y',
                    'AGENT_DOB' => date('mdY', strtotime($postData['dob'])),
                    'AGENT_SECID' => $postData['ssn'],
                    'CITY_OF_BIRTH' => $postData['city'],
                    'AGENT_CELLPHONE' => (!empty($staff->phonenumber) ? $staff->phonenumber : ''),
                ];

                Session::put('_callvue_dob_ssn_data', $callVuPayload);

                return response()->json($response);
            }
        }
    }

    public function getCallVuCall($decrypted_payload = [], $endpoint_name)
    {
        $staff = Staff::where('staffid', auth()->user()->id)->first();
        if (!empty($staff) && $staff->client_uid != 'gd721s') {
            return false;
        }

        if (request()->hasCookie('_callvu_Flex360ID-' . auth()->user()->id)) {
            $resp = json_decode(request()->cookie('_callvu_Flex360ID-' . auth()->user()->id), true);

            if ($resp['Status'] == 0 && !empty($resp['data'])) {
                $resp_data = json_decode($resp['data'], true);

                if (!empty($resp_data[0]['ID'])) {
                    Staff::where('staffid', auth()->user()->id)->update(['client_uid' => $resp_data[0]['ID']]);
                    request()->cookie()->forget('_callvu_Flex360ID-' . auth()->user()->id);
                } else {
                    request()->cookie()->forget('_callvu_Flex360ID-' . auth()->user()->id);
                }
            }
        } else {
            if (empty($endpoint_name)) {
                return false;
            }

            $endpoint_url = get_option($endpoint_name);

            if (empty($endpoint_name)) {
                return false;
            }

            $decrypted_payload = ['Flex360ID' => auth()->user()->id];

            // Implement the JavaScript logic for sending the encrypted payload to the endpoint here.

            return response('success'); // Replace with your JavaScript logic
        }
    }

    public function access_guard()
    {
        try {
            $data = [];
            $user_data = $this->retrive_user('no');

            if (!empty($user_data)) {
                $data['callVuData'] = $user_data;
            }
            return view('admin.candidates.access_guard', $data);
        } catch (\Exception $ex) {
            return response()->json(['error' => $ex->getMessage()], 500);
        }
    }

    public function payment_dashboard()
    {
        try {
            $data = [];
            return view('admin.candidates.payment_dashboard', $data);
        } catch (\Exception $ex) {
            return response()->json(['error' => $ex->getMessage()], 500);
        }
    }
    public function getAlertIntake(Request $request)
    {
        try {
            $post_data = $request->all();

            if (!empty($post_data)) {
                $global_variable = $post_data['global_variable'];
                $data = json_decode($post_data['response']);
                if (!empty($data)) {
                    $dataArray = json_decode($data, true);
                    if (!empty($dataArray)) {
                        $response_data = json_decode($dataArray['data'], true);
                        return response()->json(['response_data' => $response_data]);
                    }
                }
            }
        } catch (\Exception $ex) {
            return response()->json(['error' => $ex->getMessage()], 500);
        }
    }

    public function glTest()
    {
        try {
            return view('candidates.gl_test');
        } catch (\Exception $ex) {
            return response()->json(['error' => $ex->getMessage()], 500);
        }
    }

    public function glProd()
    {
        try {
            return view('candidates.gl_prod');
        } catch (\Exception $ex) {
            return response()->json(['error' => $ex->getMessage()], 500);
        }
    }

    public function assessmentIframe()
    {
        try {
            return view('candidates.onboard_iframe');
        } catch (\Exception $ex) {
            return response()->json(['error' => $ex->getMessage()], 500);
        }
    }

    public function phoneVerifyModal()
    {
        $response = [];
        return view('candidates.phone_verify_modal', $response);
    }

    public function updatePhoneNumber(Request $request)
    {
        $response = ['status' => 0, 'message' => 'Something went wrong'];
        $post_data = $request->all();

        // ... Your existing code to update the phone number ...

        return response()->json($response);
    }


    public function resendVerificationCode()
    {
        $response = ['status' => 0, 'message' => 'Something went wrong'];
        $post_data = request()->all();

        $check_exists = DB::table('staff')
            ->where('staffid', get_staff_user_id())
            ->whereNotNull('phonenumber')
            ->first();

        if (!empty($check_exists)) {
            $phone_verification_code = rand(111111, 999999);
            $merge_fields = [
                '{staff_firstname}' => $check_exists->firstname,
                '{otp_numbers}' => $phone_verification_code,
            ];

            $sms = new AppSms();
            $sms->trigger(CUSTOM_SMS_SEND, '+1 ' . $check_exists->phonenumber, $merge_fields);

            $response = ['status' => 1, 'verification_code' => $phone_verification_code];
            DB::table('staff')
                ->where('staffid', get_staff_user_id())
                ->update(['phone_verification_code' => $phone_verification_code]);
        } else {
            $response['message'] = 'Invalid phone number';
        }

        return response()->json($response);
    }

    public function verifyPhoneNumber()
    {
        $response = ['status' => 0, 'message' => 'Invalid OTP.'];
        $postData = request()->all();

        if (!empty($postData['staffid']) && !empty($postData['code'])) {
            $check_code = DB::table('staff')
                ->where('staffid', $postData['staffid'])
                ->where('phone_verification_code', $postData['code'])
                ->get()
                ->toArray();

            if ($check_code) {
                $update = [
                    'phone_verification_key' => null,
                    'phone_verification_code' => null,
                    'phone_verified' => 1,
                    'active' => 1,
                ];

                DB::table('staff')
                    ->where('staffid', $postData['staffid'])
                    ->update($update);

                $response['status'] = 1;
                $response['message'] = 'Your phone verification is successful';
            }
        }

        return response()->json($response);
    }

    public function initiateBGV()
    {
        $staff = DB::table('staff')->where('staffid', get_staff_user_id())->first();
        $yardstik = new Yardstik();

        $candidate = $yardstik->addCandidate([
            'email' => $staff->email,
            'first_name' => $staff->firstname,
            'last_name' => $staff->lastname,
            'phone' => '+1' . $staff->phonenumber,
        ]);

        if ($candidate['success']) {
            if (!empty($candidate['success']['id'])) {
                DB::table('staff')
                    ->where('staffid', $staff->staffid)
                    ->update(['yardstik_candidate_id' => $candidate['success']['id']]);

                $response['candidate_message'] = 'Candidate created successfully';

                log_activity($response['candidate_message'], now(), 'for', get_staff_user_id());

                $yardstik_data = DB::table('staff')->where('staffid', get_staff_user_id())->first();

                if (!empty($yardstik_data)) {
                    $invite = $yardstik->inviteCandidate([
                        'yardstik_candidate_id' => $yardstik_data->yardstik_candidate_id,
                    ]);

                    if ($invite['success']) {
                        if (!empty($invite['success']['id'])) {
                            DB::table('staff')
                                ->where('staffid', get_staff_user_id())
                                ->update([
                                    'yardstik_report_url' => $invite['success']['candidate']['reports'][0]['meta']['report_url'],
                                    'yardstik_candidate_application_link' => $invite['success']['candidate']['reports'][0]['meta']['apply'],
                                    'bgv_status' => $invite['success']['status'],
                                    'yardstik_invite_id' => $invite['success']['id'],
                                    'yardstik_report_id' => $invite['success']['report']['id'],
                                ]);

                            $response['invite_message'] = 'Candidate invited successfully';
                            log_activity($response['invite_message'], now(), 'for', get_staff_user_id());
                        }
                    } else {
                        $response['invite_message'] = 'Candidate invite failed';
                        log_activity($response['invite_message'], now(), 'for', get_staff_user_id());
                    }
                }
            }
        } else {
            $response['candidate_message'] = 'Candidate creation failed';
            log_activity($response['candidate_message'], now(), 'for', get_staff_user_id());
        }

        return response()->json($response);
    }

    public function sendNotification($staffid)
    {
        $deviceInfo = DB::table('user_device_info')
            ->select('device_fcm_key')
            ->where('user_id', $staffid)
            ->where('device_fcm_key', '!=', '')
            ->orderBy('id', 'desc')
            ->first();

        if (!empty($deviceInfo->device_fcm_key)) {
            $data = [];
            $email = 'skyovi.com';

            $appsent = send_notifications_template('Background_verification_initiated', $email, $staffid, $data, $deviceInfo->device_fcm_key);
            $req_dump = print_r($appsent, true);

            file_put_contents('debug.log', 'Background_verification_initiated =' . $req_dump, FILE_APPEND);

            return true;
        }

        return false;
    }

    public function contactusList()
    {
        if (!is_admin()) {
            abort(403, 'Access Denied');
        }
        if (request()->ajax()) {
            return $this->app->get_table_data('contactus');
        }

        $data['title'] = __('Contact Us');
        return view('admin.candidates.contactus_list', $data);
    }

    public function saveEncryptedPayload()
    {
        try {
            $response = '';
            $ssnPayload = "false";
            $staff = Staff::find(get_staff_user_id());

            if (empty($staff->callvu_registration_payload) || true) {
                DB::table('staff')
                    ->where('staffid', get_staff_user_id())
                    ->update(['callvu_ssn_payload' => $this->input->post('ssn') == "true" ? $this->input->post('encrypted_payload') : '']);

                $ssnPayload = $this->input->post('ssn') == "true" ? "true" : $ssnPayload;
                $response = $this->callvuGetAgentData($ssnPayload);

                if ($ssnPayload == "true") {
                    $this->initiateBGV();

                    DB::table('staff')
                        ->where('staffid', get_staff_user_id())
                        ->update([
                            'spi' => 2,
                            'role' => 5,
                            'TrainingID' => strtolower(getRandomStringRandomInt(16))
                        ]);

                    $staff = Staff::find(get_staff_user_id());
                    if (!empty($staff->TrainingID)) {
                        add_user_flexez([
                            'firstname' => $staff->firstname,
                            'lastname' => $staff->lastname,
                            'email' => $staff->email,
                            'flexid' => $staff->TrainingID,
                            'userid' => $staff->staffid
                        ]);

                        $userDevice = DB::table('user_device_info')
                            ->select('device_fcm_key')
                            ->where('user_id', get_staff_user_id())
                            ->whereNotNull('device_fcm_key')
                            ->get()
                            ->toArray();

                        $deviceTokens = array_map(function ($v) {
                            return $v->device_fcm_key;
                        }, $userDevice);

                        $sendData = [
                            'title' => get_option('firebase_silent_notification_title'),
                            'message' => get_option('firebase_silent_notification_message'),
                            'device_token' => $deviceTokens
                        ];

                        send_pushnotifications($sendData);
                        send_mail_template('staff_role_change_email', get_staff_user_id(), $staff->email, $staff->role);
                    }
                }

                return response()->json(['status' => 1, 'message' => 'data stored', 'call_vu_response' => $response]);
            } else {
                return response()->json(['status' => 1, 'message' => 'encryption is already generated']);
            }
        } catch (\Exception $ex) {
            return response()->json($ex->getMessage(), 500);
        }
    }

    public function callvuGetAgentData($ssnPayload = null)
    {
        try {
            $staff = Staff::find(get_staff_user_id());

            if (!empty($ssnPayload) && $ssnPayload == "true") {
                $callvuResponsePayload['text'] = "Call Vu Registration API called at update SPI time for staffid = " . get_staff_user_id();
                DB::table('callvu_response')->insert($callvuResponsePayload);
                $this->callvuApiWrapper($staff->callvu_ssn_payload, get_option('CALL_VU_REGISTRATION'), $staff->email);

                $nullData = ['callvu_ssn_payload' => null];
                DB::table('staff')->where('staffid', get_staff_user_id())->update($nullData);
                return true;
            } else {
                $callvuResponsePayload['text'] = "Call Vu Registration API called at registration time for staffid = " . get_staff_user_id();
                DB::table('callvu_response')->insert($callvuResponsePayload);
                return $this->callvuApiWrapper($staff->callvu_registration_payload, get_option('CALL_VU_REGISTRATION'), $staff->email);
            }
        } catch (\Exception $th) {
            return response()->json($th->getMessage(), 500);
        }
    }

    public function saveAssessmentPayload()
    {
        try {
            $response = [];
            $staff = Staff::find(get_staff_user_id());

            if (!empty($this->input->post('staffid'))) {
                $staff = Staff::find($this->input->post('staffid'));
            }

            $callvuResponsePayload['text'] = get_staff_user_id() . " Data " . $staff->callvu_assessmentresult_payload;
            DB::table('callvu_response')->insert($callvuResponsePayload);

            if (empty($staff->callvu_assessmentresult_payload) || true) {
                $data = [
                    'callvu_assessmentresult_payload' => $this->input->post('encrypted_payload'),
                ];

                if (!empty($this->input->post('column_name'))) {
                    $data = [
                        $this->input->post('column_name') => $this->input->post('encrypted_payload'),
                    ];
                }

                DB::table('staff')
                    ->where('staffid', $staff->staffid)
                    ->update($data);

                if (empty($this->input->post('column_name'))) {
                    $callvuResponsePayload['text'] = "Call Vu Registration API called when assessment result passed or failed staffid = " . get_staff_user_id();
                    DB::table('callvu_response')->insert($callvuResponsePayload);
                    $response = $this->callvuApiWrapper($staff->callvu_assessmentresult_payload, get_option('CALL_VU_REGISTRATION'), $staff->email);
                }

                return response()->json(['status' => 1, 'message' => 'data stored', 'call_vu_response' => $response]);
            } else {
                return response()->json(['status' => 1, 'message' => 'encryption is already generated']);
            }
        } catch (\Exception $ex) {
            return response()->json($ex->getMessage(), 500);
        }
    }

    public function callvuApiWrapper($payload, $endpointName, $email = null)
    {
        try {
            if (!empty($email)) {
                $email = explode('@', $email);
                if ($email[1] == get_option('TESTING_EMAIL')) {
                    $updateTestData = ['test_user' => '1'];
                    DB::table('staff')->where('staffid', get_staff_user_id())->update($updateTestData);
                    return true;
                }
            }

            $url = $endpointName;
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            $result = curl_exec($ch);

            if ($result === false) {
                return ['status' => 'false', 'error' => curl_error($ch)];
            }
            curl_close($ch);
            return $result;
        } catch (\Exception $th) {
            return response()->json($th->getMessage(), 500);
        }
    }

    public function chat()
    {
        return view('admin.candidates.chat_window');
    }

    public function demoPWA()
    {
        return view('admin.candidates.demo_pwa');
    }

    public function showTraining()
    {
        return view('admin.candidates.training_app');
    }

    public function downloadFlex360()
    {
        return view('admin.candidates.downloadflex360');
    }


    public function getCallVuDataByDynamicPayload()
    {
        try {
            $payload = "Zz+7HZzE9H+gohCLhoJt+3Um16WuAaDLH8kE+WsSVm2HEo19djFcwzL9lvk10eDB3P948LTc+mcmRenLsscOJGdEGq3bUl0AApnifdqyiqgR8GRp/EasKGqydwkRnfI2gWX1+t6L5OQnDYu82XIUyrrRiegPCIE0Y/KmZAr09KASpL00Y2JEV/PxEQHMW7lqfeTPNr8poiGRPk+aEZgRlsgdopFY9HcFptJu59ouYShXjtrozA62RPujxD48uEWn481/UJhuwN+9K6bNYwaPUyRb4ZnLsvG0NA0cLs+HwKQy89aYhj7mNN3EfP+JDsvIR9pgyNvNrA5Sys1v/wIDcGlY/XnnCsBlibp/gM4m2ec21wPzIBPRI26lFF8W5lmrzyszd8xJM0k0ADA1vhXlb25IuR34HRJ+6K3vAtbR7jcUTPHWvRsTV+BKFmm0b0Zq6LS/PAgKU+S2Lgl17STcTkRsILwr6QMaVxo4M/uWB4h8lK7BuciBxug32aYg4iKYSB7nrpb9/9M/aY6OmMmbE14SwhrcrIWLXRxzghi+z4ioAQaQkWFIz+cO0PffUbgdaZ0EamzKU+oKbZsDeQz2771+eJ9BJWTtrIn68D5e6FlbamQffkLYES+QnR9QYvVqEXXbw4vnzwj5YGl+TupnvwImklhniySC4iFtzcpbYEY=";

            $url = config('yourconfig.call_vu_verified_call_and_payments_url');

            $response = Http::post($url, ['payload' => $payload]);

            if ($response->successful()) {
                $result = $response->json();

                if ($result['Status'] == 0 && !empty($result['data'])) {
                    $respData = json_decode($result['data'], true);

                    if (!empty($respData[0]['ID'])) {
                        // Update the database with client_uid
                        // Example: YourModel::where('staffid', auth()->user()->staffid)->update(['client_uid' => $respData[0]['ID']]);
                    }
                }

                // Return the result or use it as needed
                return response()->json($result);
            }
        } catch (\Exception $th) {
            // Handle exceptions as needed
            return response()->json(['error' => $th->getMessage()], 500);
        }
    }

    public function webhookRequest(Request $request)
    {
        $data = $request->only([
            'id',
            'event',
            'resource_id',
            'account_id',
            'account_name',
            'resource_type',
            'resource_url',
        ]);

        $user = auth()->user();
        if ($user) {
            WebhookRequest::updateOrCreate(
                ['staffid' => $user->id],
                $data
            );
        }
    }

    public function getAllInvite()
    {
        $apiUrl = 'https://api.yardstik-staging.com/invitations';
        $apiKey = '23bbb807d621c38d430c6f7b4435e88a80075500';

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Account ' . $apiKey,
        ])->get($apiUrl);

        if ($response->successful()) {
            return $response->json();
        }

        return response()->json(['error' => $response->json()], 500);
    }

    public function getInvitationStatus()
    {
        $user = auth()->user();
        $yardstikInviteId = $user->yardstik_invite_id;

        if ($yardstikInviteId) {
            $yardstik = new Yardstik(); // Replace with your Yardstik library
            $response = $yardstik->getInviteById($yardstikInviteId);

            if ($response && isset($response['success']['status'])) {
                $status = $response['success']['status'];

                $user->update([
                    'bgv_status' => $status,
                ]);

                return $status;
            }
        }

        return 'Status not available';
    }

    public function getBGVStatus()
{
    $staff = get_staff(get_staff_user_id());
    $newStatus = $staff->bgv_status;
    $i = ($newStatus == 'INVITATION_invite_sent' || $newStatus == 'INVITATION_viewed' || $newStatus == 'INVITATION_clicked' || $newStatus == 'REPORT_created') ? 0 : 1;
    return $i;
}

public function updateCandidateUID()
{
    try {
        $candidateDetails = DB::table('staff')
            ->select('staffid', 'callvu_get_user_details_payload')
            ->where('spi', '2')
            ->where('client_uid', NULL)
            ->whereNotNull('callvu_get_user_details_payload')
            ->get();

        if (!$candidateDetails->isEmpty()) {
            foreach ($candidateDetails as $singleCandidate) {
                $payload = $singleCandidate->callvu_get_user_details_payload;
                $url = config('your_app.CALL_VU_GET_USER_DETAIL'); // Set your configuration key

                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                $result = curl_exec($ch);

                if ($result === false) {
                    return ['status' => 'false', 'error' => curl_error($ch)];
                }

                curl_close($ch);
                $resultArray = json_decode($result, true);

                if (!empty($resultArray) && $resultArray['Status'] == 0 && !empty($resultArray['data'])) {
                    $respData = json_decode($resultArray['data'], true);

                    if (!empty($respData[0]['ID'])) {
                        DB::table('staff')
                            ->where('staffid', $singleCandidate->staffid)
                            ->update(['client_uid' => $respData[0]['AGENT_ATTUID']]);
                    }
                }

                print_r($result);
            }
        }
    } catch (\Exception $ex) {
        echo "<pre>";
        print_r($ex);
    }
}

// public function downloadApp()
// {
//     $data = [];
//     $data['title'] = 'Download Application';
//     return view('candidates.download_app', $data);
// }

// public function getCourseResult($email)
// {
//     $courseProgress = 0;

//     $query = DB::table('training_resource_progress_trn')
//         ->select('progress')
//         ->where('email', $email)
//         ->join('tbltraining_courses_ref', 'tbltraining_courses_ref.resource_id', '=', 'training_resource_progress_trn.content_id')
//         ->where('tbltraining_courses_ref.is_active', 0)
//         ->get();

//     foreach ($query as $record) {
//         $courseProgress += $record->progress;
//     }

//     $courses = DB::table('tbltraining_courses_ref')
//         ->selectRaw('COUNT(row_id) as title_count')
//         ->where('is_active', 0)
//         ->first();

//     $titleCount = $courses->title_count;

//     $avgCourseProgress = ($titleCount > 0) ? ($courseProgress / $titleCount) * 100 : 0;

//     return ['avg' => $avgCourseProgress, 'total_cnt' => $titleCount, 'progress_cnt' => $courseProgress];
// }

// public function updateTrainingResult()
// {
//     try {
//         $emails = DB::table('tbltraining_resource_progress_trn')
//             ->select(DB::raw('DISTINCT(email)'))
//             ->get()
//             ->pluck('email');

//         foreach ($emails as $email) {
//             $finalResult = $this->getCourseResult($email);
//             $avg = $finalResult['avg'];
//             $totalCount = $finalResult['total_cnt'];
//             $progressCount = floor($finalResult['progress_cnt']);
//             $finalString = $progressCount . "/" . $totalCount . " (" . round($avg) . "%");
//             DB::table('staff')
//                 ->where('email', $email)
//                 ->update([
//                     'training_result' => $finalString,
//                     'training_avg' => round($avg),
//                     'role' => round($avg) == 100 ? '2' : null,
//                 ]);
//         }

//         return "Data Updated";
//     } catch (\Exception $ex) {
//         return response()->json($ex);
//     }
// }




public function ddSetup()
{
    $data = [];
    $data['user'] = Auth::user(); // Assuming you're using Laravel's built-in authentication
    if (empty($data['user']->gigwage_id)) {
        session()->flash('danger', __('Gigwage id not generated'));
        return redirect()->route('admin.dashboard'); // Replace with your admin dashboard route
    }
    return view('candidates.ddsetup', $data);
}

public function ddSetupSubmit(Request $request)
{
    $post_data = $request->all();
    $gigwage = new Gigwage(); // Assuming you have a Gigwage class or service
    $contractorResponse = $gigwage->addBankAccount(Auth::user()->gigwage_id, $post_data);

    $response = [
        'status' => 0,
    ];

    if ($contractorResponse['success']) {
        if (isset($contractorResponse['success']['error'])) {
            $response['message'] = $contractorResponse['success']['error'];
        } else {
            $response['status'] = 1;
            $response['message'] = __('Bank Account added successfully');
            DB::table('staff')
                ->where('staffid', Auth::id())
                ->update(['bank_ddsetup' => 1]);
        }
    }

    return response()->json($response);
}

public function chatInitPushNotification()
{
    $response = [
        'status' => 0,
    ];

    if (Auth::check()) {
        $email = Auth::user()->email;
        $query = DB::table('sb_messages as m')
            ->join('sb_users as u', 'u.id', '=', 'm.user_id')
            ->where('u.email', $email)
            ->whereDate('m.creation_time', now()->toDateString())
            ->first();

        if ($query) {
            $userDevice = DB::table('user_device_info')
                ->select('device_fcm_key', 'user_id')
                ->join('staff', 'staff.staffid', '=', 'user_device_info.user_id')
                ->where('device_fcm_key', '!=', '')
                ->whereNotNull('device_fcm_key')
                ->where('FLEXPERT', 'Y')
                ->get()
                ->toArray();

            if (!empty($userDevice)) {
                $sendData = [
                    'title' => __('FLEXPERT chat init'),
                    'message' => $query->message,
                    'device_token' => array_column($userDevice, 'device_fcm_key'),
                ];

                // Send push notifications here
                // Replace this with your push notification implementation
                // send_push_notifications($sendData);

                $response['status'] = 1;
            } else {
                $response['status'] = 2;
            }
        }
    }

    return response()->json($response);
}

public function sendInvitation($contractor_id = null)
{
    if (empty($contractor_id)) {
        return 'Please enter contractor id';
    }

    $gigwage = new Gigwage(); // Assuming you have a Gigwage class or service
    $gigwageData = $gigwage->sendInvitation($contractor_id);

    if ($gigwageData['success'] && isset($gigwageData['success']['invitation'])) {
        DB::table('staff')
            ->where('gigwage_id', $contractor_id)
            ->update(['gigwage_invitation_token' => $gigwageData['success']['invitation']['token']]);
    }

    return response()->json($gigwageData);
}













}
