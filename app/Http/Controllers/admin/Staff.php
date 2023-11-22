<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class StaffController extends AdminController
{
    /**
     * List all staff members.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        if (!has_permission('staff', '', 'view')) {
            access_denied('staff');
        }

        if (request()->ajax()) {
            $this->app->get_table_data('staff');
        }

        $data['staff_members'] = $this->staff_model->get('', ['active' => 1]);
        $data['title'] = _l('staff_members');

        return view('admin.staff.manage', $data);
    }

    /**
     * Add new staff member or edit existing.
     *
     * @param string $id
     *
     * @return \Illuminate\View\View|\Illuminate\Http\RedirectResponse
     */
    public function member($id = '')
    {
        if (!has_permission('staff', '', 'view')) {
            access_denied('staff');
        }

        hooks()->do_action('staff_member_edit_view_profile', $id);

        $this->load->model('departments_model');

        if (request()->isMethod('post')) {
            $data = request()->all();
            
            // Continue with the conversion, adapting CI-specific functions to Laravel equivalents.
            // ...
        }

        if (empty($id)) {
            $title = _l('add_new', _l('staff_member_lowercase'));
        } else {
            // Continue with the conversion, adapting CI-specific functions to Laravel equivalents.
            // ...
        }

        // Load other necessary models and retrieve required data.
        // ...

        return view('admin.staff.member', $data);
    }

    /**
     * Get role permission for specific role id.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function roleChanged($id)
    {
        if (!has_permission('staff', '', 'view')) {
            ajax_access_denied('staff');
        }

        return response()->json($this->roles_model->get($id)->permissions);
    }
      /**
     * Save dashboard widgets order.
     *
     * @return void
     */
    public function saveDashboardWidgetsOrder()
    {
        hooks()->do_action('before_save_dashboard_widgets_order');

        $post_data = request()->all();

        foreach ($post_data as $container => $widgets) {
            if ($widgets == 'empty') {
                $post_data[$container] = [];
            }
        }

        update_staff_meta(get_staff_user_id(), 'dashboard_widgets_order', serialize($post_data));
    }

    /**
     * Save dashboard widgets visibility.
     *
     * @return void
     */
    public function saveDashboardWidgetsVisibility()
    {
        hooks()->do_action('before_save_dashboard_widgets_visibility');

        $post_data = request()->all();
        update_staff_meta(get_staff_user_id(), 'dashboard_widgets_visibility', serialize($post_data['widgets']));
    }

    /**
     * Reset dashboard.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function resetDashboard()
    {
        update_staff_meta(get_staff_user_id(), 'dashboard_widgets_visibility', null);
        update_staff_meta(get_staff_user_id(), 'dashboard_widgets_order', null);

        return redirect(admin_url());
    }

    /**
     * Save hidden table columns.
     *
     * @return void
     */
    public function saveHiddenTableColumns()
    {
        hooks()->do_action('before_save_hidden_table_columns');
        $data = request()->all();
        $id = $data['id'];
        $hidden = isset($data['hidden']) ? $data['hidden'] : [];
        update_staff_meta(get_staff_user_id(), 'hidden-columns-' . $id, json_encode($hidden));
    }

    /**
     * Change language for staff.
     *
     * @param string $lang
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function changeLanguage($lang = '')
    {
        hooks()->do_action('before_staff_change_language', $lang);

        DB::table('staff')
            ->where('staffid', get_staff_user_id())
            ->update(['default_language' => $lang]);

        if (request()->header('referer')) {
            return redirect(request()->header('referer'));
        } else {
            return redirect(admin_url());
        }
    }

    /**
     * Display timesheets for staff.
     *
     * @return \Illuminate\View\View
     */
    public function timesheets()
    {
        $data['view_all'] = false;

        if (staff_can('view-timesheets', 'reports') && request()->input('view') == 'all') {
            $data['staff_members_with_timesheets'] = DB::select('SELECT DISTINCT staff_id FROM ' . db_prefix() . 'taskstimers WHERE staff_id !=' . get_staff_user_id());
            $data['view_all'] = true;
        }

        if (request()->ajax()) {
            $this->app->get_table_data('staff_timesheets', ['view_all' => $data['view_all']]);
        }

        if ($data['view_all'] == false) {
            unset($data['view_all']);
        }

        $data['logged_time'] = $this->staff_model->get_logged_time_data(get_staff_user_id());
        $data['title'] = '';

        return view('admin.staff.timesheets', $data);
    }

    /**
     * Delete staff member.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function delete()
    {
        if (!is_admin() && is_admin(request()->input('id'))) {
            die('Busted, you can\'t delete administrators');
        }

        if (has_permission('staff', '', 'delete')) {
            $success = $this->staff_model->delete(request()->input('id'), request()->input('transfer_data_to'));
            if ($success) {
                set_alert('success', _l('deleted', _l('staff_member')));
            }
        }

        return redirect(admin_url('staff'));
    }

    /**
     * Edit staff profile.
     *
     * @return \Illuminate\View\View
     */
    public function editProfile()
    {
        hooks()->do_action('edit_logged_in_staff_profile');

        if (request()->isMethod('post')) {
            handle_staff_profile_image_upload();
            $data = request()->all();
            
            // Continue with the conversion, adapting CI-specific functions to Laravel equivalents.
            // ...
        }

        $member = $this->staff_model->get(get_staff_user_id());
        $data['member'] = $member;
        
        // Load other necessary models and retrieve required data.
        // ...

        return view('admin.staff.profile', $data);
    }

    /**
     * Remove staff profile image.
     *
     * @param string $id
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function removeStaffProfileImage($id = '')
    {
        $staff_id = get_staff_user_id();

        if (is_numeric($id) && (has_permission('staff', '', 'create') || has_permission('staff', '', 'edit'))) {
            $staff_id = $id;
        }

        hooks()->do_action('before_remove_staff_profile_image');

        $member = $this->staff_model->get($staff_id);

        if (file_exists(get_upload_path_by_type('staff') . $staff_id)) {
            delete_dir(get_upload_path_by_type('staff') . $staff_id);
        }

        DB::table('staff')
            ->where('staffid', $staff_id)
            ->update(['profile_image' => null]);

        if (!is_numeric($id)) {
            return redirect(admin_url('staff/edit_profile/' . $staff_id));
        } else {
            return redirect(admin_url('staff/member/' . $staff_id));
        }
    }

    /**
     * Change password for staff profile.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function changePasswordProfile()
    {
        if (request()->isMethod('post')) {
            $response = $this->staff_model->change_password(request()->post(null, false), get_staff_user_id());

            if (is_array($response) && isset($response[0]['passwordnotmatch'])) {
                set_alert('danger', _l('staff_old_password_incorrect'));
            } else {
                if ($response == true) {
                    set_alert('success', _l('staff_password_changed'));
                } else {
                    set_alert('warning', _l('staff_problem_changing_password'));
                }
            }

            return redirect(admin_url('staff/edit_profile'));
        }
    }
     /**
     * View public profile. If id passed view profile by staff id else current user.
     *
     * @param string $id
     *
     * @return \Illuminate\View\View
     */
    public function profile($id = '')
    {
        if ($id == '') {
            $id = get_staff_user_id();
        }

        hooks()->do_action('staff_profile_access', $id);

        $data['logged_time'] = $this->staff_model->get_logged_time_data($id);
        $data['staff_p'] = $this->staff_model->get($id);

        if (!$data['staff_p']) {
            abort(404, 'Staff Member Not Found');
        }

        $this->load->model('departments_model');
        $data['staff_departments'] = $this->departments_model->get_staff_departments($data['staff_p']->staffid);
        $data['departments'] = $this->departments_model->get();
        $data['title'] = _l('staff_profile_string') . ' - ' . $data['staff_p']->firstname . ' ' . $data['staff_p']->lastname;

        // notifications
        $total_notifications = DB::table(db_prefix() . 'notifications')->where('touserid', get_staff_user_id())->count();
        $data['total_pages'] = ceil($total_notifications / $this->misc_model->get_notifications_limit());

        return view('admin.staff.myprofile', $data);
    }

    /**
     * Change status to staff active or inactive / ajax.
     *
     * @param int    $id
     * @param string $status
     *
     * @return void
     */
    public function changeStaffStatus($id, $status)
    {
        if (has_permission('staff', '', 'edit')) {
            if (request()->ajax()) {
                $this->staff_model->change_staff_status($id, $status);
            }
        }
    }

    /**
     * Logged in staff notifications.
     *
     * @return \Illuminate\Http\JsonResponse|\Illuminate\View\View
     */
    public function notifications()
    {
        $this->load->model('misc_model');
        if (request()->isMethod('post')) {
            $page = request()->post('page');
            $offset = ($page * $this->misc_model->get_notifications_limit());
            $notifications = DB::table(db_prefix() . 'notifications')
                ->where('touserid', get_staff_user_id())
                ->orderBy('date', 'desc')
                ->offset($offset)
                ->limit($this->misc_model->get_notifications_limit())
                ->get()
                ->toArray();

            $i = 0;
            foreach ($notifications as $notification) {
                // Continue with the conversion, adapting CI-specific functions to Laravel equivalents.
                // ...
                $i++;
            }

            return response()->json($notifications);
        }

        return view('admin.staff.notifications');
    }

    /**
     * Update two-factor authentication.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateTwoFactor()
    {
        $fail_reason = _l('set_two_factor_authentication_failed');

        if (request()->isMethod('post')) {
            $this->load->library('form_validation');
            $this->form_validation->set_rules('two_factor_auth', _l('two_factor_auth'), 'required');

            if (request()->post('two_factor_auth') == 'google') {
                $this->form_validation->set_rules('google_auth_code', _l('google_authentication_code'), 'required');
            }

            if ($this->form_validation->run() !== false) {
                $two_factor_auth_mode = request()->post('two_factor_auth');
                $id = get_staff_user_id();

                if ($two_factor_auth_mode == 'google') {
                    $this->load->model('Authentication_model');
                    $secret = request()->post('secret');
                    $success = $this->authentication_model->set_google_two_factor($secret);
                    $fail_reason = _l('set_google_two_factor_authentication_failed');
                } elseif ($two_factor_auth_mode == 'email') {
                    DB::table('staff')->where('staffid', $id)->update(['two_factor_auth_enabled' => 1]);
                } else {
                    DB::table('staff')->where('staffid', $id)->update(['two_factor_auth_enabled' => 0]);
                }

                if ($success) {
                    set_alert('success', _l('set_two_factor_authentication_successful'));
                    return redirect(admin_url('staff/edit_profile/' . get_staff_user_id()));
                }
            }
        }

        set_alert('danger', $fail_reason);
        return redirect(admin_url('staff/edit_profile/' . get_staff_user_id()));
    }

    /**
     * Verify Google two-factor authentication.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyGoogleTwoFactor()
    {
        if (!request()->ajax()) {
            abort(404);
        }

        if (request()->isMethod('post')) {
            $data = request()->post();
            $this->load->model('authentication_model');
            $is_success = $this->authentication_model->is_google_two_factor_code_valid($data['code'], $data['secret']);
            $result = [];

            header('Content-Type: application/json');
            if ($is_success) {
                $result['status'] = 'success';
                $result['message'] = _l('google_2fa_code_valid');
                return response()->json($result);
            }

            $result['status'] = 'failed';
            $result['message'] = _l('google_2fa_code_invalid');
            return response()->json($result);
        }
    }

    // Continue converting the rest of the functions...

    /**
     * Save completed checklist visibility.
     *
     * @return void
     */
    public function saveCompletedChecklistVisibility()
    {
        hooks()->do_action('before_save_completed_checklist_visibility');

        $post_data = request()->post();
        if (is_numeric($post_data['task_id'])) {
            update_staff_meta(get_staff_user_id(), 'task-hide-completed-items-' . $post_data['task_id'], $post_data['hideCompleted']);
        }
    }

    // ...

    /**
     * Change status to staff onboarding or not / ajax.
     *
     * @param int    $id
     * @param string $status
     *
     * @return void
     */
    public function changeStaffOnboardingStatus($id, $status)
    {
        if (has_permission('staff', '', 'edit')) {
            if (request()->ajax()) {
                $this->staff_model->change_staff_otherStatus($id, $status, 'is_onboarding');
            }
        }
    }

    /**
     * Deactivate the account.
     *
     * @return void
     */
    public function deactivateAccount()
    {
        DB::table('staff')->where('staffid', get_staff_user_id())->delete();
        set_alert('success', _l('Your account has been deleted.'));
        return redirect(admin_url());
    }

    /**
     * Resend verification email.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function resendVerificationEmail()
    {
        $staff_id = request()->post('staff_id');
        $response = [];
        $response['status'] = 0;
        $response['message'] = _l('something_went_wrong');

        if (!empty($staff_id)) {
            $staff_detail = $this->staff_model->get($staff_id);
            if (!empty($staff_detail)) {
                $verification_code = (!empty($staff_detail->email_verification_code) ? $staff_detail->email_verification_code : rand(111111, 999999));

                $update_data = [];
                $update_data['email_verification_code'] = $verification_code;
                $update_data['email_verification_key'] = app_generate_hash();
                $update_data['email_verification_sent_at'] = now();

                DB::table('staff')->where('staffid', $staff_id)->update($update_data);

                if (!empty($verification_code)) {
                    send_mail_template('staff_verification_email', $staff_detail->email, $staff_detail->staffid, $verification_code);
                }

                $response['status'] = 1;
                $response['message'] = _l('Email verification sent successfully');
            }
        }

        return response()->json($response);
    }
}
