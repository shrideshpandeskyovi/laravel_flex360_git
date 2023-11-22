
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProposalsModel;
use App\Models\CurrenciesModel;

class ProposalsController extends Controller
{
    protected $proposalsModel;
    protected $currenciesModel;

    public function __construct()
    {
        parent::__construct();
        $this->proposalsModel = new ProposalsModel();
        $this->currenciesModel = new CurrenciesModel();
    }

    public function index($proposal_id = '')
    {
        return $this->listProposals($proposal_id);
    }

    public function listProposals($proposal_id = '')
    {
        close_setup_menu();

        if (!$this->hasPermission('proposals', '', 'view') && !$this->hasPermission('proposals', '', 'view_own') && get_option('allow_staff_view_estimates_assigned') == 0) {
            return $this->accessDenied('proposals');
        }

        $isPipeline = session('proposals_pipeline') == 'true';

        if ($isPipeline && !$this->request->input('status')) {
            $data['title'] = _l('proposals_pipeline');
            $data['bodyclass'] = 'proposals-pipeline';
            $data['switch_pipeline'] = false;

            if (is_numeric($proposal_id)) {
                $data['proposalid'] = $proposal_id;
            } else {
                $data['proposalid'] = session('proposalid');
            }

            return view('admin.proposals.pipeline.manage', $data);
        } else {
            if ($this->request->input('status') && $isPipeline) {
                return $this->pipeline(0, true);
            }

            $data['proposal_id'] = $proposal_id;
            $data['switch_pipeline'] = true;
            $data['title'] = _l('proposals');
            $data['proposal_statuses'] = $this->proposalsModel->getStatuses();
            $data['proposals_sale_agents'] = $this->proposalsModel->getSaleAgents();
            $data['years'] = $this->proposalsModel->getProposalsYears();

            return view('admin.proposals.manage', $data);
        }
    }

    public function table()
    {
        if (
            !$this->hasPermission('proposals', '', 'view')
            && !$this->hasPermission('proposals', '', 'view_own')
            && get_option('allow_staff_view_proposals_assigned') == 0
        ) {
            return $this->ajaxAccessDenied();
        }

        return $this->app->getTableData('proposals');
    }
    public function clearSignature($id)
    {
        if ($this->hasPermission('proposals', '', 'delete')) {
            $this->proposalsModel->clearSignature($id);
        }

        return redirect(admin_url('proposals/list_proposals/' . $id));
    }
    public function proposalRelations($relId, $relType)
    {
        return $this->app->getTableData('proposals_relations', [
            'rel_id'   => $relId,
            'rel_type' => $relType,
        ]);
    }
    
    public function deleteAttachment($id)
    {
        $file = $this->miscModel->getFile($id);
    
        if ($file->staffid == $this->getStaffUserId() || $this->isAdmin()) {
            return $this->proposalsModel->deleteAttachment($id);
        } else {
            return $this->ajaxAccessDenied();
        }
    }
    
    public function syncData()
    {
        if ($this->hasPermission('proposals', '', 'create') || $this->hasPermission('proposals', '', 'edit')) {
            $hasPermissionView = $this->hasPermission('proposals', '', 'view');

            $this->db->where('rel_id', $this->request->post('rel_id'));
            $this->db->where('rel_type', $this->request->post('rel_type'));

            if (!$hasPermissionView) {
                $this->db->where('addedfrom', $this->getStaffUserId());
            }

            $address = trim($this->request->post('address'));
            $address = nl2br($address);

            $this->db->update(db_prefix() . 'proposals', [
                'phone'   => $this->request->post('phone'),
                'zip'     => $this->request->post('zip'),
                'country' => $this->request->post('country'),
                'state'   => $this->request->post('state'),
                'address' => $address,
                'city'    => $this->request->post('city'),
            ]);

            if ($this->db->affected_rows() > 0) {
                return response()->json([
                    'message' => _l('all_data_synced_successfully'),
                ]);
            } else {
                return response()->json([
                    'message' => _l('sync_proposals_up_to_date'),
                ]);
            }
        }
    }

