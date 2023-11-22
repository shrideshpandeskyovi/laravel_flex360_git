<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * @property Invoices_model $invoices_model
 * @property Payments_model $payments_model
 */
class Payments extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('payments_model');
    }

    public function batch_payment_modal()
    {
        $this->load->model('invoices_model');
        $data['invoices'] = $this->invoices_model->get_unpaid_invoices();
        $data['customers'] = $this->db->select('userid,' . get_sql_select_client_company())
            ->where_in('userid', collect($data['invoices'])->pluck('clientid')->toArray())
            ->get(db_prefix() . 'clients')->result();
        $this->load->view('admin/payments/batch_payment_modal', $data);
    }

    public function add_batch_payment()
    {
        if ($this->input->method() !== 'post') {
            show_404();
        }

        if (!staff_can('create', 'payment')) {
            access_denied('Create Payment');
        }

        $totalAdded = $this->payments_model->add_batch_payment($this->input->post());

        if ($totalAdded > 0) {
            set_alert('success', _l('batch_payment_added_successfully', $totalAdded));
            return redirect(admin_url('payments'));
        }

        return redirect(admin_url('invoices'));
    }

    /* In case the user goes only to /payments */
    public function index()
    {
        $this->list_payments();
    }

    public function list_payments()
    {
        if (!has_permission('payments', '', 'view')
            && !has_permission('invoices', '', 'view_own')
            && get_option('allow_staff_view_invoices_assigned') == '0') {
            access_denied('payments');
        }

        $data['title'] = _l('payments');
        $this->load->view('admin/payments/manage', $data);
    }

    public function table($clientid = '')
    {
        if (!has_permission('payments', '', 'view')
            && !has_permission('invoices', '', 'view_own')
            && get_option('allow_staff_view_invoices_assigned') == '0') {
            ajax_access_denied();
        }

        $this->app->get_table_data('payments', [
            'clientid' => $clientid,
        ]);
    }

    /* Update payment data */
    public function payment($id = '')
    {
        if (!has_permission('payments', '', 'view')
            && !has_permission('invoices', '', 'view_own')
            && get_option('allow_staff_view_invoices_assigned') == '0') {
            access_denied('payments');
        }

        if (!$id) {
            redirect(admin_url('payments'));
        }

        if ($this->input->post()) {
            if (!has_permission('payments', '', 'edit')) {
                access_denied('Update Payment');
            }

            $success = $this->payments_model->update($this->input->post(), $id);

            if ($success) {
                set_alert('success', _l('updated_successfully', _l('payment')));
            }

            redirect(admin_url('payments/payment/' . $id));
        }

        $payment = $this->payments_model->get($id);

        if (!$payment) {
            show_404();
        }

        $this->load->model('invoices_model');
        $payment->invoice = $this->invoices_model->get($payment->invoiceid);
        $template_name    = 'invoice_payment_recorded_to_customer';

        $data = prepare_mail_preview_data($template_name, $payment->invoice->clientid);
        $data['payment'] = $payment;

        $this->load->model('payment_modes_model');
        $data['payment_modes'] = $this->payment_modes_model->get('', [], true, true);

        $i = 0;
        foreach ($data['payment_modes'] as $mode) {
            if ($mode['active'] == 0 && $data['payment']->paymentmode != $mode['id']) {
                unset($data['payment_modes'][$i]);
            }
            $i++;
        }

        $data['title'] = _l('payment_receipt') . ' - ' . format_invoice_number($data['payment']->invoiceid);
        $this->load->view('admin/payments/payment', $data);
    }

    /**
     * Generate payment PDF
     * @since  Version 1.0.1
     * @param  mixed $id Payment id
     */
    public function pdf($id)
{
    $this->handlePaymentViewPermissions($id);

    $payment = $this->payments_model->get($id);
    $this->checkInvoiceViewPermissions($payment->invoiceid);

    try {
        $paymentPdf = payment_pdf($payment);
        $this->outputPdf($paymentPdf, $id);
    } catch (Exception $e) {
        $this->handlePdfException($e);
    }
}

