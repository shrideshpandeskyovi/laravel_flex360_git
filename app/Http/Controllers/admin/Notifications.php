<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Notifications extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('emails_model');
        $this->load->model('Notifications_model');
    }

    /* List all email templates */
    public function index()
    {
        if (!has_permission('email_templates', '', 'view')) {
            access_denied('email_templates');
        }

        $langCheckings = [];

        $this->db->where('language', 'english');
        $emailTemplatesEnglish = $this->db->get(db_prefix() . 'emailtemplates')->result_array();

        foreach ($this->app->get_available_languages() as $avLanguage) {

            if ($avLanguage === 'english') {
            
                foreach ($emailTemplatesEnglish as $template) {

                    if (isset($langCheckings[$template['slug'] . '-' . $avLanguage])) {
                        continue;
                    }

                    $notExists = total_rows(db_prefix() . 'emailtemplates', [
                        'slug'     => $template['slug'],
                        'language' => $avLanguage,
                    ]) == 0;

                    $langCheckings[$template['slug'] . '-' . $avLanguage] = 1;

                    if ($notExists) {
                        $data              = [];
                        $data['slug']      = $template['slug'];
                        $data['type']      = $template['type'];
                        $data['language']  = $avLanguage;
                        $data['name']      = $template['name'] . ' [' . $avLanguage . ']';
                        $data['subject']   = $template['subject'];
                        $data['message']   = '';
                        $data['fromname']  = $template['fromname'];
                        $data['plaintext'] = $template['plaintext'];
                        $data['active']    = $template['active'];
                        $data['order']     = $template['order'];
                        $this->db->insert(db_prefix() . 'emailtemplates', $data);
                    }
                }
            }
        }
        
        update_option('email_templates_language_checks', serialize($langCheckings));

        $data['staff'] = $this->getEmailData('staff');
        $data['credit_notes'] = $this->getEmailData('credit_note');
        $data['tasks'] = $this->getEmailData('tasks');
        $data['client'] = $this->getEmailData('client');
        $data['tickets'] = $this->getEmailData('ticket');
        $data['invoice'] = $this->getEmailData('invoice');
        $data['estimate'] = $this->getEmailData('estimate');
        $data['contracts'] = $this->getEmailData('contract');
        $data['proposals'] = $this->getEmailData('proposals');
        $data['projects'] = $this->getEmailData('project');
        $data['leads'] = $this->getEmailData('leads');
        $data['gdpr'] = $this->getEmailData('gdpr');
        $data['subscriptions'] = $this->getEmailData('subscriptions');
        $data['estimate_request'] = $this->getEmailData('estimate_request');
        $data['notifications'] = $this->getEmailData('notifications');

        $data['title'] = _l('email_templates');
        $data['hasPermissionEdit'] = has_permission('email_templates', '', 'edit');

        $this->load->view('admin/notifications/notification_templates', $data);
    }

    private function getEmailData($type)
    {
        return $this->emails_model->get([
            'type'     => $type,
            'language' => 'english',
        ]);
    }
    public function enable_by_type($type)
{
    $this->performPermissionCheckAndRedirect(function () use ($type) {
        $this->Notifications_model->mark_as_by_type($type, 1);
    });
}

public function disable_by_type($type)
{
    $this->performPermissionCheckAndRedirect(function () use ($type) {
        $this->Notifications_model->mark_as_by_type($type, 0);
    });
}

public function enable($id)
{
    $this->performPermissionCheckAndRedirect(function () use ($id) {
        $template = $this->getEmailTemplateById($id);
        $this->emails_model->mark_as($template->slug, 1);
    });
}

public function disable($id)
{
    $this->performPermissionCheckAndRedirect(function () use ($id) {
        $template = $this->getEmailTemplateById($id);
        $this->emails_model->mark_as($template->slug, 0);
    });
}

/* Since version 1.0.1 - test your smtp settings */
public function sent_smtp_test_email()
{
    if ($this->input->post()) {
        $this->load->config('email');
        $template = $this->prepareTestSmtpEmailTemplate();

        $this->initializeEmailLibrary();

        if (get_option('mail_engine') == 'phpmailer') {
            $this->configurePhpMailerDebug();
        }

        $this->setEmailProperties($template);

        if ($this->email->send(true)) {
            set_alert('success', 'Seems like your SMTP settings are set correctly. Check your email now.');
            hooks()->do_action('smtp_test_email_success');
        } else {
            set_debug_alert('<h1>Your SMTP settings are not set correctly here is the debug log.</h1><br />' . $this->email->print_debugger() . (isset($GLOBALS['debug']) ? $GLOBALS['debug'] : ''));
            hooks()->do_action('smtp_test_email_failed');
        }
    }
}

public function delete_queued_email($id)
{
    $this->performPermissionCheckAndRedirect(function () use ($id) {
        $this->email->delete_queued_email($id);
        set_alert('success', _l('deleted', _l('email_queue')));
    }, 'edit', 'settings');
}

public function enable_notification($id)
{
    $this->performPermissionCheckAndRedirect(function () use ($id) {
        $template = $this->Notifications_model->get_email_template_by_id($id);
        $this->Notifications_model->notification_mark_as($template->slug, 1);
    });
}

