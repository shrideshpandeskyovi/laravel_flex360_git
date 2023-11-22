<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Mail\StaffVerificationEmail;
use App\Models\Staff;
use QRCode;

class StaffCandidatesController extends Controller
{
    public function index()
    {
        return redirect(route('admin.index'));
    }

    public function admin()
    {
        return view('admin.index'); // Adjust the view path as needed
    }

    public function signup($id = '')
    {
        $data['qr_file'] = 'assets/images/site_qr.png';
        $is_reset = false;

        if (request()->isMethod('post')) {
            // Handle form submission and validation here
            // ...

            // Example: generate a random password
            $password = str_random(8);
            $hashedPassword = Hash::make($password);

            // Example: send email
            // Mail::to(request('email'))->send(new StaffVerificationEmail($password));

            // Example: generate QR code
            // ...

            // Example: redirect to a different route
            return redirect(route('admin.index'));
        }

        // Example: retrieve user data
        $userData = Staff::find($id);

        // Example: check email verification key
        if (!$userData || $userData->email_verification_key === null) {
            return redirect(route('admin.index'));
        }

        // Example: send verification email
        // Mail::to($userData->email)->send(new StaffVerificationEmail($userData->firstname, $userData->lastname, $id));

        // Example: set session data
        $signupData = [
            'email' => $userData->email,
            'password' => $password,
            'staff_id' => $id,
            'firstname' => $userData->firstname,
            'lastname' => $userData->lastname,
            'phone_verification_code' => rand(111111, 999999),
        ];
        session(['_staff_signup_data' => $signupData]);

        // Example: generate QR code paths
        $qrIos = '/assets/images/qr_ios.png';
        $qrAndroid = '/assets/images/qr_android.png';
        if (!file_exists(public_path($qrIos))) {
            // Generate QR code for iOS
            // QRCode::png('data for iOS', public_path($qrIos));
        }

        if (!file_exists(public_path($qrAndroid))) {
            // Generate QR code for Android
            // QRCode::png('data for Android', public_path($qrAndroid));
        }

        $isReset = true;

        return view('admin.candidates.candidate_signup', compact('isReset', 'data', 'qrIos', 'qrAndroid'));
    }
    public function userVerify(Request $request)
{
    $postData = $request->all();
    $response = [
        'status' => 0,
        'message' => 'Something went wrong..., please try again',
    ];

    if (!empty($postData['staffid']) && !empty($postData['type']) && !empty($postData['code'])) {
        $check_code = DB::table('staff')
            ->where('staffid', $postData['staffid'])
            ->where($postData['type'] . '_verification_code', $postData['code'])
            ->get()
            ->toArray();

        if (!empty($check_code)) {
            if ($postData['type'] == 'phone') {
                $update = [
                    'phone_verification_key' => null,
                    'phone_verification_code' => null,
                    'phone_verified' => 1,
                    'active' => 1,
                ];
                $response['is_required_phone_verify'] = false;
            } elseif ($postData['type'] == 'email') {
                $update = [
                    'email_verification_key' => null,
                    'email_verification_code' => null,
                    'email_verified_at' => now(),
                ];
                $response['is_required_phone_verify'] = true;
            }

            DB::table('staff')
                ->where('staffid', $postData['staffid'])
                ->update($update);

            $check_exist = DB::table('staff')
                ->where('staffid', $postData['staffid'])
                ->first();

            if (!empty($check_exist)) {
                if (empty($check_exist->email_verification_key)) {
                    $this->session->forget('openid_reg');

                    // Add candidate as contractor on Call Vue start
                    $callVu_payload = [
                        // Populate the payload fields as needed
                    ];
                    $end_point_name = "CALL_VU_REGISTRATION";
                    // $callvue_success = $this->callvu->submit_payload($callVu_payload, $end_point_name);
                    // Add candidate as contractor on Call Vue end
                }

                // Check check_app_download or not
                $app_downloaded = 0;

                $check_app_download = DB::table('user_device_info')
                    ->where('user_id', $postData['staffid'])
                    ->whereNotNull('device_imei')
                    ->first();

                if (!empty($check_app_download)) {
                    $app_downloaded = 1;
                }

                $response['app_downloaded'] = $app_downloaded;
                $response['status'] = 1;
                $response['message'] = 'Your ' . $postData['type'] . ' verification is successful';

                $user_agent = $request->header('User-Agent');
                $is_apple_device = strpos($user_agent, 'iPhone') !== false
                    || strpos($user_agent, 'iPad') !== false
                    || strpos($user_agent, 'iPod') !== false
                    || strpos($user_agent, 'Macintosh') !== false;

                $is_android_device = strpos($user_agent, 'Android') !== false;
                $is_windows_device = strpos($user_agent, 'Windows') !== false;

                if ($is_apple_device || $is_android_device) {
                    $response['user_agent'] = true;
                }
            } else {
                $response['app_downloaded'] = 0;
                $response['message'] = 'Invalid Code';
                $response['user_agent'] = false;
            }
        }
    }

    return response()->json($response);
    // Redirect if needed
    // return redirect()->route('authentication');
}
public function downloadFlex360app()
{
    $data['staff'] = get_staff(get_staff_user_id());
    return view('admin.qrauth.appdownload', $data);
}

public function downloadFlex360appweb()
{
    return view('admin.qrauth.appdownloadweb');
}

public function sendNotifications()
{
    $fcmTokens = DB::table('user_device_info')
        ->select('device_fcm_key')
        ->whereNotNull('device_fcm_key')
        ->orderBy('id', 'desc')
        ->get()
        ->pluck('device_fcm_key');

    foreach ($fcmTokens as $fcm) {
        $id = session('staff_user_id');
        $data = [];
        $email = 'skyovi.com';
        $appsent = send_notifications_template('Staff_welcome_message', $email, $id, $data, $fcm);
        $req_dump = print_r($appsent, true);
        file_put_contents('NotificationTest.log', $fcm . '\n' . now() . '\n ' . $req_dump, FILE_APPEND);
    }

    return 'Notification sent';
}

public function tresult()
{
    return view('admin.candidates.t_result');
}

public function presult()
{
    return view('admin.candidates.p_result');
}

public function callvu_sample_test()
{
    $callVuPayload = [
        // Populate the payload fields as needed
    ];

    $endPointName = 'https://opus-callvu-qc2.att.net/WS_FlexLiteAPIV1/api/flex/GetResponse';

    $callvuResponse = app('callvu')->submit_payload($callVuPayload, $endPointName);

    // If you are using a library or helper function for HTTP requests, you can replace the above line with:
    // $callvuResponse = send_request($endPointName, 'POST', $callVuPayload);

    return response()->json($callvuResponse);
}
public function deviceSecurity()
{
    $data['title'] = 'Device & Security';
    return view('admin.candidates.device_security', $data);
}

public function downloadApp()
{
    return view('admin.candidates.downloadapp');
}

public function resendVerificationEmail(Request $request)
{
    $staffId = $request->input('staff_id');
    $response = [];
    $response['status'] = 0;
    $response['message'] = _l('something_went_wrong');

    if (!empty($staffId)) {
        $staffDetail = DB::table('staff')->where('staffid', $staffId)->first();

        if (!empty($staffDetail)) {
            $verificationCode = !empty($staffDetail->email_verification_code) ? $staffDetail->email_verification_code : rand(111111, 999999);

            $updateData = [
                'email_verification_code' => $verificationCode,
                'email_verification_key' => app_generate_hash(),
                'email_verification_sent_at' => now(),
            ];

            DB::table('staff')->where('staffid', $staffId)->update($updateData);

            if (!empty($verificationCode)) {
                // Assuming you have a function to send mail, replace the following line accordingly
                send_mail_template('staff_verification_email', $staffDetail->email, $staffDetail->staffid, $verificationCode);
            }

            $response['status'] = 1;
            $response['message'] = _l('Email verification send successfully');
        }
    }

    return response()->json($response);
}
}