    public function proposal($id = '')
    {
        if ($this->request->post()) {
            $proposalData = $this->request->post();

            if ($id == '') {
                if (!$this->hasPermission('proposals', '', 'create')) {
                    return $this->accessDenied('proposals');
                }

                $id = $this->proposalsModel->add($proposalData);

                if ($id) {
                    set_alert('success', _l('added_successfully', _l('proposal')));

                    if ($this->setProposalPipelineAutoload($id)) {
                        return redirect(admin_url('proposals'));
                    } else {
                        return redirect(admin_url('proposals/list_proposals/' . $id));
                    }
                }
            } else {
                if (!$this->hasPermission('proposals', '', 'edit')) {
                    return $this->accessDenied('proposals');
                }

                $success = $this->proposalsModel->update($proposalData, $id);

                if ($success) {
                    set_alert('success', _l('updated_successfully', _l('proposal')));
                }

                if ($this->setProposalPipelineAutoload($id)) {
                    return redirect(admin_url('proposals'));
                } else {
                    return redirect(admin_url('proposals/list_proposals/' . $id));
                }
            }
        }

        if ($id == '') {
            $title = _l('add_new', _l('proposal_lowercase'));
        } else {
            $data['proposal'] = $this->proposalsModel->get($id);

            if (!$data['proposal'] || !$this->userCanViewProposal($id)) {
                blank_page(_l('proposal_not_found'));
            }

            $data['estimate'] = $data['proposal'];
            $data['is_proposal'] = true;
            $title = _l('edit', _l('proposal_lowercase'));
        }

        $this->load->model('taxes_model');
        $data['taxes'] = $this->taxesModel->get();
        $this->load->model('invoice_items_model');
        $data['ajaxItems'] = false;

        if (total_rows(db_prefix() . 'items') <= ajax_on_total_items()) {
            $data['items'] = $this->invoiceItemsModel->getGrouped();
        } else {
            $data['items'] = [];
            $data['ajaxItems'] = true;
        }

        $data['itemsGroups'] = $this->invoiceItemsModel->getGroups();
        $data['statuses'] = $this->proposalsModel->getStatuses();
        $data['staff'] = $this->staffModel->get('', ['active' => 1]);
        $data['currencies'] = $this->currenciesModel->get();
        $data['base_currency'] = $this->currenciesModel->getBaseCurrency();

        $data['title'] = $title;
        return view('admin.proposals.proposal', $data);
    }

