Copy code
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ContractsModel;
use App\Models\CurrenciesModel;

class ContractsController extends Controller
{
    protected $contractsModel;
    protected $currenciesModel;

    public function __construct(ContractsModel $contractsModel, CurrenciesModel $currenciesModel)
    {
        $this->contractsModel = $contractsModel;
        $this->currenciesModel = $currenciesModel;
    }

    public function index()
    {
        close_setup_menu();

        if (!has_permission('contracts', '', 'view') && !has_permission('contracts', '', 'view_own')) {
            access_denied('contracts');
        }

        $data['expiring'] = $this->contractsModel->get_contracts_about_to_expire(get_staff_user_id());
        $data['count_active'] = count_active_contracts();
        $data['count_expired'] = count_expired_contracts();
        $data['count_recently_created'] = count_recently_created_contracts();
        $data['count_trash'] = count_trash_contracts();
        $data['chart_types'] = json_encode($this->contractsModel->get_contracts_types_chart_data());
        $data['chart_types_values'] = json_encode($this->contractsModel->get_contracts_types_values_chart_data());
        $data['contract_types'] = $this->contractsModel->get_contract_types();
        $data['years'] = $this->contractsModel->get_contracts_years();
        $data['base_currency'] = $this->currenciesModel->get_base_currency();
        $data['title'] = _l('contracts');
        return view('admin.contracts.manage', $data);
    }

    public function table($clientid = '')
    {
        if (!has_permission('contracts', '', 'view') && !has_permission('contracts', '', 'view_own')) {
            ajax_access_denied();
        }

        $this->app->get_table_data('contracts', [
            'clientid' => $clientid,
        ]);
    }

    public function contract($id = '')
    {
        if (request()->isMethod('post')) {
            if ($id == '') {
                if (!has_permission('contracts', '', 'create')) {
                    access_denied('contracts');
                }
                $id = $this->contractsModel->add(request()->input());
                if ($id) {
                    session()->flash('alert-success', _l('added_successfully', _l('contract')));
                    return redirect()->route('contracts.contract', [$id]);
                }
            } else {
                if (!has_permission('contracts', '', 'edit')) {
                    access_denied('contracts');
                }
                $contract = $this->contractsModel->get($id);
                $data = request()->input();

                if ($contract->signed == 1) {
                    unset($data['contract_value'], $data['clientid'], $data['datestart'], $data['dateend']);
                }

                $success = $this->contractsModel->update($data, $id);
                if ($success) {
                    session()->flash('alert-success', _l('updated_successfully', _l('contract')));
                }
                return redirect()->route('contracts.contract', [$id]);
            }
        }

        if ($id == '') {
            $title = _l('add_new', _l('contract_lowercase'));
        } else {
            $data['contract'] = $this->contractsModel->get($id, [], true);
            $data['contract_renewal_history'] = $this->contractsModel->get_contract_renewal_history($id);
            $data['totalNotes'] = total_rows(db_prefix() . 'notes', ['rel_id' => $id, 'rel_type' => 'contract']);
            if (!$data['contract'] || (!has_permission('contracts', '', 'view') && $data['contract']->addedfrom != get_staff_user_id())) {
                blank_page(_l('contract_not_found'));
            }

            $data['contract_merge_fields'] = $this->app_merge_fields->get_flat('contract', ['other', 'client'], '{email_signature}');

            $title = $data['contract']->subject;

            $data = array_merge($data, prepare_mail_preview_data('contract_send_to_customer', $data['contract']->client));
        }

        if (request()->has('customer_id')) {
            $data['customer_id'] = request()->input('customer_id');
        }

        $data['base_currency'] = $this->currenciesModel->get_base_currency();
        $data['types'] = $this->contractsModel->get_contract_types();
        $data['title'] = $title;
        $data['bodyclass'] = 'contract';
        return view('admin.contracts.contract', $data);
    }
    public function getTemplate()
    {
        $name = request()->input('name');
        $content = View::make('admin.contracts.templates.' . $name)->render();

        return response()->json(['content' => $content]);
    }
    public function markAsSigned($id)
    {
        if (!staff_can('edit', 'contracts')) {
            access_denied('mark contract as signed');
        }

        $this->contractsModel->markAsSigned($id);

        return redirect()->route('contracts.contract', [$id]);
    }
    public function unmarkAsSigned($id)
    {
        if (!staff_can('edit', 'contracts')) {
            abort(403, 'Unauthorized action.');
        }

        $this->contracts_model->unmark_as_signed($id);

        return redirect()->route('contracts.contract', ['id' => $id]);
    }

