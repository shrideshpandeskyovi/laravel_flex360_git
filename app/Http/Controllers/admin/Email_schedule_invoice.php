<?php

namespace App\Http\Controllers;

use App\Models\EmailScheduleModel;
use App\Models\InvoicesModel;
use App\Models\EmailsModel;
use Illuminate\Support\Facades\Request;

class EmailScheduleInvoiceController extends AdminController
{
    protected $emailScheduleModel;
    protected $invoicesModel;
    protected $emailsModel;

    public function __construct()
    {
        parent::__construct();

        $this->emailScheduleModel = new EmailScheduleModel();
        $this->invoicesModel = new InvoicesModel();
        $this->emailsModel = new EmailsModel();
    }

    public function create($id)
    {
        if (!staff_can('create', 'invoices')) {
            ajax_access_denied();
        }

        if (Request::isMethod('post')) {
            $data = Request::post();

            $this->emailScheduleModel->create($id, 'invoice', [
                'scheduled_at' => to_sql_date($data['scheduled_at'], true),
                'cc'           => $data['cc'],
                'contacts'     => $data['sent_to'],
                'attach_pdf'   => isset($data['attach_pdf']) ? 1 : 0,
                'template'     => 'invoice_send_to_customer',
            ]);

            set_alert('success', _l('email_scheduled_successfully'));
            return redirect(admin_url('invoices/list_invoices/' . $id));
        }

        $data = $this->scheduleData($id);
        $data['formUrl'] = admin_url('email_schedule_invoice/create/' . $id);
        $data['date'] = get_scheduled_email_default_date();

        return view('admin.invoices.schedule', $data);
    }

    public function edit($id)
    {
        $schedule = $this->emailScheduleModel->getById($id);
        $data = $this->scheduleData($schedule->rel_id);

        if (staff_can('edit', 'invoices') || $data['invoice']->addedfrom == get_staff_user_id()) {
            if (Request::isMethod('post')) {
                $postData = Request::post();

                $this->emailScheduleModel->update($id, [
                    'scheduled_at' => to_sql_date($postData['scheduled_at'], true),
                    'cc'           => $postData['cc'],
                    'contacts'     => $postData['sent_to'],
                    'attach_pdf'   => isset($postData['attach_pdf']) ? 1 : 0,
                ]);

                set_alert('success', _l('email_scheduled_successfully'));
                return redirect(admin_url('invoices/list_invoices/' . $schedule->rel_id));
            }

            $data['schedule'] = $schedule;
            $data['formUrl'] = admin_url('email_schedule_invoice/edit/' . $id);
            $data['date'] = $schedule->scheduled_at;

            return view('admin.invoices.schedule', $data);
        } else {
            ajax_access_denied();
        }
    }

    protected function scheduleData($id)
    {
        $invoice = $this->invoicesModel->get($id);

        $data['invoice'] = $invoice;

        $templateName = 'invoice_send_to_customer';
        $slug = $this->appMailTemplate->get_default_property_value('slug', $templateName);
        $template = $this->emailsModel->where(['slug' => $slug, 'language' => 'english'])->first();

        $data['template_disabled'] = $template->active == 0;
        $data['template_id'] = $template->emailtemplateid;
        $data['template_system_name'] = $template->name;

        return $data;
    }
}
