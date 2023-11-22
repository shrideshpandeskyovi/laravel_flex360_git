<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;

class AuthenticationController extends Controller
{
    private $exp_time = 60 * 5; // 5 minutes

    public function __construct()
    {
        parent::__construct();

        if ($this->app->is_db_upgrade_required()) {
            return Redirect::to(admin_url());
        }

        load_admin_language();
        $this->load->model('Authentication_model');
        $this->load->library('form_validation');
        $this->load->model('User_deviceinfo_model');

        $this->form_validation->set_message('required', _l('form_validation_required'));
        $this->form_validation->set_message('valid_email', _l('form_validation_valid_email'));
        $this->form_validation->set_message('matches', _l('form_validation_matches'));

        hooks()->do_action('admin_auth_init');
    }

    public function index()
    {
        if (Cookie::get('remember')) {
            $email = Cookie::get('email');
            $password = Cookie::get('password');
            $remember = Cookie::get('remember');

            if ($this->Authentication_model->login($email, $password, $remember, true) == TRUE) {
                $this->generateQR();

                // is logged in
                maybe_redirect_to_previous_url();

                hooks()->do_action('after_staff_login');
                return redirect(admin_url());
            } else {
                $data['title'] = _l('admin_auth_login_heading');
                return view('authentication.login_admin', $data);
            }
        } else {
            return $this->admin();
        }
    }

    public function admin(Request $request)
    {
        if (is_staff_logged_in()) {
            $this->generateQR();
            return redirect(admin_url());
        }

        $validator = Validator::make($request->all(), [
            'password' => 'required',
            'email' => 'required|email',
            'g-recaptcha-response' => show_recaptcha() ? 'callback_recaptcha' : '',
        ]);

        if ($validator->fails()) {
            return view('authentication.login_admin')->withErrors($validator)->withInput();
        }

        $email = $request->input('email');
        $password = $request->input('password');
        $remember = $request->input('remember');

        if ($remember) {
            Cookie::queue('email', $email, $this->exp_time);
            Cookie::queue('password', $password, $this->exp_time);
            Cookie::queue('remember', $remember, $this->exp_time);
        } else {
            Cookie::queue(Cookie::forget('email'));
            Cookie::queue(Cookie::forget('password'));
            Cookie::queue(Cookie::forget('remember'));
        }

        $data = $this->Authentication_model->login($email, $password, $remember, true);

        if (is_array($data) && isset($data['memberinactive'])) {
            session()->flash('alert-danger', _l('admin_auth_inactive_account'));
            return redirect(admin_url('authentication'));
        } elseif (is_array($data) && isset($data['two_factor_auth'])) {
            session(['_two_factor_auth_established' => true]);

            if ($data['user']->two_factor_auth_enabled == 1) {
                $this->Authentication_model->set_two_factor_auth_code($data['user']->staffid);
                $sent = send_mail_template('staff_two_factor_auth_key', $data['user']);

                if (!$sent) {
                    session()->flash('alert-danger', _l('two_factor_auth_failed_to_send_code'));
                    return redirect(admin_url('authentication'));
                } else {
                    session(['_two_factor_auth_staff_email' => $email]);
                    session()->flash('alert-success', _l('two_factor_auth_code_sent_successfully', $email));
                    return redirect(admin_url('authentication/two_factor'));
                }
            } else {
                session()->flash('alert-success', _l('enter_two_factor_auth_code_from_mobile'));
                return redirect(admin_url('authentication/two_factor/app'));
            }
        } elseif ($data == false) {
            session()->flash('alert-danger', _l('admin_auth_invalid_email_or_password'));
            return redirect(admin_url('authentication'));
        }

        // Set user device info
        $deviceInfo = [
            'user_id' => session('staff_user_id'),
            'timezone' => $request->input('timezone'),
            'browser' => $request->userAgent(),
            'browser_version' => $request->header('User-Agent'),
            'os' => php_uname('s'),
            'ip_address' => $request->ip(),
            'mac_address' => '', // Add your logic to get the MAC address in Laravel
        ];

        $this->User_deviceinfo_model->set($deviceInfo);

        /*
        END
        */

        $this->load->model('announcements_model');
        $this->announcements_model->set_announcements_as_read_except_last_one(session('staff_user_id'), true);
        $this->generateQR();

        // Is logged in
        maybe_redirect_to_previous_url();

        hooks()->do_action('after_staff_login');
        return redirect(admin_url());
    }