    public function pdf($id)
    {
        if (!has_permission('contracts', '', 'view') && !has_permission('contracts', '', 'view_own')) {
            abort(403, 'Unauthorized action.');
        }

        if (!$id) {
            return redirect()->route('contracts.index');
        }

        $contract = $this->contracts_model->get($id);

        try {
            $pdf = contract_pdf($contract);
        } catch (Exception $e) {
            abort(500, $e->getMessage());
        }

        $type = 'D';

        if (request()->input('output_type')) {
            $type = request()->input('output_type');
        }

        if (request()->input('print')) {
            $type = 'I';
        }

        return $pdf->Output(slug_it($contract->subject) . '.pdf', $type);
    }

    public function sendToEmail($id)
    {
        if (!has_permission('contracts', '', 'view') && !has_permission('contracts', '', 'view_own')) {
            abort(403, 'Unauthorized action.');
        }

        $success = $this->contracts_model->send_contract_to_client($id, request()->input('attach_pdf'), request()->input('cc'));

        if ($success) {
            set_alert('success', _l('contract_sent_to_client_success'));
        } else {
            set_alert('danger', _l('contract_sent_to_client_fail'));
        }

        return redirect()->route('contracts.contract', ['id' => $id]);
    }

    public function addNote($rel_id)
    {
        if (request()->input('post') && (has_permission('contracts', '', 'view') || has_permission('contracts', '', 'view_own'))) {
            $this->misc_model->add_note(request()->input('post'), 'contract', $rel_id);
            return $rel_id;
        }
    }

    public function getNotes($id)
    {
        if ((has_permission('contracts', '', 'view') || has_permission('contracts', '', 'view_own'))) {
            $data['notes'] = $this->misc_model->get_notes($id, 'contract');
            return view('admin.includes.sales_notes_template', $data);
        }
    }

    public function clearSignature($id)
    {
        if (has_permission('contracts', '', 'delete')) {
            $this->contracts_model->clear_signature($id);
        }

        return redirect()->route('contracts.contract', ['id' => $id]);
    }

    public function saveContractData()
    {
        if (!has_permission('contracts', '', 'edit')) {
            return Response::json([
                'success' => false,
                'message' => _l('access_denied'),
            ], 400);
        }

        $success = false;
        $message = '';

        $this->db->where('id', request()->input('contract_id'));
        $this->db->update(db_prefix() . 'contracts', [
            'content' => html_purify(request()->input('content')),
        ]);

        $success = $this->db->affected_rows() > 0;
        $message = _l('updated_successfully', _l('contract'));

        return Response::json([
            'success' => $success,
            'message' => $message,
        ]);
    }

    public function addComment()
    {
        if (request()->input('post')) {
            return Response::json([
                'success' => $this->contracts_model->add_comment(request()->input('post')),
            ]);
        }
    }

    public function editComment($id)
    {
        if (request()->input('post')) {
            return Response::json([
                'success' => $this->contracts_model->edit_comment(request()->input('post'), $id),
                'message' => _l('comment_updated_successfully'),
            ]);
        }
    }

    public function getComments($id)
    {
        $data['comments'] = $this->contracts_model->get_comments($id);
        return view('admin.contracts.comments_template', $data);
    }
    public function removeComment($id)
    {
        $comment = DB::table(db_prefix() . 'contract_comments')->where('id', $id)->first();

        if ($comment) {
            if ($comment->staffid != get_staff_user_id() && !is_admin()) {
                return Response::json([
                    'success' => false,
                ]);
            }

            return Response::json([
                'success' => $this->contracts_model->remove_comment($id),
            ]);
        } else {
            return Response::json([
                'success' => false,
            ]);
        }
    }

