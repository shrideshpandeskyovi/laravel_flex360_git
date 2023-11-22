<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Auth;
use App\Models\Authentication;
use App\Models\UserDeviceInfo;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\View;

class AuthController extends Controller 
{
  protected $authenticationModel;
    protected $userDeviceInfoModel;

    public function __construct(Authentication $authenticationModel, UserDeviceInfo $userDeviceInfoModel)
    {
        $this->authenticationModel = $authenticationModel;
        $this->userDeviceInfoModel = $userDeviceInfoModel;
    }

    // public function __construct()
    // {
    //     $this->middleware('guest')->except('logout');

    //     $this->loadLangFiles();
        
    //     Validator::extend('custom_rule', function($attribute, $value) {
    //        // Custom validation logic
    //     });
    // }
    public function index(Request $request)
    {
      if ($request->cookie('remember')) {
        $email = $request->cookie('email');
        $remember = $request->cookie('remember');
        $password = $request->post('password');
  
        if (Auth::attempt(['email' => $email, 'password' => $password], $remember)) {
            
          return redirect()->intended(route('dashboard'));
  
        } else {
  
          return view('auth.login')->with(['title' => 'Login']);
  
        }
  
      } else {
        return $this->login();
      }
    }
  
    public function admin()
    {
        if (Auth::check()) {
            return redirect()->route('admin.dashboard'); // Replace 'admin.dashboard' with your actual admin dashboard route.
        }

        $request = request();

        $this->validate($request, [
            'password' => 'required',
            'email' => 'required|email',
            // Add the recaptcha validation rule if needed
        ]);

        $credentials = $request->only('email', 'password');
        $remember = $request->input('remember');

        if (Auth::attempt($credentials, $remember)) {
            // Authentication successful
            // Perform your post-login logic here

            // Store user device info
            $deviceInfo = [
                'user_id' => Auth::id(),
                'timezone' => $request->input('timezone'),
                'browser' => $request->server('HTTP_USER_AGENT'),
                'ip_address' => $request->ip(),
            ];

            // Save the device info to your model
            $this->userDeviceInfoModel->create($deviceInfo);

            // Handle announcements and redirection as needed
            // ...

            return redirect()->route('admin.candidates'); // Replace 'admin.candidates' with your desired route.
        }

        return view('authentication.login_admin', ['title' => __('admin_auth_login_heading')]);
    }


    public function twoFactor($type = 'email')
    {
        if (!Session::has('_two_factor_auth_established')) {
            abort(404);
        }

        $data = [];

        if (request()->isMethod('post')) {
            try {
                $this->validate(request(), [
                    'code' => 'required',
                ]);

                $code = request('code');
                $code = trim($code);
                $email = Session::get('_two_factor_auth_staff_email');

                if ($this->authenticationModel->isTwoFactorCodeValid($code, $email) && $type === 'email') {
                    Session::forget('_two_factor_auth_staff_email');
                    $user = $this->authenticationModel->getUserByTwoFactorAuthCode($code);
                    $this->authenticationModel->clearTwoFactorAuthCode($user->staffid);
                    $this->authenticationModel->twoFactorAuthLogin($user);
                    Session::forget('_two_factor_auth_established');
                    $this->authenticationModel->setAnnouncementsAsReadExceptLastOne($user->staffid, true);

                    // Redirect to the desired page after successful login
                    return redirect()->route('admin.dashboard'); // Replace with your desired route
                } elseif ($this->authenticationModel->isGoogleTwoFactorCodeValid($code) && $type === 'app') {
                    $user = $this->authenticationModel->getUserByTfaStaffId(Session::get('tfa_staffid'));
                    $this->authenticationModel->twoFactorAuthLogin($user);
                    Session::forget('_two_factor_auth_established');
                    $this->authenticationModel->setAnnouncementsAsReadExceptLastOne($user->staffid, true);

                    // Redirect to the desired page after successful login
                    return redirect()->route('admin.dashboard'); // Replace with your desired route
                } else {
                    // Handle invalid code
                    log_activity('Failed Two-factor authentication attempt [Staff Name: ' . auth()->user()->name . ', IP: ' . request()->ip() . ']');

                    return redirect()->route('authentication.twoFactor', ['type' => $type])
                        ->with('error', 'Invalid two-factor authentication code.');
                }
            } catch (ValidationException $e) {
                return back()->withErrors($e->errors())->withInput();
            }
        }

        return view('authentication.set_two_factor_auth_code', $data);
    }

