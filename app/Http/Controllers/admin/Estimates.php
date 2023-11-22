<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Estimates;
use App\Models\Taxes;
use App\Models\Currencies;
use App\Models\InvoiceItems;
use App\Models\Staff;
use App\Services\MiscellaneousService; // Adjust the namespace based on your service class

class EstimatesController extends Controller
{
    protected $miscellaneousService;

    public function __construct(MiscellaneousService $miscellaneousService)
    {
        $this->miscellaneousService = $miscellaneousService;
        $this->middleware('admin');
    }

    public function index($id = '')
    {
        return $this->listEstimates($id);
    }

    public function listEstimates($id = '')
    {
        if (!has_permission('estimates', '', 'view') && !has_permission('estimates', '', 'view_own') && get_option('allow_staff_view_estimates_assigned') == '0') {
            access_denied('estimates');
        }

        $isPipeline = session('estimate_pipeline') == 'true';

        $estimateStatuses = Estimates::getStatuses();

        if ($isPipeline && !$request->input('status') && !$request->input('filter')) {
            $title = _l('estimates_pipeline');
            $bodyclass = 'estimates-pipeline estimates-total-manual';
            $switchPipeline = false;

            if (is_numeric($id)) {
                $estimateId = $id;
            } else {
                $estimateId = session('estimateid');
            }

            return view('admin.estimates.pipeline.manage', compact('title', 'bodyclass', 'switchPipeline', 'estimateId'));
        } else {
            if (($request->input('status') || $request->input('filter')) && $isPipeline) {
                $this->pipeline(0, true);
            }

            $estimateId = $id;
            $switchPipeline = true;
            $title = _l('estimates');
            $bodyclass = 'estimates-total-manual';
            $estimatesYears = Estimates::getEstimatesYears();
            $estimatesSaleAgents = Estimates::getSaleAgents();

            return view('admin.estimates.manage', compact('estimateId', 'switchPipeline', 'title', 'bodyclass', 'estimatesYears', 'estimatesSaleAgents'));
        }
    }

    public function table($clientId = '')
    {
        if (!has_permission('estimates', '', 'view') && !has_permission('estimates', '', 'view_own') && get_option('allow_staff_view_estimates_assigned') == '0') {
            ajax_access_denied();
        }

        return $this->app->getTableData('estimates', compact('clientId'));
    }

    public function estimate($id = '')
    {
        if ($request->isMethod('post')) {
            $estimateData = $request->input();

            $saveAndSendLater = false;
            if (isset($estimateData['save_and_send_later'])) {
                unset($estimateData['save_and_send_later']);
                $saveAndSendLater = true;
            }

            if ($id == '') {
                if (!has_permission('estimates', '', 'create')) {
                    access_denied('estimates');
                }

                $id = Estimates::add($estimateData);

                if ($id) {
                    set_alert('success', _l('added_successfully', _l('estimate')));

                    $redUrl = admin_url('estimates/list_estimates/' . $id);

                    if ($saveAndSendLater) {
                        session(['send_later' => true]);
                        // die(redirect($redUrl));
                    }

                    return !$this->setEstimatePipelineAutoload($id) ? redirect($redUrl) : redirect(admin_url('estimates/list_estimates/'));
                }
            } else {
                if (!has_permission('estimates', '', 'edit')) {
                    access_denied('estimates');
                }

                $success = Estimates::update($estimateData, $id);

                if ($success) {
                    set_alert('success', _l('updated_successfully', _l('estimate')));
                }

                return $this->setEstimatePipelineAutoload($id) ? redirect(admin_url('estimates/list_estimates/')) : redirect(admin_url('estimates/list_estimates/' . $id));
            }
        }

        if ($id == '') {
            $title = _l('create_new_estimate');
        } else {
            $estimate = Estimates::find($id);

            if (!$estimate || !user_can_view_estimate($id)) {
                blank_page(_l('estimate_not_found'));
            }

            $data['estimate'] = $estimate;
            $data['edit'] = true;
            $title = _l('edit', _l('estimate_lowercase'));
        }

        if ($request->input('customer_id')) {
            $data['customer_id'] = $request->input('customer_id');
        }

        if ($request->input('estimate_request_id')) {
            $data['estimate_request_id'] = $request->input('estimate_request_id');
        }

        $taxes = Taxes::all();
        $currencies = Currencies::all();
        $baseCurrency = Currencies::getBaseCurrency();

        $invoiceItems = new InvoiceItems();

        $data['ajaxItems'] = false;

        if (total_rows(db_prefix() . 'items') <= ajax_on_total_items()) {
            $data['items'] = $invoiceItems->getGrouped();
        } else {
            $data['items'] = [];
            $data['ajaxItems'] = true;
        }

        $data['itemsGroups'] = $invoiceItems->getGroups();

        $data['staff'] = Staff::where('active', 1)->get();
        $data['estimateStatuses'] = Estimates::getStatuses();
        $data['title'] = $title;

        return view('admin.estimates.estimate', $data);
    }