    public function getTemplate()
    {
        $name = $this->request->get('name');
        return view('admin.proposals.templates.' . $name, []);
    }
    public function sendExpiryReminder($id)
{
    $canView = userCanViewProposal($id);

    if (!$canView) {
        return accessDenied('proposals');
    } else {
        if (!hasPermission('proposals', '', 'view') && !hasPermission('proposals', '', 'view_own') && $canView == false) {
            return accessDenied('proposals');
        }
    }

    $success = $this->proposalsModel->sendExpiryReminder($id);

    if ($success) {
        setAlert('success', _l('sent_expiry_reminder_success'));
    } else {
        setAlert('danger', _l('sent_expiry_reminder_fail'));
    }

    if ($this->setProposalPipelineAutoload($id)) {
        return redirect()->back();
    } else {
        return redirect(adminURL('proposals/list_proposals/' . $id));
    }
}

public function clearAcceptanceInfo($id)
{
    if (isAdmin()) {
        $this->db->where('id', $id);
        $this->db->update(dbPrefix() . 'proposals', getAcceptanceInfoArray(true));
    }

    return redirect(adminURL('proposals/list_proposals/' . $id));
}

public function pdf($id)
{
    if (!$id) {
        return redirect(adminURL('proposals'));
    }

    $canView = userCanViewProposal($id);

    if (!$canView) {
        return accessDenied('proposals');
    } else {
        if (!hasPermission('proposals', '', 'view') && !hasPermission('proposals', '', 'view_own') && $canView == false) {
            return accessDenied('proposals');
        }
    }

    $proposal = $this->proposalsModel->get($id);

    try {
        $pdf = proposalPdf($proposal);
    } catch (Exception $e) {
        $message = $e->getMessage();
        echo $message;

        if (strpos($message, 'Unable to get the size of the image') !== false) {
            showPdfUnableToGetImageSizeError();
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

    $proposalNumber = formatProposalNumber($id);
    $pdf->Output($proposalNumber . '.pdf', $type);
}

public function getProposalDataAjax($id, $toReturn = false)
{
    if (!hasPermission('proposals', '', 'view') && !hasPermission('proposals', '', 'view_own') && getOption('allow_staff_view_proposals_assigned') == 0) {
        echo _l('access_denied');
        die;
    }

    $proposal = $this->proposalsModel->get($id, [], true);

    if (!$proposal || !userCanViewProposal($id)) {
        echo _l('proposal_not_found');
        die;
    }

    $this->appMailTemplate->setRelId($proposal->id);
    $data = prepareMailPreviewData('proposal_send_to_customer', $proposal->email);

    $mergeFields = [];

    $mergeFields[] = [
        [
            'name' => 'Items Table',
            'key'  => '{proposal_items}',
        ],
    ];

    $mergeFields = array_merge($mergeFields, $this->appMergeFields->getFlat('proposals', 'other', '{email_signature}'));

    $data['proposal_statuses']     = $this->proposalsModel->getStatuses();
    $data['members']               = $this->staffModel->get('', ['active' => 1]);
    $data['proposal_merge_fields'] = $mergeFields;
    $data['proposal']              = $proposal;
    $data['totalNotes']            = totalRows(dbPrefix() . 'notes', ['rel_id' => $id, 'rel_type' => 'proposal']);

    if ($toReturn == false) {
        return view('admin.proposals.proposals_preview_template', $data);
    } else {
        return view('admin.proposals.proposals_preview_template', $data)->render();
    }
}

public function addNote($relId)
{
    if ($this->input->post() && userCanViewProposal($relId)) {
        $this->miscModel->addNote($this->input->post(), 'proposal', $relId);
        echo $relId;
    }
}

public function getNotes($id)
{
    if (userCanViewProposal($id)) {
        $data['notes'] = $this->miscModel->getNotes($id, 'proposal');
        return view('admin.includes.sales_notes_template', $data);
    }
}
public function convertToEstimate($id)
{
    if (!hasPermission('estimates', '', 'create')) {
        accessDenied('estimates');
    }

    if ($this->input->post()) {
        $this->load->model('estimates_model');
        $estimateId = $this->estimatesModel->add($this->input->post());

        if ($estimateId) {
            setAlert('success', _l('proposal_converted_to_estimate_success'));
            $this->db->where('id', $id);
            $this->db->update(dbPrefix() . 'proposals', [
                'estimate_id' => $estimateId,
                'status'      => 3,
            ]);

            logActivity('Proposal Converted to Estimate [EstimateID: ' . $estimateId . ', ProposalID: ' . $id . ']');

            hooks()->doAction('proposal_converted_to_estimate', ['proposal_id' => $id, 'estimate_id' => $estimateId]);

            return redirect(adminURL('estimates/estimate/' . $estimateId));
        } else {
            setAlert('danger', _l('proposal_converted_to_estimate_fail'));
        }

        if ($this->setProposalPipelineAutoload($id)) {
            return redirect(adminURL('proposals'));
        } else {
            return redirect(adminURL('proposals/list_proposals/' . $id));
        }
    }
}

public function convertToInvoice($id)
{
    if (!hasPermission('invoices', '', 'create')) {
        accessDenied('invoices');
    }

    if ($this->input->post()) {
        $this->load->model('invoices_model');
        $invoiceId = $this->invoicesModel->add($this->input->post());

        if ($invoiceId) {
            setAlert('success', _l('proposal_converted_to_invoice_success'));
            $this->db->where('id', $id);
            $this->db->update(dbPrefix() . 'proposals', [
                'invoice_id' => $invoiceId,
                'status'     => 3,
            ]);

            logActivity('Proposal Converted to Invoice [InvoiceID: ' . $invoiceId . ', ProposalID: ' . $id . ']');

            hooks()->doAction('proposal_converted_to_invoice', ['proposal_id' => $id, 'invoice_id' => $invoiceId]);

            return redirect(adminURL('invoices/invoice/' . $invoiceId));
        } else {
            setAlert('danger', _l('proposal_converted_to_invoice_fail'));
        }

        if ($this->setProposalPipelineAutoload($id)) {
            return redirect(adminURL('proposals'));
        } else {
            return redirect(adminURL('proposals/list_proposals/' . $id));
        }
    }
}

public function getInvoiceConvertData($id)
{
    $this->load->model('payment_modes_model');
    $data['payment_modes'] = $this->payment_modes_model->get('', [
        'expenses_only !=' => 1,
    ]);

    $this->load->model('taxes_model');
    $data['taxes']         = $this->taxes_model->get();
    $data['currencies']    = $this->currenciesModel->get();
    $data['base_currency'] = $this->currenciesModel->getBaseCurrency();

    $this->load->model('invoice_items_model');
    $data['ajaxItems'] = false;

    if (totalRows(dbPrefix() . 'items') <= ajax_on_total_items()) {
        $data['items'] = $this->invoiceItemsModel->getGrouped();
    } else {
        $data['items']     = [];
        $data['ajaxItems'] = true;
    }

    $data['items_groups'] = $this->invoiceItemsModel->getGroups();

    $data['staff']          = $this->staffModel->get('', ['active' => 1]);
    $data['proposal']       = $this->proposalsModel->get($id);
    $data['billable_tasks'] = [];
    $data['add_items']      = $this->parseItems($data['proposal']);

    if ($data['proposal']->rel_type == 'lead') {
        $this->db->where('leadid', $data['proposal']->rel_id);
        $data['customer_id'] = $this->db->get(dbPrefix() . 'clients')->row()->userid;
    } else {
        $data['customer_id'] = $data['proposal']->rel_id;
        $data['project_id'] = $data['proposal']->project_id;
    }

    $data['custom_fields_rel_transfer'] = [
        'belongs_to' => 'proposal',
        'rel_id'     => $id,
    ];

    return view('admin.proposals.invoice_convert_template', $data);
}

public function getEstimateConvertData($id)
{
    $this->load->model('taxes_model');
    $data['taxes']         = $this->taxes_model->get();
    $data['currencies']    = $this->currenciesModel->get();
    $data['base_currency'] = $this->currenciesModel->getBaseCurrency();

    $this->load->model('invoice_items_model');
    $data['ajaxItems'] = false;

    if (totalRows(dbPrefix() . 'items') <= ajax_on_total_items()) {
        $data['items'] = $this->invoiceItemsModel->getGrouped();
    } else {
        $data['items']     = [];
        $data['ajaxItems'] = true;
    }

    $data['items_groups'] = $this->invoiceItemsModel->getGroups();

    $data['staff']     = $this->staffModel->get('', ['active' => 1]);
    $data['proposal']  = $this->proposalsModel->get($id);
    $data['add_items'] = $this->parseItems($data['proposal']);

    $this->load->model('estimates_model');
    $data['estimate_statuses'] = $this->estimatesModel->getStatuses();

    if ($data['proposal']->rel_type == 'lead') {
        $this->db->where('leadid', $data['proposal']->rel_id);
        $data['customer_id'] = $this->db->get(dbPrefix() . 'clients')->row()->userid;
    } else {
        $data['customer_id'] = $data['proposal']->rel_id;
        $data['project_id'] = $data['proposal']->project_id;
    }

    $data['custom_fields_rel_transfer'] = [
        'belongs_to' => 'proposal',
        'rel_id'     => $id,
    ];

    return view('admin.proposals.estimate_convert_template', $data);
}

private function parseItems($proposal)
{
    $items = [];

    foreach ($proposal->items as $item) {
        $taxNames = [];
        $taxes    = getProposalItemTaxes($item['id']);

        foreach ($taxes as $tax) {
            array_push($taxNames, $tax['taxname']);
        }

        $item['taxname']        = $taxNames;
        $item['parent_item_id'] = $item['id'];
        $item['id']             = 0;
        $items[]                = $item;
    }

    return $items;
}
public function sendToEmail($id)
{
    $canView = userCanViewProposal($id);

    if (!$canView) {
        accessDenied('proposals');
    } else {
        if (!hasPermission('proposals', '', 'view') && !hasPermission('proposals', '', 'view_own') && $canView == false) {
            accessDenied('proposals');
        }
    }

    if ($this->input->post()) {
        try {
            $success = $this->proposalsModel->sendProposalToEmail(
                $id,
                $this->input->post('attach_pdf'),
                $this->input->post('cc')
            );
        } catch (Exception $e) {
            $message = $e->getMessage();
            echo $message;

            if (strpos($message, 'Unable to get the size of the image') !== false) {
                showPdfUnableToGetImageSizeError();
            }

            die;
        }

        if ($success) {
            setAlert('success', _l('proposal_sent_to_email_success'));
        } else {
            setAlert('danger', _l('proposal_sent_to_email_fail'));
        }

        if ($this->setProposalPipelineAutoload($id)) {
            return redirect(url()->previous());
        } else {
            return redirect(adminURL('proposals/list_proposals/' . $id));
        }
    }
}

public function copy($id)
{
    if (!hasPermission('proposals', '', 'create')) {
        accessDenied('proposals');
    }

    $newId = $this->proposalsModel->copy($id);

    if ($newId) {
        setAlert('success', _l('proposal_copy_success'));
        $this->setProposalPipelineAutoload($newId);
        return redirect(adminURL('proposals/proposal/' . $newId));
    } else {
        setAlert('success', _l('proposal_copy_fail'));
    }

    if ($this->setProposalPipelineAutoload($id)) {
        return redirect(adminURL('proposals'));
    } else {
        return redirect(adminURL('proposals/list_proposals/' . $id));
    }
}

public function markActionStatus($status, $id)
{
    if (!hasPermission('proposals', '', 'edit')) {
        accessDenied('proposals');
    }

    $success = $this->proposalsModel->markActionStatus($status, $id);

    if ($success) {
        setAlert('success', _l('proposal_status_changed_success'));
    } else {
        setAlert('danger', _l('proposal_status_changed_fail'));
    }

    if ($this->setProposalPipelineAutoload($id)) {
        return redirect(adminURL('proposals'));
    } else {
        return redirect(adminURL('proposals/list_proposals/' . $id));
    }
}

public function delete($id)
{
    if (!hasPermission('proposals', '', 'delete')) {
        accessDenied('proposals');
    }

    $response = $this->proposalsModel->delete($id);

    if ($response == true) {
        setAlert('success', _l('deleted', _l('proposal')));
    } else {
        setAlert('warning', _l('problem_deleting', _l('proposal_lowercase')));
    }

    return redirect(adminURL('proposals'));
}

public function getRelationDataValues($relId, $relType)
{
    echo json_encode($this->proposalsModel->getRelationDataValues($relId, $relType));
}

public function addProposalComment()
{
    if ($this->input->post()) {
        echo json_encode([
            'success' => $this->proposalsModel->addComment($this->input->post()),
        ]);
    }
}

public function editComment($id)
{
    if ($this->input->post()) {
        echo json_encode([
            'success' => $this->proposalsModel->editComment($this->input->post(), $id),
            'message' => _l('comment_updated_successfully'),
        ]);
    }
}

public function getProposalComments($id)
{
    $data['comments'] = $this->proposalsModel->getComments($id);
    return view('admin.proposals.comments_template', $data);
}

public function removeComment($id)
{
    $this->db->where('id', $id);
    $comment = $this->db->get(dbPrefix() . 'proposal_comments')->row();

    if ($comment) {
        if ($comment->staffid != getStaffUserId() && !isAdmin()) {
            echo json_encode([
                'success' => false,
            ]);

            die;
        }

        echo json_encode([
            'success' => $this->proposalsModel->removeComment($id),
        ]);
    } else {
        echo json_encode([
            'success' => false,
        ]);
    }
}

public function saveProposalData()
{
    if (!hasPermission('proposals', '', 'edit') && !hasPermission('proposals', '', 'create')) {
        header('HTTP/1.0 400 Bad error');
        echo json_encode([
            'success' => false,
            'message' => _l('access_denied'),
        ]);

        die;
    }

    $success = false;
    $message = '';

    $this->db->where('id', $this->input->post('proposal_id'));
    $this->db->update(dbPrefix() . 'proposals', [
        'content' => htmlPurify($this->input->post('content', false)),
    ]);

    $success = $this->db->affected_rows() > 0;
    $message = _l('updated_successfully', _l('proposal'));

    echo json_encode([
        'success' => $success,
        'message' => $message,
    ]);
}

}