    public function forgotPassword()
    {
        if (auth()->check()) {
            return redirect()->route('admin.dashboard'); // Redirect to the dashboard if a user is already logged in
        }

        if (request()->isMethod('post')) {
            try {
                $validator = Validator::make(request()->all(), [
                    'email' => 'required|email|exists:users,email',
                ]);

                if ($validator->fails()) {
                    return redirect()->route('authentication.forgotPassword')
                        ->withErrors($validator)
                        ->withInput();
                }

                $email = request('email');
                $success = $this->authenticationModel->forgotPassword($email, true);

                if (is_array($success) && isset($success['memberinactive'])) {
                    return redirect()->route('authentication.forgotPassword')
                        ->with('error', 'Inactive account.');
                } elseif ($success === true) {
                    return redirect()->route('authentication.forgotPassword')
                        ->with('success', 'Check your email for instructions on resetting your password.');
                } else {
                    return redirect()->route('authentication.forgotPassword')
                        ->with('error', 'Error while setting a new password key.');
                }
            } catch (ValidationException $e) {
                return redirect()->route('authentication.forgotPassword')
                    ->withErrors($e->errors())
                    ->withInput();
            }
        }

        return view('authentication.forgot_password');
    }

    public function resetPassword($staff, $userid, $new_pass_key)
    {
        if (!$this->authenticationModel->canResetPassword($staff, $userid, $new_pass_key)) {
            return redirect()->route('authentication.index')
                ->with('error', 'Password reset key expired.');
        }

        if (request()->isMethod('post')) {
            try {
                $validator = Validator::make(request()->all(), [
                    'password' => 'required',
                    'passwordr' => 'required|same:password',
                ]);

                if ($validator->fails()) {
                    return redirect()->route('authentication.resetPassword', [$staff, $userid, $new_pass_key])
                        ->withErrors($validator)
                        ->withInput();
                }

                hooks()->do_action('beforeUserResetPassword', [
                    'staff' => $staff,
                    'userid' => $userid,
                ]);

                $success = $this->authenticationModel->resetPassword($staff, $userid, $new_pass_key, request('passwordr', false));

                if (is_array($success) && $success['expired'] === true) {
                    return redirect()->route('authentication.index')
                        ->with('error', 'Password reset key expired.');
                } elseif ($success === true) {
                    hooks()->do_action('afterUserResetPassword', [
                        'staff' => $staff,
                        'userid' => $userid,
                    ]);
                    return redirect()->route('authentication.index')
                        ->with('success', 'Password reset successful.');
                } else {
                    return redirect()->route('authentication.index')
                        ->with('error', 'Password reset failed.');
                }
            } catch (ValidationException $e) {
                return redirect()->route('authentication.resetPassword', [$staff, $userid, $new_pass_key])
                    ->withErrors($e->errors())
                    ->withInput();
            }
        }

        return view('authentication.reset_password');
    }

    public function setPassword($staff, $userid, $new_pass_key)
    {
        if (!$this->authenticationModel->canSetPassword($staff, $userid, $new_pass_key)) {
            $alertType = 'danger';
            $alertMessage = 'Password reset key expired.';
            $redirectRoute = $staff == 1 ? 'authentication.index' : 'home';

            return redirect()->route($redirectRoute)
                ->with($alertType, $alertMessage);
        }

        if (request()->isMethod('post')) {
            try {
                $validator = Validator::make(request()->all(), [
                    'password' => 'required',
                    'passwordr' => 'required|same:password',
                ]);

                if ($validator->fails()) {
                    return redirect()->route('authentication.setPassword', [$staff, $userid, $new_pass_key])
                        ->withErrors($validator)
                        ->withInput();
                }

                $success = $this->authenticationModel->setPassword($staff, $userid, $new_pass_key, request('passwordr', false));

                if (is_array($success) && $success['expired'] === true) {
                    $alertType = 'danger';
                    $alertMessage = 'Password reset key expired.';
                } elseif ($success === true) {
                    $alertType = 'success';
                    $alertMessage = 'Password reset successful.';
                } else {
                    $alertType = 'danger';
                    $alertMessage = 'Password reset failed.';
                }

                $redirectRoute = $staff == 1 ? 'authentication.index' : 'home';

                return redirect()->route($redirectRoute)
                    ->with($alertType, $alertMessage);
            } catch (ValidationException $e) {
                return redirect()->route('authentication.setPassword', [$staff, $userid, $new_pass_key])
                    ->withErrors($e->errors())
                    ->withInput();
            }
        }

        return view('authentication.set_password');
    }