    public function clearSignature($id)
    {
        if (has_permission('estimates', '', 'delete')) {
            Estimates::clearSignature($id);
        }

        return redirect(admin_url('estimates/list_estimates/' . $id));
    }

    public function updateNumberSettings($id)
    {
        $response = [
            'success' => false,
            'message' => '',
        ];

        if (has_permission('estimates', '', 'edit')) {
            Estimates::where('id', $id)->update([
                'prefix' => $request->input('prefix'),
            ]);

            if (DB::affectedRows() > 0) {
                $response['success'] = true;
                $response['message'] = _l('updated_successfully', _l('estimate'));
            }
        }

        return response()->json($response);
    }

    public function validateEstimateNumber()
    {
        $isEdit = $request->input('isedit');
        $number = $request->input('number');
        $date = $request->input('date');
        $originalNumber = $request->input('original_number');
        $number = trim($number);
        $number = ltrim($number, '0');

        if ($isEdit == 'true') {
            if ($number == $originalNumber) {
                return response()->json(true);
            }
        }

        if (total_rows(db_prefix() . 'estimates', [
            'YEAR(date)' => date('Y', strtotime(to_sql_date($date))),
            'number' => $number,
        ]) > 0) {
            return response()->json(false);
        } else {
            return response()->json(true);
        }
    }

    public function deleteAttachment($id)
    {
        $file = MiscellaneousService::getFile($id);

        if ($file->staffid == get_staff_user_id() || is_admin()) {
            return Estimates::deleteAttachment($id);
        } else {
            abort(400, _l('access_denied'));
        }
    }
    public function __construct(MiscellaneousService $miscellaneousService)
    {
        $this->miscellaneousService = $miscellaneousService;
        $this->middleware('admin');
    }

    public function getEstimateDataAjax($id, $toReturn = false)
    {
        if (!has_permission('estimates', '', 'view') && !has_permission('estimates', '', 'view_own') && get_option('allow_staff_view_estimates_assigned') == '0') {
            return response()->json(_l('access_denied'));
        }

        if (!$id) {
            return response()->json('No estimate found');
        }

        $estimate = Estimates::find($id);

        if (!$estimate || !user_can_view_estimate($id)) {
            return response()->json(_l('estimate_not_found'));
        }

        $estimate->date = _d($estimate->date);
        $estimate->expirydate = _d($estimate->expirydate);

        if ($estimate->invoiceid !== null) {
            $invoiceModel = new \App\Models\Invoices(); // Adjust the namespace based on your model location
            $estimate->invoice = $invoiceModel->find($estimate->invoiceid);
        }

        if ($estimate->sent == 0) {
            $templateName = 'estimate_send_to_customer';
        } else {
            $templateName = 'estimate_send_to_customer_already_sent';
        }

        $data = prepare_mail_preview_data($templateName, $estimate->clientid);

        $data['activity'] = $this->estimates_model->get_estimate_activity($id);
        $data['estimate'] = $estimate;
        $data['members'] = Staff::where('active', 1)->get();
        $data['estimate_statuses'] = Estimates::getStatuses();
        $data['totalNotes'] = total_rows(db_prefix() . 'notes', ['rel_id' => $id, 'rel_type' => 'estimate']);

        $data['send_later'] = false;
        if (Session::has('send_later')) {
            $data['send_later'] = true;
            Session::forget('send_later');
        }

        if ($toReturn == false) {
            return view('admin.estimates.estimate_preview_template', $data);
        } else {
            return view('admin.estimates.estimate_preview_template', $data)->render();
        }
    }

    public function getEstimatesTotal(Request $request)
    {
        if ($request->post()) {
            $data['totals'] = Estimates::getEstimatesTotal($request->post());

            $currenciesModel = new Currencies();
            if (!$request->post('customer_id')) {
                $multipleCurrencies = is_using_multiple_currencies(db_prefix() . 'estimates');
            } else {
                $multipleCurrencies = is_client_using_multiple_currencies($request->post('customer_id'), db_prefix() . 'estimates');
            }

            if ($multipleCurrencies) {
                $data['currencies'] = $currenciesModel->get();
            }

            $data['estimates_years'] = Estimates::getEstimatesYears();

            if (count($data['estimates_years']) >= 1 && !\app\services\utilities\Arr::inMultidimensional($data['estimates_years'], 'year', date('Y'))) {
                array_unshift($data['estimates_years'], ['year' => date('Y')]);
            }

            $data['_currency'] = $data['totals']['currencyid'];
            unset($data['totals']['currencyid']);

            return view('admin.estimates.estimates_total_template', $data);
        }
    }