public function disable_notification($id)
{
    $this->performPermissionCheckAndRedirect(function () use ($id) {
        $template = $this->Notifications_model->get_email_template_by_id($id);
        $this->Notifications_model->notification_mark_as($template->slug, 0);
    });
}

public function enable_sms($id)
{
    $this->performPermissionCheckAndRedirect(function () use ($id) {
        $template = $this->Notifications_model->get_email_template_by_id($id);
        $this->Notifications_model->sms_mark_as($template->slug, 1);
    });
}

public function disable_sms($id)
{
    $this->performPermissionCheckAndRedirect(function () use ($id) {
        $template = $this->Notifications_model->get_email_template_by_id($id);
        $this->Notifications_model->sms_mark_as($template->slug, 0);
    });
}

public function update_templates($id)
{
    if ($this->input->post()) {
        $data = $this->input->post();
        $tmp  = $this->input->post(null, false);

        $this->updateTemplateData($data, $tmp, $id);

        $success = $this->Notifications_model->update_new($data, $id);

        if ($success) {
            set_alert('success', _l('updated_successfully', _l('notification_template')));
        }

        redirect(admin_url('notifications/update_templates/' . $id));
    }

    $data = $this->prepareUpdateTemplateData($id);
    $this->loadTemplateView($data);
}

private function performPermissionCheckAndRedirect($callback, $permissionType = 'edit', $permissionEntity = 'email_templates')
{
    if (has_permission($permissionEntity, '', $permissionType)) {
        $callback();
    }
    redirect(admin_url('notifications'));
}

private function getEmailTemplateById($id)
{
    return $this->emails_model->get_email_template_by_id($id);
}

private function prepareTestSmtpEmailTemplate()
{
    $template           = new StdClass();
    $template->message  = get_option('email_header') . 'This is a test SMTP email. <br />If you received this message, that means your SMTP settings are set correctly.' . get_option('email_footer');
    $template->fromname = get_option('companyname') != '' ? get_option('companyname') : 'TEST';
    $template->subject  = 'SMTP Setup Testing';

    return parse_email_template($template);
}

private function initializeEmailLibrary()
{
    hooks()->do_action('before_send_test_smtp_email');
    $this->email->initialize();
}

private function configurePhpMailerDebug()
{
    $this->email->set_debug_output(function ($err) {
        if (!isset($GLOBALS['debug'])) {
            $GLOBALS['debug'] = '';
        }
        $GLOBALS['debug'] .= $err . '<br />';
        return $err;
    });
    $this->email->set_smtp_debug(3);
}

private function setEmailProperties($template)
{
    $this->email->set_newline(config_item('newline'));
    $this->email->set_crlf(config_item('crlf'));

    $this->email->from(get_option('smtp_email'), $template->fromname);
    $this->email->to($this->input->post('test_email'));

    $systemBCC = get_option('bcc_emails');

    if ($systemBCC != '') {
        $this->email->bcc($systemBCC);
    }

    $this->email->subject($template->subject);
    $this->email->message($template->message);
}

private function updateTemplateData(&$data, $tmp, $id)
{
    foreach ($data['message'] as $key => $contents) {
        $data['message'][$key] = $tmp['message'][$key];
    }

    foreach ($data['appmessage'] as $key => $contents) {
        $data['appmessage'][$key] = $tmp['appmessage'][$key];
    }

    foreach ($data['smsmessage'] as $key => $contents) {
        $data['smsmessage'][$key] = $tmp['smsmessage'][$key];
    }

    foreach ($data['subject'] as $key => $contents) {
        $data['subject'][$key] = $tmp['subject'][$key];
    }

    $data['fromname'] = $tmp['fromname'];
}

private function prepareUpdateTemplateData($id)
{
    $data['available_languages'] = $this->app->get_available_languages();

    if (($key = array_search('english', $data['available_languages'])) !== false) {
        unset($data['available_languages'][$key]);
    }

    $data['available_merge_fields'] = $this->app_merge_fields->all();

    $data['template'] = $this->emails_model->get_email_template_by_id($id);
    $title            = $data['template']->name;
    $data['title']    = $title;

    return $data;
}

private function loadTemplateView($data)
{
    $this->load->view('admin/notifications/template', $data);
}
public function create_email_template()
{
    $this->performPermissionCheckAndLoadView(function () {
        $id = 3;
        $data = $this->prepareTemplateData($id);
        $this->load->view('admin/notifications/create_template', $data);
    });
}

public function add_templates()
{
    if ($this->input->post()) {
        $success = $this->Notifications_model->add_new();
        if ($success) {
            set_alert('success', _l('added_successfully', _l('notification_template')));
        }
        redirect(admin_url('notifications/index'));
    }
}

private function performPermissionCheckAndLoadView($callback)
{
    if (has_permission('email_templates', '', 'view')) {
        $callback();
    } else {
        access_denied('email_templates');
    }
}

private function prepareTemplateData($id)
{
    $data['available_languages'] = $this->app->get_available_languages();

    if (($key = array_search('english', $data['available_languages'])) !== false) {
        unset($data['available_languages'][$key]);
    }

    $data['available_merge_fields'] = $this->app_merge_fields->all();

    $data['template'] = $this->Notifications_model->get_email_template_by_id($id);
    $title            = $data['template']->name;
    $data['title']    = $title;

    return $data;
}

}