    public function logout()
    {
        Cookie::forget('email');
        Cookie::forget('remember');
        Cookie::forget('loginTime');
        Cookie::forget('is_downloadpopupshown');

        $isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';

        // Set the Secure attribute if the request is secure
        $cookieSecure = $isSecure;

        // Set the HttpOnly attribute for added security
        $cookieHttpOnly = true;

        $is_logout_cookie = cookie('is_logout', '1', 60 * 60 * 24 * 31 * 2, null, 'cortex.attflex.com', $cookieSecure, $cookieHttpOnly);
        return response()
            ->withCookie($is_logout_cookie)
            ->view('authentication.logout');

        Session::flush();

        $id_token_session = Session::get('id_token');

        hooks()->do_action('after_user_logout');

        $oidc = new OpenIDConnectClient(
            config('services.keycloak.url') . '/' . config('services.keycloak.realm'),
            config('services.keycloak.client_id'),
            config('services.keycloak.client_secret')
        );

        $oidc->signOut($id_token_session, route('openid-connect'));
    }

    // ----------------------------------***********__________________-------
    // use Illuminate\Support\Facades\Validator;
    // Open the app/Providers/AppServiceProvider.php file (if it doesn't exist, create it).

    // In the boot method of the AppServiceProvider, add the custom validation rule using the Validator facade:
public function boot()
{
    Validator::extend('email_exists', function ($attribute, $value, $parameters, $validator) {
        $total_rows = DB::table('staff')->where('email', $value)->count();

        if ($total_rows === 0) {
            return false;
        }

        return true;
    });
}

// Now, you can use the email_exists validation rule in your Laravel controllers or requests. For example, in a request class:

// use Illuminate\Foundation\Http\FormRequest;

// class YourRequestClass extends FormRequest
// {
//     public function rules()
//     {
//         return [
//             'email' => 'required|email|email_exists',
//             // Other validation rules
//         ];
//     }z
// }

    // ----------------------------------*****i dont know what is going on ******__________________-------