    public function addNote($relId)
    {
        if (request()->isMethod('post') && user_can_view_estimate($relId)) {
            $this->misc_model->add_note(request()->post(), 'estimate', $relId);
            return response()->json($relId);
        }
    }

    public function getNotes($id)
    {
        if (user_can_view_estimate($id)) {
            $data['notes'] = $this->misc_model->get_notes($id, 'estimate');
            return view('admin.includes.sales_notes_template', $data);
        }
    }

    public function markActionStatus($status, $id)
    {
        if (!has_permission('estimates', '', 'edit')) {
            return response()->json(_l('access_denied'));
        }

        $success = Estimates::markActionStatus($status, $id);

        if ($success) {
            set_alert('success', _l('estimate_status_changed_success'));
        } else {
            set_alert('danger', _l('estimate_status_changed_fail'));
        }

        if ($this->setEstimatePipelineAutoload($id)) {
            return redirect()->back();
        } else {
            return redirect(admin_url('estimates/list_estimates/' . $id));
        }
    }

    public function sendExpiryReminder($id)
    {
        $canView = user_can_view_estimate($id);

        if (!$canView) {
            access_denied('Estimates');
        } else {
            if (!has_permission('estimates', '', 'view') && !has_permission('estimates', '', 'view_own') && $canView == false) {
                access_denied('Estimates');
            }
        }

        $success = Estimates::sendExpiryReminder($id);

        if ($success) {
            set_alert('success', _l('sent_expiry_reminder_success'));
        } else {
            set_alert('danger', _l('sent_expiry_reminder_fail'));
        }

        if ($this->setEstimatePipelineAutoload($id)) {
            return redirect()->back();
        } else {
            return redirect(admin_url('estimates/list_estimates/' . $id));
        }
    }

    /* Send estimate to email */
    public function sendToEmail($id)
    {
        $canView = user_can_view_estimate($id);

        if (!$canView) {
            access_denied('estimates');
        } else {
            if (!has_permission('estimates', '', 'view') && !has_permission('estimates', '', 'view_own') && $canView == false) {
                access_denied('estimates');
            }
        }

        try {
            $success = Estimates::sendEstimateToClient($id, '', request()->post('attach_pdf'), request()->post('cc'));
        } catch (Exception $e) {
            $message = $e->getMessage();
            echo $message;
            if (strpos($message, 'Unable to get the size of the image') !== false) {
                show_pdf_unable_to_get_image_size_error();
            }
            die;
        }

        // In case the client uses another language
        load_admin_language();

        if ($success) {
            set_alert('success', _l('estimate_sent_to_client_success'));
        } else {
            set_alert('danger', _l('estimate_sent_to_client_fail'));
        }

        if ($this->setEstimatePipelineAutoload($id)) {
            return redirect()->back();
        } else {
            return redirect(admin_url('estimates/list_estimates/' . $id));
        }
    }

    /* Convert estimate to invoice */
    public function convertToInvoice($id)
    {
        if (!has_permission('invoices', '', 'create')) {
            access_denied('invoices');
        }

        if (!$id) {
            die('No estimate found');
        }

        $draftInvoice = false;

        if (request()->has('save_as_draft')) {
            $draftInvoice = true;
        }

        $invoiceId = Estimates::convertToInvoice($id, false, $draftInvoice);

        if ($invoiceId) {
            set_alert('success', _l('estimate_convert_to_invoice_successfully'));
            return redirect(admin_url('invoices/list_invoices/' . $invoiceId));
        } else {
            if (Session::has('estimate_pipeline') && Session::get('estimate_pipeline') == 'true') {
                Session::flash('estimateid', $id);
            }

            if ($this->setEstimatePipelineAutoload($id)) {
                return redirect()->back();
            } else {
                return redirect(admin_url('estimates/list_estimates/' . $id));
            }
        }
    }

    public function copy($id)
    {
        if (!has_permission('estimates', '', 'create')) {
            access_denied('estimates');
        }

        if (!$id) {
            die('No estimate found');
        }

        $newId = Estimates::copy($id);

        if ($newId) {
            set_alert('success', _l('estimate_copied_successfully'));
            
            if ($this->setEstimatePipelineAutoload($newId)) {
                return redirect()->back();
            } else {
                return redirect(admin_url('estimates/estimate/' . $newId));
            }
        }

        set_alert('danger', _l('estimate_copied_fail'));

        if ($this->setEstimatePipelineAutoload($id)) {
            return redirect()->back();
        } else {
            return redirect(admin_url('estimates/estimate/' . $id));
        }
    }
    public function __construct(MiscellaneousService $miscellaneousService)
    {
        $this->miscellaneousService = $miscellaneousService;
        $this->middleware('admin');
    }

