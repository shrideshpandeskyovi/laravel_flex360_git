<?php

namespace App\Http\Controllers;

use App\Models\EmailsModel;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Config;

class EmailsController extends AdminController
{
    protected $emailsModel;

    public function __construct()
    {
        parent::__construct();
        $this->emailsModel = new EmailsModel();
    }

    public function index()
    {
        if (!has_permission('email_templates', '', 'view')) {
            access_denied('email_templates');
        }

        $langCheckings = get_option('email_templates_language_checks');

        if ($langCheckings == '') {
            $langCheckings = [];
        } else {
            $langCheckings = unserialize($langCheckings);
        }

        $emailTemplatesEnglish = $this->emailsModel
            ->where('language', 'english')
            ->get()
            ->toArray();

        foreach ($this->app->get_available_languages() as $avLanguage) {
            if ($avLanguage == 'english') {
                foreach ($emailTemplatesEnglish as $template) {
                    if (isset($langCheckings[$template['slug'] . '-' . $avLanguage])) {
                        continue;
                    }

                    $notExists = $this->emailsModel
                        ->where('slug', $template['slug'])
                        ->where('language', $avLanguage)
                        ->count() == 0;

                    $langCheckings[$template['slug'] . '-' . $avLanguage] = 1;

                    if ($notExists) {
                        $data = [
                            'slug' => $template['slug'],
                            'type' => $template['type'],
                            'language' => $avLanguage,
                            'name' => $template['name'] . ' [' . $avLanguage . ']',
                            'subject' => $template['subject'],
                            'message' => '',
                            'fromname' => $template['fromname'],
                            'plaintext' => $template['plaintext'],
                            'active' => $template['active'],
                            'order' => $template['order'],
                        ];

                        $this->emailsModel->create($data);
                    }
                }
            }
        }

        update_option('email_templates_language_checks', serialize($langCheckings));

        $data['staff'] = $this->emailsModel->where([
            'type' => 'staff',
            'language' => 'english',
        ])->get();

        // ... (Repeat similar blocks for other email types)

        $data['title'] = _l('email_templates');
        $data['hasPermissionEdit'] = has_permission('email_templates', '', 'edit');

        return view('admin.emails.email_templates', $data);
    }

    public function email_template($id)
    {
        if (!has_permission('email_templates', '', 'view')) {
            access_denied('email_templates');
        }

        if (!$id) {
            return redirect(admin_url('emails'));
        }

        if (Request::isMethod('post')) {
            if (!has_permission('email_templates', '', 'edit')) {
                access_denied('email_templates');
            }

            $data = Request::post();
            $tmp = Request::post(null, false);

            foreach ($data['message'] as $key => $contents) {
                $data['message'][$key] = $tmp['message'][$key];
            }

            foreach ($data['subject'] as $key => $contents) {
                $data['subject'][$key] = $tmp['subject'][$key];
            }

            $data['fromname'] = $tmp['fromname'];

            $success = $this->emailsModel->where('id', $id)->update($data);

            if ($success) {
                set_alert('success', _l('updated_successfully', _l('email_template')));
            }

            return redirect(admin_url('emails/email_template/' . $id));
        }

        $data['available_languages'] = $this->app->get_available_languages();

        $englishKey = array_search('english', $data['available_languages']);

        if ($englishKey !== false) {
            unset($data['available_languages'][$englishKey]);
        }

        $data['available_merge_fields'] = $this->app_merge_fields->all();

        $data['template'] = $this->emailsModel->find($id);
        $title = $data['template']->name;
        $data['title'] = $title;

        return view('admin.emails.template', $data);
    }

    // ... (Repeat similar blocks for other functions)

    public function sent_smtp_test_email()
    {
        if (Request::isMethod('post')) {
            Config::set('email', config('email'));
            
            // Simulate fake template to be parsed
            $template = new \StdClass();
            $template->message = get_option('email_header') . 'This is test SMTP email. <br />If you received this message that means that your SMTP settings is set correctly.' . get_option('email_footer');
            $template->fromname = get_option('companyname') != '' ? get_option('companyname') : 'TEST';
            $template->subject = 'SMTP Setup Testing';

            $template = parse_email_template($template);

            hooks()->do_action('before_send_test_smtp_email');

            $this->email->initialize();
            if (get_option('mail_engine') == 'phpmailer') {
                $this->email->set_debug_output(function ($err) {
                    if (!isset($GLOBALS['debug'])) {
                        $GLOBALS['debug'] = '';
                    }
                    $GLOBALS['debug'] .= $err . '<br />';

                    return $err;
                });

                $this->email->set_smtp_debug(3);
            }

            $this->email->set_newline(config_item('newline'));
            $this->email->set_crlf(config_item('crlf'));

            $this->email->from(get_option('smtp_email'), $template->fromname);
            $this->email->to(Request::post('test_email'));

            $systemBCC = get_option('bcc_emails');

            if ($systemBCC != '') {
                $this->email->bcc($systemBCC);
            }

            $this->email->subject($template->subject);
            $this->email->message($template->message);

            if ($this->email->send(true)) {
                set_alert('success', 'Seems like your SMTP settings are set correctly. Check your email now.');
                hooks()->do_action('smtp_test_email_success');
            } else {
                set_debug_alert('<h1>Your SMTP settings are not set correctly here is the debug log.</h1><br />' . $this->email->print_debugger() . (isset($GLOBALS['debug']) ? $GLOBALS['debug'] : ''));

                hooks()->do_action('smtp_test_email_failed');
            }
        }
    }
    public function deleteQueuedEmail($id)
    {
        if (staff_can('edit', 'settings')) {
            $this->emailService->deleteQueuedEmail($id);
            set_alert('success', _l('deleted', _l('email_queue')));
        }

        return redirect(admin_url('settings?group=email&tab=email_queue'));
    }

    // ... (Repeat similar blocks for other functions)
}
