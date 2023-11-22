<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class CreditNotesController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('credit_notes_model');
    }

    public function index($id = '')
    {
        return $this->listCreditNotes($id);
    }

    public function listCreditNotes($id = '')
    {
        if (!has_permission('credit_notes', '', 'view') && !has_permission('credit_notes', '', 'view_own')) {
            access_denied('credit_notes');
        }

        close_setup_menu();

        $data['years']          = $this->credit_notes_model->getCreditsYears();
        $data['statuses']       = $this->credit_notes_model->getStatuses();
        $data['credit_note_id'] = $id;
        $data['title']          = _l('credit_notes');
        return view('admin.credit_notes.manage', $data);
    }

    public function table($clientid = '')
    {
        if (!has_permission('credit_notes', '', 'view') && !has_permission('credit_notes', '', 'view_own')) {
            ajax_access_denied();
        }

        $this->app->getTableData('credit_notes', [
            'clientid' => $clientid,
        ]);
    }

    public function updateNumberSettings($id)
    {
        $response = [
            'success' => false,
            'message' => '',
        ];

        if (has_permission('credit_notes', '', 'edit')) {
            if ($this->request->input('prefix')) {
                $affectedRows = 0;

                $this->db->where('id', $id);
                $this->db->update(db_prefix() . 'creditnotes', [
                    'prefix' => $this->request->input('prefix'),
                ]);

                if ($this->db->affected_rows() > 0) {
                    $affectedRows++;
                }

                if ($affectedRows > 0) {
                    $response['success'] = true;
                    $response['message'] = _l('updated_successfully', _l('credit_note'));
                }
            }
        }

        return response()->json($response);
    }
    public function validateNumber(Request $request)
    {
        $isEdit = $request->post('isedit');
        $number = $request->post('number');
        $date = $request->post('date');
        $originalNumber = $request->post('original_number');
        $number = trim($number);
        $number = ltrim($number, '0');

        if ($isEdit == 'true') {
            if ($number == $originalNumber) {
                return response()->json(true);
            }
        }

        $totalRows = DB::table(db_prefix() . 'creditnotes')
            ->whereYear('date', date('Y', strtotime(to_sql_date($date))))
            ->where('number', $number)
            ->count();

        return response()->json($totalRows === 0 ? 'true' : 'false');
    }

    public function creditNote($id = '')
    {
        if (!has_permission('credit_notes', '', 'view') && !has_permission('credit_notes', '', 'view_own')) {
            access_denied('credit_notes');
        }

        if ($request->isMethod('post')) {
            $creditNoteData = $request->post();
            if ($id == '') {
                if (!has_permission('credit_notes', '', 'create')) {
                    access_denied('credit_notes');
                }
                $id = $this->credit_notes_model->add($creditNoteData);
                if ($id) {
                    set_alert('success', _l('added_successfully', _l('credit_note')));
                    return redirect(admin_url('credit_notes/list_credit_notes/' . $id));
                }
            } else {
                if (!has_permission('credit_notes', '', 'edit')) {
                    access_denied('credit_notes');
                }
                $success = $this->credit_notes_model->update($creditNoteData, $id);
                if ($success) {
                    set_alert('success', _l('updated_successfully', _l('credit_note')));
                }
                return redirect(admin_url('credit_notes/list_credit_notes/' . $id));
            }
        }

        if ($id == '') {
            $title = _l('add_new', _l('credit_note_lowercase'));
        } else {
            $creditNote = $this->credit_notes_model->get($id);

            if (!$creditNote || (!has_permission('credit_notes', '', 'view') && $creditNote->addedfrom != get_staff_user_id())) {
                blank_page(_l('credit_note_not_found'), 'danger');
            }

            $data['credit_note'] = $creditNote;
            $data['edit'] = true;
            $title = _l('edit', _l('credit_note_lowercase')) . ' - ' . format_credit_note_number($creditNote->id);
        }

        if ($request->input('customer_id')) {
            $data['customer_id'] = $request->input('customer_id');
        }

        $data['taxes'] = $this->taxes_model->get();
        $data['ajaxItems'] = false;

        if (DB::table(db_prefix() . 'items')->count() <= ajax_on_total_items()) {
            $data['items'] = $this->invoice_items_model->get_grouped();
        } else {
            $data['items'] = [];
            $data['ajaxItems'] = true;
        }

        $data['itemsGroups'] = $this->invoice_items_model->get_groups();

        $data['currencies'] = $this->currencies_model->get();
        $data['base_currency'] = $this->currencies_model->get_base_currency();

        $data['title'] = $title;
        $data['bodyclass'] = 'credit-note';
        return view('admin.credit_notes.credit_note', $data);
    }

    public function applyCreditsToInvoices($creditNoteId)
    {
        $creditApplied = false;
        if ($request->post()) {
            foreach ($request->post('amount') as $invoiceId => $amount) {
                if ($this->credit_notes_model->apply_credits($creditNoteId, ['amount' => $amount, 'invoice_id' => $invoiceId])) {
                    update_invoice_status($invoiceId, true);
                    $creditsApplied = true;
                }
            }
        }
        if ($creditApplied) {
            set_alert('success', _l('credits_successfully_applied_to_invoices'));
        }
        return redirect(admin_url('credit_notes/list_credit_notes/' . $creditNoteId));
    }

    public function creditNoteFromInvoice($invoiceId)
    {
        if (has_permission('credit_notes', '', 'create')) {
            $id = $this->credit_notes_model->credit_note_from_invoice($invoiceId);

            if ($id) {
                return redirect(admin_url('credit_notes/credit_note/' . $id));
            }
        }
        return redirect(admin_url('invoices/list_invoices/' . $invoiceId));
    }

    public function refund($id, $refundId = null)
    {
        if (has_permission('credit_notes', '', 'edit')) {
            $this->load->model('payment_modes_model');
            if (!$refundId) {
                $data['payment_modes'] = $this->payment_modes_model->get('', [
                    'expenses_only !=' => 1,
                ]);
            } else {
                $data['refund'] = $this->credit_notes_model->get_refund($refundId);
                $data['payment_modes'] = $this->payment_modes_model->get('', [], true, true);
                $i = 0;
                foreach ($data['payment_modes'] as $mode) {
                    if ($mode['active'] == 0 && $data['refund']->payment_mode != $mode['id']) {
                        unset($data['payment_modes'][$i]);
                    }
                    $i++;
                }
            }

            $data['credit_note'] = $this->credit_notes_model->get($id);
            return view('admin.credit_notes.refund', $data);
        }
    }

    public function createRefund($creditNoteId)
    {
        if (has_permission('credit_notes', '', 'edit')) {
            $data = $request->post();
            $data['refunded_on'] = to_sql_date($data['refunded_on']);
            $data['staff_id'] = get_staff_user_id();
            $success = $this->credit_notes_model->create_refund($creditNoteId, $data);

            if ($success) {
                set_alert('success', _l('added_successfully', _l('refund')));
            }
        }

        return redirect(admin_url('credit_notes/list_credit_notes/' . $creditNoteId));
    }

    public function editRefund($refundId, $creditNoteId)
    {
        if (has_permission('credit_notes', '', 'edit')) {
            $data = $request->post();
            $data['refunded_on'] = to_sql_date($data['refunded_on']);
            $success = $this->credit_notes_model->edit_refund($refundId, $data);

            if ($success) {
                set_alert('success', _l('updated_successfully', _l('refund')));
            }
        }

        return redirect(admin_url('credit_notes/list_credit_notes/' . $creditNoteId));
    }

    public function deleteRefund($refundId, $creditNoteId)
    {
        if (has_permission('credit_notes', '', 'delete')) {
            $success = $this->credit_notes_model->delete_refund($refundId, $creditNoteId);
            if ($success) {
                set_alert('success', _l('deleted', _l('refund')));
            }
        }
        return redirect(admin_url('credit_notes/list_credit_notes/' . $creditNoteId));
    }

    public function getCreditNoteDataAjax($id)
    {
        if (!has_permission('credit_notes', '', 'view') && !has_permission('credit_notes', '', 'view_own')) {
            return response()->json(_l('access_denied'));
        }

        if (!$id) {
            return response()->json(_l('credit_note_not_found'));
        }

        $creditNote = $this->credit_notes_model->get($id);

        if (!$creditNote || (!has_permission('credit_notes', '', 'view') && $creditNote->addedfrom != get_staff_user_id())) {
            return response()->json(_l('credit_note_not_found'));
        }

        $data = prepare_mail_preview_data('credit_note_send_to_customer', $creditNote->clientid);

        $data['credit_note'] = $creditNote;
        $data['members'] = $this->staff_model->get('', ['active' => 1]);
        $data['available_creditable_invoices'] = $this->credit_notes_model->get_available_creditable_invoices($id);

        return view('admin.credit_notes.credit_note_preview_template', $data);
    }

    public function markOpen($id)
    {
        if (total_rows(db_prefix() . 'creditnotes', ['status' => 3, 'id' => $id]) > 0 && has_permission('credit_notes', '', 'edit')) {
            $this->credit_notes_model->mark($id, 1);
        }

        return redirect(admin_url('credit_notes/list_credit_notes/' . $id));
    }

    public function deleteAttachment($id)
    {
        $file = $this->misc_model->get_file($id);
        if ($file->staffid == get_staff_user_id() || is_admin()) {
            return $this->credit_notes_model->delete_attachment($id);
        } else {
            return response()->json(['message' => 'Access Denied'], 403);
        }
    }

    public function markVoid($id)
    {
        $creditNote = $this->credit_notes_model->get($id);
        if ($creditNote->status != 2 && $creditNote->status != 3 && !$creditNote->credits_used && has_permission('credit_notes', '', 'edit')) {
            $this->credit_notes_model->mark($id, 3);
        }
        return redirect(admin_url('credit_notes/list_credit_notes/' . $id));
    }

    public function sendToEmail($id)
    {
        if (!has_permission('credit_notes', '', 'view') && !has_permission('credit_notes', '', 'view_own')) {
            access_denied('credit_notes');
        }
        $success = $this->credit_notes_model->send_credit_note_to_client($id, $this->input->post('attach_pdf'), $this->input->post('cc'));
        load_admin_language();
        if ($success) {
            set_alert('success', _l('credit_note_sent_to_client_success'));
        } else {
            set_alert('danger', _l('credit_note_sent_to_client_fail'));
        }
        return redirect(admin_url('credit_notes/list_credit_notes/' . $id));
    }

    public function deleteInvoiceAppliedCredit($id, $creditId, $invoiceId)
    {
        if (has_permission('credit_notes', '', 'delete')) {
            $this->credit_notes_model->delete_applied_credit($id, $creditId, $invoiceId);
        }
        return redirect(admin_url('invoices/list_invoices/' . $invoiceId));
    }

    public function deleteCreditNoteAppliedCredit($id, $creditId, $invoiceId)
    {
        if (has_permission('credit_notes', '', 'delete')) {
            $this->credit_notes_model->delete_applied_credit($id, $creditId, $invoiceId);
        }
        return redirect(admin_url('credit_notes/list_credit_notes/' . $creditId));
    }

    public function delete($id)
    {
        if (!has_permission('credit_notes', '', 'delete')) {
            access_denied('credit_notes');
        }

        if (!$id) {
            return redirect(admin_url('credit_notes'));
        }

        $creditNote = $this->credit_notes_model->get($id);

        if ($creditNote->credits_used || $creditNote->status == 2) {
            $success = false;
        } else {
            $success = $this->credit_notes_model->delete($id);
        }

        if ($success) {
            set_alert('success', _l('deleted', _l('credit_note')));
        } else {
            set_alert('warning', _l('problem_deleting', _l('credit_note_lowercase')));
        }

        return redirect(admin_url('credit_notes'));
    }

    public function pdf($id)
    {
        if (!has_permission('credit_notes', '', 'view') && !has_permission('credit_notes', '', 'view_own')) {
            access_denied('credit_notes');
        }
        if (!$id) {
            return redirect(admin_url('credit_notes/list_credit_notes'));
        }
        $creditNote = $this->credit_notes_model->get($id);
        $creditNoteNumber = format_credit_note_number($creditNote->id);

        try {
            $pdf = credit_note_pdf($creditNote);
        } catch (Exception $e) {
            $message = $e->getMessage();
            echo $message;
            if (strpos($message, 'Unable to get the size of the image') !== false) {
                show_pdf_unable_to_get_image_size_error();
            }
            die;
        }

        $type = 'D';

        if ($this->input->get('output_type')) {
            $type = $this->input->get('output_type');
        }

        if ($this->input->get('print')) {
            $type = 'I';
        }

        $pdf->Output(mb_strtoupper(slug_it($creditNoteNumber)) . '.pdf', $type);
    }
}