    public function delete($id)
    {
        if (!has_permission('estimates', '', 'delete')) {
            return response()->json(access_denied('estimates'));
        }

        if (!$id) {
            return redirect()->route('admin.estimates.list_estimates');
        }

        $success = Estimates::delete($id);

        if (is_array($success)) {
            set_alert('warning', _l('is_invoiced_estimate_delete_error'));
        } elseif ($success == true) {
            set_alert('success', _l('deleted', _l('estimate')));
        } else {
            set_alert('warning', _l('problem_deleting', _l('estimate_lowercase')));
        }

        return redirect()->route('admin.estimates.list_estimates');
    }

    public function clearAcceptanceInfo($id)
    {
        if (is_admin()) {
            Estimates::where('id', $id)->update(get_acceptance_info_array(true));
        }

        return redirect()->route('admin.estimates.list_estimates', $id);
    }

    /* Generates estimate PDF and sending to email  */
    public function pdf($id)
    {
        $canView = user_can_view_estimate($id);

        if (!$canView) {
            access_denied('Estimates');
        } else {
            if (!has_permission('estimates', '', 'view') && !has_permission('estimates', '', 'view_own') && $canView == false) {
                access_denied('Estimates');
            }
        }

        if (!$id) {
            return redirect()->route('admin.estimates.list_estimates');
        }

        $estimate = Estimates::find($id);
        $estimateNumber = format_estimate_number($estimate->id);

        try {
            $pdf = estimate_pdf($estimate);
        } catch (Exception $e) {
            $message = $e->getMessage();
            echo $message;
            if (strpos($message, 'Unable to get the size of the image') !== false) {
                show_pdf_unable_to_get_image_size_error();
            }
            die;
        }

        $type = 'D';

        if (request()->has('output_type')) {
            $type = request()->get('output_type');
        }

        if (request()->has('print')) {
            $type = 'I';
        }

        $fileNameHookData = hooks()->apply_filters('estimate_file_name_admin_area', [
            'file_name' => mb_strtoupper(slug_it($estimateNumber)) . '.pdf',
            'estimate' => $estimate,
        ]);

        $pdf->Output($fileNameHookData['file_name'], $type);
    }

    // Pipeline
    public function getPipeline()
    {
        if (has_permission('estimates', '', 'view') || has_permission('estimates', '', 'view_own') || get_option('allow_staff_view_estimates_assigned') == '1') {
            $data['estimate_statuses'] = Estimates::getStatuses();
            return view('admin.estimates.pipeline.pipeline', $data);
        }
    }

    public function pipelineOpen($id)
    {
        $canView = user_can_view_estimate($id);

        if (!$canView) {
            access_denied('Estimates');
        } else {
            if (!has_permission('estimates', '', 'view') && !has_permission('estimates', '', 'view_own') && $canView == false) {
                access_denied('Estimates');
            }
        }

        $data['id'] = $id;
        $data['estimate'] = $this->getEstimateDataAjax($id, true);
        return view('admin.estimates.pipeline.estimate', $data);
    }

    public function updatePipeline()
    {
        if (has_permission('estimates', '', 'edit')) {
            Estimates::updatePipeline(request()->post());
        }
    }

    public function pipeline($set = 0, $manual = false)
    {
        $set = ($set == 1) ? 'true' : 'false';

        Session::put('estimate_pipeline', $set);

        if ($manual == false) {
            return redirect()->route('admin.estimates.list_estimates');
        }
    }

    public function pipelineLoadMore()
    {
        $status = request()->get('status');
        $page = request()->get('page');

        $estimates = (new EstimatesPipeline($status))
            ->search(request()->get('search'))
            ->sortBy(request()->get('sort_by'), request()->get('sort'))
            ->page($page)->get();

        foreach ($estimates as $estimate) {
            return view('admin.estimates.pipeline._kanban_card', [
                'estimate' => $estimate,
                'status' => $status,
            ]);
        }
    }

    public function setEstimatePipelineAutoload($id)
    {
        if ($id == '') {
            return false;
        }

        if (Session::has('estimate_pipeline') && Session::get('estimate_pipeline') == 'true') {
            Session::flash('estimateid', $id);

            return true;
        }

        return false;
    }

    public function getDueDate()
    {
        if (request()->post()) {
            $date = request()->post('date');
            $duedate = '';

            if (get_option('estimate_due_after') != 0) {
                $date = to_sql_date($date);
                $d = date('Y-m-d', strtotime('+' . get_option('estimate_due_after') . ' DAY', strtotime($date)));
                $duedate = _d($d);
                return $duedate;
            }
        }
    }
    
}