public function send_to_email($id)
{
    $this->handlePaymentViewPermissions($id);

    $payment = $this->payments_model->get($id);
    $this->checkInvoiceViewPermissions($payment->invoiceid);

    $this->load->model('invoices_model');
    $payment->invoice_data = $this->invoices_model->get($payment->invoiceid);

    set_mailing_constant();

    $paymentPdf = payment_pdf($payment);
    $filename   = mb_strtoupper(slug_it(_l('payment') . '-' . $payment->paymentid), 'UTF-8') . '.pdf';

    $attach = $paymentPdf->Output($filename, 'S');

    $sent    = $this->sendPaymentToEmailContacts($payment, $attach, $filename);

    load_admin_language();
    set_alert($sent ? 'success' : 'danger', _l($sent ? 'payment_sent_successfully' : 'payment_sent_failed'));

    redirect(admin_url('payments/payment/' . $id));
}

public function delete($id)
{
    if (!has_permission('payments', '', 'delete')) {
        access_denied('Delete Payment');
    }

    $this->deletePayment($id);
}

/* Helper Functions */

private function handlePaymentViewPermissions($id)
{
    if (!has_permission('payments', '', 'view')
        && !has_permission('invoices', '', 'view_own')
        && get_option('allow_staff_view_invoices_assigned') == '0') {
        access_denied('View Payment');
    }
}

private function checkInvoiceViewPermissions($invoiceId)
{
    if (!has_permission('payments', '', 'view')
        && !has_permission('invoices', '', 'view_own')
        && !user_can_view_invoice($invoiceId)) {
        access_denied('View Payment');
    }
}

private function outputPdf($paymentPdf, $id)
{
    $type = $this->input->get('output_type') ?: 'D';

    if ($this->input->get('print')) {
        $type = 'I';
    }

    $paymentPdf->Output(mb_strtoupper(slug_it(_l('payment') . '-' . $id)) . '.pdf', $type);
}

private function handlePdfException(Exception $e)
{
    $message = $e->getMessage();
    echo $message;
    if (strpos($message, 'Unable to get the size of the image') !== false) {
        show_pdf_unable_to_get_image_size_error();
    }
    die;
}

private function sendPaymentToEmailContacts($payment, $attach, $filename)
{
    $sent    = false;
    $sent_to = $this->input->post('sent_to');

    if (is_array($sent_to) && count($sent_to) > 0) {
        foreach ($sent_to as $contact_id) {
            if ($contact_id != '') {
                $contact = $this->clients_model->get_contact($contact_id);

                $template = mail_template('invoice_payment_recorded_to_customer', (array) $contact, $payment->invoice_data, false, $payment->paymentid);

                $template->add_attachment([
                    'attachment' => $attach,
                    'filename'   => $filename,
                    'type'       => 'application/pdf',
                ]);

                $this->attachInvoiceToPaymentReceiptEmail($template, $payment);

                if ($template->send()) {
                    $sent = true;
                }
            }
        }
    }

    return $sent;
}

private function attachInvoiceToPaymentReceiptEmail($template, $payment)
{
    if (get_option('attach_invoice_to_payment_receipt_email') == 1) {
        $invoice_number = format_invoice_number($payment->invoiceid);
        set_mailing_constant();
        $pdfInvoice           = invoice_pdf($payment->invoice_data);
        $pdfInvoiceAttachment = $pdfInvoice->Output($invoice_number . '.pdf', 'S');

        $template->add_attachment([
            'attachment' => $pdfInvoiceAttachment,
            'filename'   => str_replace('/', '-', $invoice_number) . '.pdf',
            'type'       => 'application/pdf',
        ]);
    }
}

private function deletePayment($id)
{
    if (!$id) {
        redirect(admin_url('payments'));
    }

    $response = $this->payments_model->delete($id);

    if ($response == true) {
        set_alert('success', _l('deleted', _l('payment')));
    } else {
        set_alert('warning', _l('problem_deleting', _l('payment_lowercase')));
    }

    redirect(admin_url('payments'));
}

}