    public function renew()
    {
        if (!has_permission('contracts', '', 'edit')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->post()) {
            $data = request()->post();
            $success = $this->contracts_model->renew($data);

            if ($success) {
                set_alert('success', _l('contract_renewed_successfully'));
            } else {
                set_alert('warning', _l('contract_renewed_fail'));
            }

            return redirect()->route('contracts.contract', ['id' => $data['contractid'], 'tab' => 'renewals']);
        }
    }

    public function deleteRenewal($renewal_id, $contractid)
    {
        $success = $this->contracts_model->delete_renewal($renewal_id, $contractid);

        if ($success) {
            set_alert('success', _l('contract_renewal_deleted'));
        } else {
            set_alert('warning', _l('contract_renewal_delete_fail'));
        }

        return redirect()->route('contracts.contract', ['id' => $contractid, 'tab' => 'renewals']);
    }

    public function copy($id)
    {
        if (!has_permission('contracts', '', 'create')) {
            abort(403, 'Unauthorized action.');
        }

        if (!$id) {
            return redirect()->route('contracts.index');
        }

        $newId = $this->contracts_model->copy($id);

        if ($newId) {
            set_alert('success', _l('contract_copied_successfully'));
        } else {
            set_alert('warning', _l('contract_copied_fail'));
        }

        return redirect()->route('contracts.contract', ['id' => $newId]);
    }

    public function delete($id)
    {
        if (!has_permission('contracts', '', 'delete')) {
            abort(403, 'Unauthorized action.');
        }

        if (!$id) {
            return redirect()->route('contracts.index');
        }

        $response = $this->contracts_model->delete($id);

        if ($response) {
            set_alert('success', _l('deleted', _l('contract')));
        } else {
            set_alert('warning', _l('problem_deleting', _l('contract_lowercase')));
        }

        if (strpos(url()->previous(), 'clients/') !== false) {
            return redirect()->back();
        } else {
            return redirect()->route('contracts.index');
        }
    }

    public function type($id = '')
    {
        if (!is_admin() && get_option('staff_members_create_inline_contract_types') == '0') {
            abort(403, 'Unauthorized action.');
        }

        if (request()->post()) {
            if (!request()->post('id')) {
                $id = $this->contracts_model->add_contract_type(request()->post());

                if ($id) {
                    $success = true;
                    $message = _l('added_successfully', _l('contract_type'));
                }

                return Response::json([
                    'success' => $success,
                    'message' => $message,
                    'id'      => $id,
                    'name'    => request()->post('name'),
                ]);
            } else {
                $data = request()->post();
                $id = $data['id'];
                unset($data['id']);
                $success = $this->contracts_model->update_contract_type($data, $id);
                $message = '';

                if ($success) {
                    $message = _l('updated_successfully', _l('contract_type'));
                }

                return Response::json([
                    'success' => $success,
                    'message' => $message,
                ]);
            }
        }
    }

    
public function types()
{
    if (!is_admin()) {
        abort(403, 'Unauthorized action.');
    }

    if (request()->ajax()) {
        return $this->app->get_table_data('contract_types');
    }

    $data['title'] = _l('contract_types');
    return view('admin.contracts.manage_types', $data);
}

public function deleteContractType($id)
{
    if (!$id) {
        return redirect()->route('contracts.types');
    }

    if (!is_admin()) {
        abort(403, 'Unauthorized action.');
    }

    $response = $this->contracts_model->delete_contract_type($id);

    if (is_array($response) && isset($response['referenced'])) {
        set_alert('warning', _l('is_referenced', _l('contract_type_lowercase')));
    } elseif ($response == true) {
        set_alert('success', _l('deleted', _l('contract_type')));
    } else {
        set_alert('warning', _l('problem_deleting', _l('contract_type_lowercase')));
    }

    return redirect()->route('contracts.types');
}

public function addContractAttachment($id)
{
    handle_contract_attachment($id);
}

public function addExternalAttachment()
{
    if (request()->post()) {
        $this->misc_model->add_attachment_to_database(
            request()->post('contract_id'),
            'contract',
            request()->post('files'),
            request()->post('external')
        );
    }
}

public function deleteContractAttachment($attachment_id)
{
    $file = $this->misc_model->get_file($attachment_id);

    if ($file->staffid == get_staff_user_id() || is_admin()) {
        return Response::json([
            'success' => $this->contracts_model->delete_contract_attachment($attachment_id),
        ]);
    }
}
}