    public function getQRCode()
{
    if (!auth()->check()) {
        abort(403, 'Unauthorized');
    }

    $companyName = str_replace(':', '-', setting('companyname'));

    if (empty($companyName)) {
        // Colons are not allowed in the issuer name
        $companyName = str_replace(['http://', 'https://'], '', config('app.url')) . ' - CRM';
    }

    $qrCodeData = app('App\Services\TwoFactorAuthService')->getQRCodeData(auth()->user(), $companyName);

    return QRCode::text($qrCodeData)
        ->setSize(200)
        ->svg();
}

public function generateQR()
{
    if (!request()->isMobile() && session('app_source') !== "FLEX360_APP") {
        $qrAuthenticationModel = new QRAuthenticationModel();
        
        $uniqueQRCode = 'Flex360-' . Str::random(64);
        $verificationCode = $this->randomNumber();

        $qrAuthenticationModel->add([
            'qr_code' => $uniqueQRCode,
            'verification_code' => $verificationCode,
        ]);

        session(['unique_qrcode' => $uniqueQRCode, 'verification_code' => $verificationCode]);

        $deviceInfo = DB::table('user_device_info')
            ->select('device_fcm_key')
            ->where('user_id', session('staff_user_id'))
            ->whereNotNull('device_fcm_key')
            ->orderByDesc('id')
            ->first();

        // session(['device_fcm_key' => $deviceInfo->device_fcm_key]); // Uncomment this line if needed

        return $uniqueQRCode;
    }
}

public function randomNumber()
{
    return rand(101, 199);
}

// ------------------?
// Helper function
function randomStrings($lengthOfString)
{
    $strResult = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    return substr(str_shuffle($strResult), 0, $lengthOfString);
}
// ------------------?
public function validateQrCode()
{
    $qrCode = request()->input('qr_code');
    $qrAuthenticationModel = new QRAuthenticationModel();

    $resultSet = $qrAuthenticationModel->validateQrCode(session('staff_user_id'), 'attflex');

    if ($resultSet['status'] === "TRUE") {
        Cookie::queue('loginTime', now()->format('Y-m-d H:i:s'), $this->exp_time);
    }

    return response()->json($resultSet);
}

public function adminValidateQrCode()
{
    $qrAuthenticationModel = new QRAuthenticationModel();
    $resultSet = $qrAuthenticationModel->adminQrValidate(session('staff_user_id'), 'attflex');

    // Add logic or return statement as needed

    // For example, returning a JSON response
    return response()->json($resultSet);
}
public function verifySsn(Request $request)
{
    if (!$request->session()->has('_ssn_auth_staff_email')) {
        abort(404);
    }

    $staffModel = new Staff(); // Adjust the model namespace and name

    $checkExist = $staffModel->where('active', 1)
        ->where('email', $request->session()->get('_ssn_auth_staff_email'))
        ->get();

    if ($checkExist->isNotEmpty()) {
        if ($checkExist[0]->spi == 2) {
            return redirect(admin_url());
        }

        $data = [];
        $data['isReset'] = false;

        $this->validate($request, [
            'ssn' => 'required',
            'dob' => 'required|date',
        ]);

        if ($request->isMethod('post')) {
            if ($request->input('ssn') && $request->input('dob')) {
                $email = $request->session()->get('_ssn_auth_staff_email');
                $dob = $request->input('dob');
                $ssn = $request->input('ssn');

                $update = $staffModel->where('email', $email)
                    ->update([
                        'spi' => 2,
                    ]);

                if ($update) {
                    $checkExist = $staffModel->where('active', 1)
                        ->where('email', $email)
                        ->get();

                    if ($checkExist->isNotEmpty()) {
                        // Your logic for updating GigWage and other operations

                        $request->session()->forget('_ssn_auth_staff_email');
                        set_alert('success', _l('updated_successfully', _l('SSN & BOB')));

                        return redirect(admin_url());
                    }
                }

                $data['isReset'] = true;
            }
        }
    }

    return view('authentication.spi_dob', $data);
}
public function verifyCode()
{
    $qrAuthenticationModel = new QRAuthentication(); // Adjust the model namespace and name

    $resultSet = $qrAuthenticationModel->verifyCode();

    if ($resultSet['status'] == "TRUE") {
        setcookie("loginTime", date('Y-m-d H:i:s'), $this->exp_time); // Assuming $this->exp_time is defined
    }

    return response()->json($resultSet);
}

public function home()
{
    $data['title'] = _l('admin_auth_login_heading'); // Assuming _l() is a translation function
    return view('authentication.login_home', $data);
}

public function keycloakLogout()
{
    // Load the required libraries (this is usually done in the constructor)

    // NOTE: assumes that $this->oidc is an instance of OpenIDConnectClient()
    if ($this->oidc->verifyLogoutToken()) {
        $sid = $this->oidc->getSidFromBackChannel();

        if (isset($sid)) {
            // Somehow find the session based on the $sid and
            // destroy it. This depends on your RP's design,
            // there is nothing in the OIDC spec to mandate how.
            //
            // In this example, we find a Redis key, which was
            // previously stored using the sid we obtained from
            // the access token after login.
            //
            // The value of the Redis key is that of the user's
            // session ID specific to this hypothetical RP app.
            //
            // We then switch to that session and destroy it.

            $redis = Redis::connection(); // Assumes you have Redis configured

            $session_id_to_destroy = $redis->get($sid);

            if ($session_id_to_destroy) {
                session_commit();
                session_id($session_id_to_destroy); // switches to that session
                session_start();
                $_SESSION = array(); // effectively ends the session
            }
        }
    }
}


public function generateQRAjax()
{
    try {
        $loginTimeCookie = Cookie::get('loginTime');

        if (null != $loginTimeCookie) {
            $pastTime = strtotime($loginTimeCookie);
        } else {
            $pastTime = time();
        }

        $currentTime = time();
        $difference = $currentTime - $pastTime;
        $differenceMinute = $difference / 60;
        $hours = 0;

        if ($differenceMinute >= 60) {
            $hours = $differenceMinute / 60;
        }

        $refresh = false;

        if ($loginTimeCookie == "") {
            $refresh = true;
        }

        if ($refresh == true || $hours > 24) {
            Cookie::queue(Cookie::forget('loginTime'));

            if (!is_mobile()) {
                $isFcm = isNotificationActive('scan-qrcode-notification');

                if ($isFcm) {
                    $deviceInfo = DB::table(db_prefix() . 'user_device_info')
                        ->select('device_fcm_key')
                        ->where('user_id', auth()->id()) // Assuming you are using Laravel auth
                        ->where('device_fcm_key', '!=', '')
                        ->where('sp_session', '!=', '')
                        ->get();

                    foreach ($deviceInfo as $fcmKey) {
                        $emptyArray = [];
                        $appSent = sendNotificationsTemplate('Scan_qrcode_notification', 'noreply@attflex.com', auth()->id(), $emptyArray, $fcmKey->device_fcm_key);

                        $reqDump = print_r($appSent, true);
                        file_put_contents('Notifications.log', date('Y-m-d H:i:s') . 'Scan QR code = ' . $reqDump, FILE_APPEND);
                        logActivity('Scan QR for Auth = ' . json_encode($reqDump));
                    }
                }
            }
        }
    } catch (Exception $e) {
        // Handle exceptions if needed
    }
}

public function errorlog($error, $functionName, $fileName)
{
    $data = [
        'error' => $error,
        'function_name' => $functionName,
        'file_name' => $fileName
    ];

    DB::table(db_prefix() . 'error_log')->insert($data);
}

public function flexezvalidateqrcode(Request $request)
{
    $qrCode = $request->input('qr_code');
    $QRAuthenticationModel = app('App\Http\Controllers\YourQRAuthenticationModelController');
    $resultSet = $QRAuthenticationModel->validateQrcode(auth()->id(), 'flexez');

    if ($resultSet['status'] == "TRUE") {
        Cookie::queue('flexezloginTime', now()->toDateTimeString(), $this->exp_time);
    }

    return response()->json($resultSet);
}

public function FlexezQRcodeGenerate()
{
    $data['flexezqrcode'] = $flexezQrCode = 'Flex360-FLEXEZ_P-' . $this->random_strings(64);
    $data['user_id'] = auth()->id();

    $qrValidated = DB::table(db_prefix() . 'uservalidationtrn')
        ->select('id')
        ->where('user_id', auth()->id())
        ->first();

    if (!empty($qrValidated)) {
        $updateData = [
            'created_at' => now(),
            'flexezqrcode' => $flexezQrCode,
            'flexezqrvalidate' => 0
        ];

        DB::table(db_prefix() . 'uservalidationtrn')
            ->where('id', $qrValidated->id)
            ->update($updateData);
    } else {
        $data['created_at'] = now();
        $data['flex360_session'] = session('Flex360_Session');
        $insertId = DB::table(db_prefix() . 'uservalidationtrn')->insertGetId($data);
    }

    $rowData['flexez_qrcode'] = $flexezQrCode;
    $rowData['showflexez'] = true;
    $flexezQrCodeView = view('admin.qrauth.QRauth', $rowData)->render();

    if (!is_mobile()) {
        $isFcm = isNotificationActive('flexez-qrcode-scan');

        if ($isFcm) {
            $deviceInfo = DB::table(db_prefix() . 'user_device_info')
                ->select('device_fcm_key')
                ->where('user_id', auth()->id())
                ->where('device_fcm_key', '!=', '')
                ->get();

            foreach ($deviceInfo as $fcmKey) {
                if ($fcmKey->device_fcm_key != "") {
                    $emptyArray = [];
                    $appSent = sendNotificationsTemplate('Flexez_qrcode_scan', 'noreply@attflex.com', auth()->id(), $emptyArray, $fcmKey->device_fcm_key);

                    $reqDump = print_r($appSent, true);
                    file_put_contents('Notifications.log', now()->format('Y-m-d H:i:s') . 'Scan QR code = ' . $reqDump, FILE_APPEND);
                    logActivity('Flexez Scan QR for Auth = ' . json_encode($reqDump));
                }
            }
        }
    }

    return response()->json(['status' => 'true', 'message' => 'New FlexEZ QR code generated !', 'flexez_qr' => $flexezQrCodeView]);
}

public function getsession()
{
    $deviceInfo = DB::table(db_prefix() . 'user_device_info')
        ->select('sp_session')
        ->where('user_id', auth()->id())
        ->first();

    return response()->json($deviceInfo);
}









}