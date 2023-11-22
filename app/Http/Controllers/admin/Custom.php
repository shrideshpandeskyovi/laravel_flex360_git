<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\View;

class CustomController extends Controller
{
    /* List all copy of clients */

    public function __construct()
    {
        parent::__construct();
        if ($this->app->is_db_upgrade_required()) {
            return redirect(admin_url());
        }
        //load_admin_language();
        $this->load->library('form_validation');
        $this->load->model('staff_model');
    }

    public function index()
    {
        echo 'index working';
        //redirect(admin_url());
    }

    public function signup($id = '')
    {
        $this->load->library('form_validation');
        $this->load->model('staff_model');
        $this->load->model('staff_model');
        $this->load->library('email');
        $this->load->library('ciqrcode');
        
        $data['qr_file'] = 'assets/images/site_qr.png';
        $is_reset = false;

        $this->form_validation->set_rules('firstname', _l('first_name'), 'trim|required|min_length[2]|max_length[50]|alpha|ucwords');
        $this->form_validation->set_rules('lastname', _l('last_name'), 'trim|required|min_length[2]|max_length[50]|alpha|ucwords');
        $this->form_validation->set_rules('email', _l('client_email'), 'trim|required|is_unique[staff.email]|valid_email');
        $this->form_validation->set_rules('username', _l('username'), 'trim|required|is_unique[staff.username]');
        $this->form_validation->set_rules('password', _l('clients_register_password'), 'required|min_length[8]|regex_match[/^(?=.*\d)(?=.*[A-Z])(?=.*[a-z])(?=.*[^\w\d\s:])([^\s]){8,128}$/]');
        $this->form_validation->set_rules('passwordr', _l('clients_register_password_repeat'), 'required|matches[password]');

        if (show_recaptcha_in_customers_area()) {
            $this->form_validation->set_rules('g-recaptcha-response', 'Captcha', 'callback_recaptcha');
        }

        if ($this->input->post()) {
            $this->session->unset_userdata('_staff_signup_data');

            if ($this->form_validation->run() !== false) {
                // Process the form data and database insertion here
                // ...
                
                // Example: Insert data into the 'staff' table
                $staffId = DB::table('staff')->insertGetId([
                    'firstname' => request()->post('firstname'),
                    'email' => request()->post('email'),
                    'password' => request()->post('password'),
                    'lastname' => request()->post('lastname', null),
                    'username' => request()->post('username', null),
                    'phone_verified' => 1,
                    'active' => 0,
                    'email_verification_key' => app_generate_hash(),
                    'phone_verification_key' => app_generate_hash(),
                    'email_verification_code' => rand(1111, 999999),
                    'phone_verification_code' => rand(1111, 999999),
                    // Add other fields as needed
                ]);

                // Send verification email
                send_mail_template('staff_verification_email', request()->post('email'), $staffId, request()->post('password'));

                // Additional logic for success
                // ...

                set_alert('success', _l('registered successfully'));
                return redirect(admin_url('StaffCandidates/signup/' . $staffId));
            }
        }

        // Additional logic before rendering the view
        // ...

        $data['userData'] = [];
        if (!empty($id)) {
            // Additional logic for existing user
            // ...
        }

        // Additional data to pass to the view
        $data['is_reset'] = $is_reset;
        $data['title'] = _l('Candidates Signup');

        return view('admin.candidates.candidate_signup', $data);
    }

    public function userVerify()
    {
        // Implementation of user verification logic
        // ...
    }

    public function downloadFlex360app()
    {
        return view('admin.qrauth.appdownload');
    }

    public function downloadFlex360appweb()
    {
        return view('admin.qrauth.appdownloadweb');
    }

    public function sendNotifications()
    {
        // Implementation of sending notifications logic
        // ...
    }

    public function tresult()
    {
        return view('admin.candidates.t_result');
    }

    public function presult()
    {
        return view('admin.candidates.p_result');
    }
}
