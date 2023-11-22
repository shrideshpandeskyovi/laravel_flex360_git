<?php

namespace App\Http\Controllers;

class SettingsController extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('payment_modes_model');
        $this->load->model('settings_model');
        $this->load->model('taxes_model');
        $this->load->model('tickets_model');
        $this->load->model('leads_model');
        $this->load->model('currencies_model');
        $this->load->model('staff_model');
    }

    public function index()
    {
        if (!has_permission('settings', '', 'view')) {
            access_denied('settings');
        }

        $tab = request()->input('group');

        if (request()->isMethod('post')) {
            if (!has_permission('settings', '', 'edit')) {
                access_denied('settings');
            }

            $logo_uploaded = (handle_company_logo_upload() ? true : false);
            $favicon_uploaded = (handle_favicon_upload() ? true : false);
            $signatureUploaded = (handle_company_signature_upload() ? true : false);

            $post_data = request()->input();
            $tmpData = request()->all();

            if (isset($post_data['settings']['email_header'])) {
                $post_data['settings']['email_header'] = $tmpData['settings']['email_header'];
            }

            if (isset($post_data['settings']['email_footer'])) {
                $post_data['settings']['email_footer'] = $tmpData['settings']['email_footer'];
            }

            if (isset($post_data['settings']['email_signature'])) {
                $post_data['settings']['email_signature'] = $tmpData['settings']['email_signature'];
            }

            if (isset($post_data['settings']['smtp_password'])) {
                $post_data['settings']['smtp_password'] = $tmpData['settings']['smtp_password'];
            }

            $success = $this->settings_model->update($post_data);

            if ($success > 0) {
                set_alert('success', _l('settings_updated'));
            }

            if ($logo_uploaded || $favicon_uploaded) {
                set_debug_alert(_l('logo_favicon_changed_notice'));
            }

            if ($tab == 'general') {
                return redirect(admin_url('settings?group=' . $tab))->refresh();
            } elseif ($signatureUploaded) {
                return redirect(admin_url('settings?group=pdf&tab=signature'));
            } else {
                $redUrl = admin_url('settings?group=' . $tab);
                if (request()->input('active_tab')) {
                    $redUrl .= '&tab=' . request()->input('active_tab');
                }
                return redirect($redUrl);
            }
        }

        $data['taxes'] = $this->taxes_model->get();
        $data['ticket_priorities'] = $this->tickets_model->get_priority();
        $data['ticket_priorities']['callback_translate'] = 'ticket_priority_translate';
        $data['roles'] = $this->roles_model->get();
        $data['leads_sources'] = $this->leads_model->get_source();
        $data['leads_statuses'] = $this->leads_model->get_status();
        $data['title'] = _l('options');
        $data['staff'] = $this->staff_model->get('', ['active' => 1]);

        $data['admin_tabs'] = ['update', 'info', 'skyovi_settings'];

        if (!$tab || (in_array($tab, $data['admin_tabs']) && !is_admin())) {
            $tab = 'general';
        }

        $data['tabs'] = $this->app_tabs->get_settings_tabs();
        if (!in_array($tab, $data['admin_tabs'])) {
            $data['tab'] = $this->app_tabs->filter_tab($data['tabs'], $tab);
        } else {
            $data['tab']['slug'] = $tab;
            $data['tab']['view'] = 'admin/settings/includes/' . $tab;
            $data['tab']['name'] = $tab === 'info' ? _l('System/Server Info') : (($data['tab']['slug'] == 'skyovi_settings') ? 'Flex360 Setting' :  _l('settings_update'));
        }

        if (!$data['tab']) {
            abort(404);
        }

        if ($data['tab']['slug'] == 'update') {
            if (!extension_loaded('curl')) {
                $data['update_errors'][] = 'CURL Extension not enabled';
                $data['latest_version'] = 0;
                $data['update_info'] = json_decode('');
            } else {
                $data['update_info'] = $this->app->get_update_info();
                if (strpos($data['update_info'], 'Curl Error -') !== false) {
                    $data['update_errors'][] = $data['update_info'];
                    $data['latest_version'] = 0;
                    $data['update_info'] = json_decode('');
                } else {
                    $data['update_info'] = json_decode($data['update_info']);
                    $data['latest_version'] = $data['update_info']->latest_version;
                    $data['update_errors'] = [];
                }
            }

            if (!extension_loaded('zip')) {
                $data['update_errors'][] = 'ZIP Extension not enabled';
            }

            $data['current_version'] = $this->current_db_version;
        }

        $data['contacts_permissions'] = get_contact_permissions();
        $data['payment_gateways'] = $this->payment_modes_model->get_payment_gateways(true);

        return view('admin.settings.all', $data);
    }
    public function deleteTag($id)
    {
        if (!$id) {
            return redirect(admin_url('settings?group=tags'));
        }

        if (!has_permission('settings', '', 'delete')) {
            return access_denied('settings');
        }

        \DB::table(db_prefix() . 'tags')->where('id', $id)->delete();
        \DB::table(db_prefix() . 'taggables')->where('tag_id', $id)->delete();

        return redirect(admin_url('settings?group=tags'));
    }

    public function removeSignatureImage()
    {
        if (!has_permission('settings', '', 'delete')) {
            return access_denied('settings');
        }

        $sImage = get_option('signature_image');
        $path = get_upload_path_by_type('company') . '/' . $sImage;

        if (File::exists($path)) {
            File::delete($path);
        }

        update_option('signature_image', '');

        return redirect(admin_url('settings?group=pdf&tab=signature'));
    }

    public function removeCompanyLogo($type = '')
    {
        hooks()->do_action('before_remove_company_logo');

        if (!has_permission('settings', '', 'delete')) {
            return access_denied('settings');
        }

        $logoName = get_option('company_logo');
        if ($type == 'dark') {
            $logoName = get_option('company_logo_dark');
        }

        $path = get_upload_path_by_type('company') . '/' . $logoName;
        if (File::exists($path)) {
            File::delete($path);
        }

        update_option('company_logo' . ($type == 'dark' ? '_dark' : ''), '');

        return redirect()->back();
    }

    public function removeFv()
    {
        hooks()->do_action('before_remove_favicon');

        if (!has_permission('settings', '', 'delete')) {
            return access_denied('settings');
        }

        $path = get_upload_path_by_type('company') . '/' . get_option('favicon');
        if (File::exists($path)) {
            File::delete($path);
        }

        update_option('favicon', '');

        return redirect()->back();
    }

    public function deleteOption($name)
    {
        if (!has_permission('settings', '', 'delete')) {
            return access_denied('settings');
        }

        return response()->json([
            'success' => delete_option($name),
        ]);
    }
}