    public function twoFactor($type = 'email')
    {
        if (!Session::has('_two_factor_auth_established')) {
            abort(404);
        }

        $validator = Validator::make(request()->all(), [
            'code' => 'required',
        ]);

        if ($validator->fails()) {
            return view('authentication.set_two_factor_auth_code')->withErrors($validator);
        }

        $code = trim(request()->input('code'));
        $email = Session::get('_two_factor_auth_staff_email');

        if ($this->Authentication_model->isTwoFactorCodeValid($code, $email) && $type == 'email') {
            Session::forget('_two_factor_auth_staff_email');

            $user = $this->Authentication_model->getUserByTwoFactorAuthCode($code);
            $this->Authentication_model->clearTwoFactorAuthCode($user->staffid);
            $this->Authentication_model->twoFactorAuthLogin($user);
            Session::forget('_two_factor_auth_established');

            $this->load->model('announcements_model');
            $this->announcements_model->setAnnouncementsAsReadExceptLastOne(getStaffUserId(), true);

            maybeRedirectToPreviousUrl();

            hooks()->doAction('after_staff_login');
            return redirect(admin_url());
        } elseif ($this->Authentication_model->isGoogleTwoFactorCodeValid($code) && $type == 'app') {
            $user = getStaff(Session::get('tfa_staffid'));
            $this->Authentication_model->twoFactorAuthLogin($user);
            Session::forget('_two_factor_auth_established');

            $this->load->model('announcements_model');
            $this->announcements_model->setAnnouncementsAsReadExceptLastOne(getStaffUserId(), true);

            maybeRedirectToPreviousUrl();

            hooks()->doAction('after_staff_login');
            return redirect(admin_url());
        } else {
            logActivity('Failed Two factor authentication attempt [Staff Name: ' . getStaffFullName() . ', IP: ' . request()->ip() . ']');
            Session::flash('alert-danger', _l('two_factor_code_not_valid'));
            return redirect(admin_url('authentication/two_factor/' . $type));
        }
    }

public function forgotPassword()
{
    if (isStaffLoggedIn()) {
        return redirect(adminUrl());
    }

    $validator = Validator::make(request()->all(), [
        'email' => 'required|email|callbackEmailExists',
    ]);

    if ($validator->fails()) {
        return view('authentication.forgot_password')->withErrors($validator);
    }

    $success = $this->Authentication_model->forgotPassword(request()->input('email'), true);

    if (is_array($success) && isset($success['memberinactive'])) {
        session()->flash('alert-danger', _l('inactive_account'));
        return redirect(adminUrl('authentication/forgot_password'));
    } elseif ($success == true) {
        session()->flash('alert-success', _l('check_email_for_resetting_password'));
        return redirect(adminUrl('authentication'));
    } else {
        session()->flash('alert-danger', _l('error_setting_new_password_key'));
        return redirect(adminUrl('authentication/forgot_password'));
    }
}

function resetPassword($staff, $userid, $newPassKey)
{
    // Check if the password reset key is valid
    if (!$this->Authentication_model->canResetPassword($staff, $userid, $newPassKey)) {
        session()->flash('alert-danger', _l('password_reset_key_expired'));
        return redirect(adminUrl('authentication'));
    }

    // Validate the submitted form data
    $validator = Validator::make(request()->all(), [
        'password' => 'required',
        'passwordr' => 'required|same:password',
    ]);

    // If the form data is not valid, return the view with errors
    if ($validator->fails()) {
        return view('authentication.reset_password')->withErrors($validator);
    }

    // Hook to execute actions before the password reset
    hooks()->doAction('beforeUserResetPassword', [
        'staff' => $staff,
        'userid' => $userid,
    ]);

    // Reset the password using the Authentication_model
    $success = $this->Authentication_model->resetPassword(
        $staff,
        $userid,
        $newPassKey,
        request()->input('passwordr')
    );

    // Check the password reset result and display a corresponding alert
    if (is_array($success) && $success['expired'] == true) {
        session()->flash('alert-danger', _l('password_reset_key_expired'));
    } elseif ($success == true) {
        hooks()->doAction('afterUserResetPassword', [
            'staff' => $staff,
            'userid' => $userid,
        ]);
        session()->flash('alert-success', _l('password_reset_message'));
    } else {
        session()->flash('alert-danger', _l('password_reset_message_fail'));
    }

    // Redirect to the authentication page
    return redirect(adminUrl('authentication'));
}

public function setPassword($staff, $userid, $newPassKey)
{
    if (!$this->Authentication_model->canSetPassword($staff, $userid, $newPassKey)) {
        session()->flash('alert-danger', _l('password_reset_key_expired'));

        if ($staff == 1) {
            return redirect(adminUrl('authentication'));
        } else {
            return redirect(url('authentication'));
        }
    }

    $validator = Validator::make(request()->all(), [
        'password' => 'required',
        'passwordr' => 'required|same:password',
    ]);

    if ($validator->fails()) {
        return view('authentication.set_password')->withErrors($validator);
    }

    $success = $this->Authentication_model->setPassword(
        $staff,
        $userid,
        $newPassKey,
        request()->input('passwordr')
    );

    if (is_array($success) && $success['expired'] == true) {
        session()->flash('alert-danger', _l('password_reset_key_expired'));
    } elseif ($success == true) {
        session()->flash('alert-success', _l('password_reset_message'));
    } else {
        session()->flash('alert-danger', _l('password_reset_message_fail'));
    }

    if ($staff == 1) {
        return redirect(adminUrl('authentication'));
    } else {
        return redirect(url());
    }
}

public function logout()
{
    delete_cookie('email');
    delete_cookie('password');
    delete_cookie('remember');
    delete_cookie('loginTime');

    $this->Authentication_model->logout();
    hooks()->doAction('afterUserLogout');

    return redirect(adminUrl('authentication'));
}

public function emailExists($email)
{
    $totalRows = totalRows(dbPrefix() . 'staff', [
        'email' => $email,
    ]);
    if ($totalRows == 0) {
        $this->validateFailMessage('authResetPassEmailNotFound');
        return false;
    }
    return true;
}

public function recaptcha($str = '')
{
    return doRecaptchaValidation($str);
}

public function getQr()
{
    if (!isStaffLoggedIn()) {
        ajaxAccessDenied();
    }

    $companyName = preg_replace('/:/', '-', getOption('companyname'));

    if ($companyName == '') {
        $companyName = rtrim(preg_replace('/^https?:\/\//', '', siteUrl()), '/') . ' - CRM';
    }

    $data = $this->authenticationModel->getQr($companyName);
    return view('admin.includes.google_two_factor', $data);
}

public function generateQr()
{
    $qrAuthenticationModel = new QRAuthenticationModel();
    $qrCode = $this->randomStrings(64);
    $data['qr_code'] = 'Flex360-' . $qrCode;
    $data['user_id'] = session('staff_user_id');
    $qrAuthenticationModel->add($data);
    session(['unique_qrcode' => $qrCode]);
    return $qrCode;
}

public function validateQrcode()
{
    $qrCode = request()->input('qr_code');
    $qrAuthenticationModel = new QRAuthenticationModel();
    $resultSet = $qrAuthenticationModel->validateQrcode(session('staff_user_id'));
    if ($resultSet == "true") {
        setcookie("loginTime", date('Y-m-d H:i:s'), $this->expTime);
    }
    echo $resultSet;
}
   
}

