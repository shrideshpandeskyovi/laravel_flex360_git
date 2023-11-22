<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InvoiceItems;
use App\Models\Taxes;
use App\Models\Currencies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceItemsController extends Controller
{
    private $notImportableFields = ['id'];

    public function __construct()
    {
        $this->middleware('permission:items.view|items.create|items.edit|items.delete', ['only' => ['index', 'table']]);
        $this->middleware('permission:items.create', ['only' => ['manage', 'import', 'add_group', 'update_group', 'copy']]);
        $this->middleware('permission:items.edit', ['only' => ['manage', 'update_group', 'copy']]);
        $this->middleware('permission:items.delete', ['only' => ['delete', 'bulk_action']]);
    }

    /* List all available items */
    public function index()
    {
        $this->authorize('items.view');

        $taxes = Taxes::get();
        $itemsGroups = InvoiceItems::getGroups();

        $currencies = Currencies::get();
        $baseCurrency = Currencies::getBaseCurrency();

        $data = [
            'taxes' => $taxes,
            'itemsGroups' => $itemsGroups,
            'currencies' => $currencies,
            'baseCurrency' => $baseCurrency,
            'title' => __('invoice_items'),
        ];

        return view('admin.invoice_items.manage', $data);
    }

    public function table()
    {
        $this->authorize('items.view');
        return app()->get_table_data('invoice_items');
    }

    /* Edit or update items / ajax request /*/
    public function manage(Request $request)
    {
        $this->authorize('items.view');

        if ($request->isMethod('post')) {
            $data = $request->all();

            if (empty($data['itemid'])) {
                $this->authorize('items.create');
                $id = InvoiceItems::add($data);
                $success = $id ? true : false;
                $message = $id ? __('added_successfully', __('sales_item')) : '';
                $item = InvoiceItems::find($id);

                return response()->json([
                    'success' => $success,
                    'message' => $message,
                    'item' => $item,
                ]);
            } else {
                $this->authorize('items.edit');
                $success = InvoiceItems::edit($data);
                $message = $success ? __('updated_successfully', __('sales_item')) : '';

                return response()->json([
                    'success' => $success,
                    'message' => $message,
                ]);
            }
        }
    }

    public function import(Request $request)
    {
        $this->authorize('items.create');

        $import = app()->make('import.import_items');

        $import->setDatabaseFields(DB::getSchemaBuilder()->getColumnListing('items'))
            ->setCustomFields(get_custom_fields('items'));

        if ($request->input('download_sample') === 'true') {
            $import->downloadSample();
        }

        if ($request->isMethod('post') && $request->hasFile('file_csv') && $request->file('file_csv')->isValid()) {
            $import->setSimulation($request->input('simulate'))
                ->setTemporaryFileLocation($request->file('file_csv')->getPathname())
                ->setFilename($request->file('file_csv')->getClientOriginalName())
                ->perform();

            $data['total_rows_post'] = $import->totalRows();

            if (!$import->isSimulation()) {
                session()->flash('alert-success', __('import_total_imported', $import->totalImported()));
            }
        }

        $data['title'] = __('import');
        return view('admin.invoice_items.import', $data);
    }

    
    public function add_group(Request $request)
    {
        $this->authorize('items.create');
        
        if ($request->isMethod('post')) {
            InvoiceItems::addGroup($request->all());
            session()->flash('alert-success', __('added_successfully', __('item_group')));
        }
    }

    public function update_group(Request $request, $id)
    {
        $this->authorize('items.edit');
        
        if ($request->isMethod('post')) {
            InvoiceItems::editGroup($request->all(), $id);
            session()->flash('alert-success', __('updated_successfully', __('item_group')));
        }
    }

    public function delete_group($id)
    {
        $this->authorize('items.delete');
        
        if (InvoiceItems::deleteGroup($id)) {
            session()->flash('alert-success', __('deleted', __('item_group')));
        }

        return redirect(admin_url('invoice_items?groups_modal=true'));
    }

    /* Delete item*/
    public function delete($id)
    {
        $this->authorize('items.delete');
        
        if (!$id) {
            return redirect(admin_url('invoice_items'));
        }

        $response = InvoiceItems::delete($id);
        
        if (is_array($response) && isset($response['referenced'])) {
            session()->flash('alert-warning', __('is_referenced', __('invoice_item_lowercase')));
        } elseif ($response == true) {
            session()->flash('alert-success', __('deleted', __('invoice_item')));
        } else {
            session()->flash('alert-warning', __('problem_deleting', __('invoice_item_lowercase')));
        }

        return redirect(admin_url('invoice_items'));
    }

    public function bulk_action(Request $request)
    {
        hooks()->do_action('before_do_bulk_action_for_items');
        $total_deleted = 0;

        if ($request->isMethod('post')) {
            $ids = $request->input('ids');
            $has_permission_delete = auth()->user()->can('items.delete');

            if (is_array($ids)) {
                foreach ($ids as $id) {
                    if ($request->input('mass_delete')) {
                        if ($has_permission_delete) {
                            if (InvoiceItems::delete($id)) {
                                $total_deleted++;
                            }
                        }
                    }
                }
            }
        }

        if ($request->input('mass_delete')) {
            session()->flash('alert-success', __('total_items_deleted', $total_deleted));
        }
    }

    public function search(Request $request)
    {
        if ($request->isMethod('post') && $request->ajax()) {
            return response()->json(InvoiceItems::search($request->input('q')));
        }
    }

    /* Get item by id / ajax */
    public function get_item_by_id($id)
    {
        if (request()->ajax()) {
            $item = InvoiceItems::find($id);
            $item->long_description = nl2br($item->long_description);
            $item->custom_fields_html = render_custom_fields('items', $id, [], ['items_pr' => true]);
            $item->custom_fields = [];

            $cf = CustomFields::getCustomFields('items');

            foreach ($cf as $custom_field) {
                $val = get_custom_field_value($id, $custom_field['id'], 'items_pr');
                if ($custom_field['type'] == 'textarea') {
                    $val = clear_textarea_breaks($val);
                }
                $custom_field['value'] = $val;
                $item->custom_fields[] = $custom_field;
            }

            return response()->json($item);
        }
    }

    /* Copy Item */
    public function copy($id)
    {
        $this->authorize('items.create');
        
        $data = InvoiceItems::find($id)->toArray();

        $newItemId = InvoiceItems::copy($data);

        if ($newItemId) {
            session()->flash('alert-success', __('item_copy_success'));
            return redirect(admin_url('invoice_items?id=' . $newItemId));
        }

        session()->flash('alert-warning', __('item_copy_fail'));
        return redirect(admin_url('invoice_items'));
    }
    public function addNote($relId)
    {
        if (request()->post() && $this->userCanViewInvoice($relId)) {
            $this->miscModel->addNote(request()->post(), 'invoice', $relId);
            return response()->json($relId);
        }
    }

    public function getNotes($id)
    {
        if ($this->userCanViewInvoice($id)) {
            $data['notes'] = $this->miscModel->getNotes($id, 'invoice');
            return view('admin.includes.sales_notes_template', $data);
        }
    }

    public function pauseOverdueReminders($id)
    {
        if (auth()->user()->can('edit_invoices')) {
            DB::table('invoices')->where('id', $id)->update(['cancel_overdue_reminders' => 1]);
        }
        return redirect(route('admin.invoices.list_invoices', $id));
    }

    public function resumeOverdueReminders($id)
    {
        if (auth()->user()->can('edit_invoices')) {
            DB::table('invoices')->where('id', $id)->update(['cancel_overdue_reminders' => 0]);
        }
        return redirect(route('admin.invoices.list_invoices', $id));
    }

    public function markAsCancelled($id)
    {
        if (!auth()->user()->can('edit_invoices') && !auth()->user()->can('create_invoices')) {
            abort(403, 'Access denied');
        }

        $success = $this->invoicesModel->markAsCancelled($id);

        if ($success) {
            session()->flash('success', __('invoice_marked_as_cancelled_successfully'));
        }

        return redirect(route('admin.invoices.list_invoices', $id));
    }

    public function unmarkAsCancelled($id)
    {
        if (!auth()->user()->can('edit_invoices') && !auth()->user()->can('create_invoices')) {
            abort(403, 'Access denied');
        }

        $success = $this->invoicesModel->unmarkAsCancelled($id);

        if ($success) {
            session()->flash('success', __('invoice_unmarked_as_cancelled'));
        }

        return redirect(route('admin.invoices.list_invoices', $id));
    }

    public function copy($id)
    {
        if (!$id) {
            return redirect(route('admin.invoices'));
        }

        if (!auth()->user()->can('create_invoices')) {
            abort(403, 'Access denied');
        }

        $newId = $this->invoicesModel->copy($id);

        if ($newId) {
            session()->flash('success', __('invoice_copy_success'));
            return redirect(route('admin.invoices.invoice', $newId));
        } else {
            session()->flash('success', __('invoice_copy_fail'));
        }

        return redirect(route('admin.invoices.invoice', $id));
    }

    public function getMergeData($id)
    {
        $invoice = $this->invoicesModel->get($id);
        $cf = getCustomFields('items');

        $i = 0;

        foreach ($invoice->items as $item) {
            $invoice->items[$i]['taxname'] = getInvoiceItemTaxes($item['id']);
            $invoice->items[$i]['long_description'] = clearTextareaBreaks($item['long_description']);
            $rel = DB::table('related_items')->where('item_id', $item['id'])->get()->toArray();
            $itemRelatedVal = '';
            $relType = '';
            foreach ($rel as $itemRelated) {
                $relType = $itemRelated->rel_type;
                $itemRelatedVal .= $itemRelated->rel_id . ',';
            }
            if ($itemRelatedVal != '') {
                $itemRelatedVal = substr($itemRelatedVal, 0, -1);
            }
            $invoice->items[$i]['item_related_formatted_for_input'] = $itemRelatedVal;
            $invoice->items[$i]['rel_type'] = $relType;

            $invoice->items[$i]['custom_fields'] = [];

            foreach ($cf as $customField) {
                $customField['value'] = getCustomFieldValue($item['id'], $customField['id'], 'items');
                $invoice->items[$i]['custom_fields'][] = $customField;
            }
            $i++;
        }

        return response()->json($invoice);
    }
    public function getBillExpenseData($id)
    {
        $expensesModel = app('App\Models\ExpensesModel');
        $expense = $expensesModel->find($id);

        $expense->qty = 1;
        $expense->long_description = clearTextareaBreaks($expense->description);
        $expense->description = $expense->name;
        $expense->rate = $expense->amount;
        if ($expense->tax != 0) {
            $expense->taxname = [];
            array_push($expense->taxname, $expense->tax_name . '|' . $expense->taxrate);
        }
        if ($expense->tax2 != 0) {
            array_push($expense->taxname, $expense->tax_name2 . '|' . $expense->taxrate2);
        }

        return response()->json($expense);
    }

    public function invoice($id = '')
    {
        if (request()->isMethod('post')) {
            $invoiceData = request()->all();
            if (empty($id)) {
                if (!auth()->user()->can('create_invoices')) {
                    abort(403, 'Access denied');
                }

                if (hooks()->apply_filters('validate_invoice_number', true)) {
                    $number = ltrim($invoiceData['number'], '0');
                    if (DB::table('invoices')->where([
                        ['date', 'YEAR(date) = ?', date('Y', strtotime(toSqlDate($invoiceData['date'])))],
                        ['number', '=', $number],
                        ['status', '!=', 'draft'],
                    ])->exists()) {
                        session()->flash('warning', __('invoice_number_exists'));
                        return redirect(route('admin.invoices.invoice'));
                    }
                }

                $id = app('App\Models\InvoicesModel')->add($invoiceData);
                if ($id) {
                    session()->flash('success', __('added_successfully', __('invoice')));
                    $redUrl = route('admin.invoices.list_invoices', $id);

                    if (isset($invoiceData['save_and_record_payment'])) {
                        session()->put('record_payment', true);
                    } elseif (isset($invoiceData['save_and_send_later'])) {
                        session()->put('send_later', true);
                    }

                    return redirect($redUrl);
                }
            } else {
                if (!auth()->user()->can('edit_invoices')) {
                    abort(403, 'Access denied');
                }

                // If number not set, is draft
                if (hooks()->apply_filters('validate_invoice_number', true) && isset($invoiceData['number'])) {
                    $number = trim(ltrim($invoiceData['number'], '0'));
                    if (DB::table('invoices')->where([
                        ['date', 'YEAR(date) = ?', date('Y', strtotime(toSqlDate($invoiceData['date'])))],
                        ['number', '=', $number],
                        ['status', '!=', 'draft'],
                        ['id', '!=', $id],
                    ])->exists()) {
                        session()->flash('warning', __('invoice_number_exists'));
                        return redirect(route('admin.invoices.invoice', $id));
                    }
                }

                $success = app('App\Models\InvoicesModel')->update($invoiceData, $id);
                if ($success) {
                    session()->flash('success', __('updated_successfully', __('invoice')));
                }

                return redirect(route('admin.invoices.list_invoices', $id));
            }
        }

        if (empty($id)) {
            $title = __('create_new_invoice');
            $data['billable_tasks'] = [];
        } else {
            $invoice = app('App\Models\InvoicesModel')->find($id);

            if (!$invoice || !$this->userCanViewInvoice($id)) {
                abort(404, __('invoice_not_found'));
            }

            $data['invoices_to_merge'] = app('App\Models\InvoicesModel')->checkForMergeInvoice($invoice->clientid, $invoice->id);
            $data['expenses_to_bill'] = app('App\Models\InvoicesModel')->getExpensesToBill($invoice->clientid);

            $data['invoice'] = $invoice;
            $data['edit'] = true;
            $data['billable_tasks'] = app('App\Models\TasksModel')->getBillableTasks($invoice->clientid, !empty($invoice->project_id) ? $invoice->project_id : '');

            $title = __('edit', __('invoice_lowercase')) . ' - ' . formatInvoiceNumber($invoice->id);
        }

        if (request()->has('customer_id')) {
            $data['customer_id'] = request()->get('customer_id');
        }

        $data['payment_modes'] = app('App\Models\PaymentModesModel')->where('expenses_only', '!=', 1)->get();

        $data['taxes'] = app('App\Models\TaxesModel')->get();
        $data['invoice_items_model'] = app('App\Models\InvoiceItemsModel');

        $data['ajaxItems'] = false;
        if (DB::table('items')->count() <= ajax_on_total_items()) {
            $data['items'] = app('App\Models\InvoiceItemsModel')->getGrouped();
        } else {
            $data['items'] = [];
            $data['ajaxItems'] = true;
        }
        $data['items_groups'] = app('App\Models\InvoiceItemsModel')->getGroups();

        $data['currencies'] = app('App\Models\CurrenciesModel')->get();
        $data['base_currency'] = app('App\Models\CurrenciesModel')->getBaseCurrency();

        $data['staff'] = app('App\Models\StaffModel')->where('active', 1)->get();
        $data['title'] = $title;
        $data['bodyclass'] = 'invoice';

        return view('admin.invoices.invoice', $data);
    }
    public function getInvoiceDataAjax($id)
    {
        if (!auth()->user()->can('view_invoices') && !auth()->user()->can('view_own_invoices')
            && get_option('allow_staff_view_invoices_assigned') == '0') {
            return response()->json(['message' => __('access_denied')]);
        }

        if (!$id) {
            return response()->json(['message' => __('invoice_not_found')]);
        }

        $invoice = app('App\Models\InvoicesModel')->find($id);

        if (!$invoice || !$this->userCanViewInvoice($id)) {
            return response()->json(['message' => __('invoice_not_found')]);
        }

        $templateName = 'invoice_send_to_customer';

        if ($invoice->sent == 1) {
            $templateName = 'invoice_send_to_customer_already_sent';
        }

        $data = $this->prepareMailPreviewData($templateName, $invoice->clientid);

        $data['invoices_to_merge'] = app('App\Models\InvoicesModel')->checkForMergeInvoice($invoice->clientid, $id);
        $data['members'] = app('App\Models\StaffModel')->where('active', 1)->get();
        $data['payments'] = app('App\Models\PaymentsModel')->getInvoicePayments($id);
        $data['activity'] = app('App\Models\InvoicesModel')->getInvoiceActivity($id);
        $data['totalNotes'] = DB::table(db_prefix() . 'notes')->where(['rel_id' => $id, 'rel_type' => 'invoice'])->count();
        $data['invoice_recurring_invoices'] = app('App\Models\InvoicesModel')->getInvoiceRecurringInvoices($id);

        $data['applied_credits'] = app('App\Models\CreditNotesModel')->getAppliedInvoiceCredits($id);
        if (creditsCanBeAppliedToInvoice($invoice->status)) {
            $data['credits_available'] = app('App\Models\CreditNotesModel')->totalRemainingCreditsByCustomer($invoice->clientid);

            if ($data['credits_available'] > 0) {
                $data['open_credits'] = app('App\Models\CreditNotesModel')->getOpenCredits($invoice->clientid);
            }

            $customerCurrency = app('App\Models\ClientsModel')->getCustomerDefaultCurrency($invoice->clientid);
            $currenciesModel = app('App\Models\CurrenciesModel');

            if ($customerCurrency != 0) {
                $data['customer_currency'] = $currenciesModel->find($customerCurrency);
            } else {
                $data['customer_currency'] = $currenciesModel->getBaseCurrency();
            }
        }

        $data['invoice'] = $invoice;

        $data['record_payment'] = false;
        $data['send_later'] = false;

        if (session()->has('record_payment')) {
            $data['record_payment'] = true;
            session()->forget('record_payment');
        } elseif (session()->has('send_later')) {
            $data['send_later'] = true;
            session()->forget('send_later');
        }

        return view('admin.invoices.invoice_preview_template', $data);
    }

    public function applyCredits($invoiceId)
    {
        $totalCreditsApplied = 0;
        foreach (request()->input('amount') as $creditId => $amount) {
            $success = app('App\Models\CreditNotesModel')->applyCredits($creditId, [
                'invoice_id' => $invoiceId,
                'amount' => $amount,
            ]);
            if ($success) {
                $totalCreditsApplied++;
            }
        }

        if ($totalCreditsApplied > 0) {
            updateInvoiceStatus($invoiceId, true);
            session()->flash('success', __('invoice_credits_applied'));
        }

        return redirect(route('admin.invoices.list_invoices', $invoiceId));
    }

    public function getInvoicesTotal()
    {
        if (request()->isMethod('post')) {
            loadInvoicesTotalTemplate();
        }
    }

    public function recordInvoicePaymentAjax($id)
    {
        $data['payment_modes'] = app('App\Models\PaymentModesModel')->where('expenses_only', '!=', 1)->get();
        $data['invoice'] = app('App\Models\InvoicesModel')->find($id);
        $data['payments'] = app('App\Models\PaymentsModel')->getInvoicePayments($id);

        return view('admin.invoices.record_payment_template', $data);
    }

    public function recordPayment()
    {
        if (!auth()->user()->can('create_payments')) {
            abort(403, 'Access denied');
        }

        if (request()->isMethod('post')) {
            $id = app('App\Models\PaymentsModel')->processPayment(request()->all(), '');

            if ($id) {
                session()->flash('success', __('invoice_payment_recorded'));
                return redirect(route('admin.payments.payment', $id));
            } else {
                session()->flash('danger', __('invoice_payment_record_failed'));
            }

            return redirect(route('admin.invoices.list_invoices', request()->input('invoiceid')));
        }
    }

    public function sendToEmail($id)
    {
        $canView = $this->userCanViewInvoice($id);
        if (!$canView) {
            abort(403, 'Access denied');
        } else {
            if (!auth()->user()->can('view_invoices') && !auth()->user()->can('view_own_invoices') && !$canView) {
                abort(403, 'Access denied');
            }
        }

        try {
            $statementData = [];
            if (request()->input('attach_statement')) {
                $statementData['attach'] = true;
                $statementData['from'] = toSqlDate(request()->input('statement_from'));
                $statementData['to'] = toSqlDate(request()->input('statement_to'));
            }

            $success = app('App\Models\InvoicesModel')->sendInvoiceToClient(
                $id,
                '',
                request()->input('attach_pdf'),
                request()->input('cc'),
                false,
                $statementData
            );
        } catch (Exception $e) {
            $message = $e->getMessage();
            echo $message;
            if (strpos($message, 'Unable to get the size of the image') !== false) {
                showPdfUnableToGetImageSizeError();
            }
            die;
        }

        loadAdminLanguage();
        if ($success) {
            session()->flash('success', __('invoice_sent_to_client_success'));
        } else {
            session()->flash('danger', __('invoice_sent_to_client_fail'));
        }

        return redirect(route('admin.invoices.list_invoices', $id));
    }
    public function deletePayment($id, $invoiceid)
    {
        if (!auth()->user()->can('delete_payments')) {
            abort(403, 'Access denied');
        }

        $paymentModel = app('App\Models\PaymentsModel');
        if (!$id) {
            return redirect(route('admin.payments.index'));
        }

        $response = $paymentModel->delete($id);
        if ($response == true) {
            session()->flash('success', __('deleted', ['item' => __('payment')]));
        } else {
            session()->flash('warning', __('problem_deleting', ['item' => __('payment_lowercase')]));
        }

        return redirect(route('admin.invoices.list_invoices', $invoiceid));
    }

    public function delete($id)
    {
        if (!auth()->user()->can('delete_invoices')) {
            abort(403, 'Access denied');
        }

        if (!$id) {
            return redirect(route('admin.invoices.list_invoices'));
        }

        $success = app('App\Models\InvoicesModel')->delete($id);

        if ($success) {
            session()->flash('success', __('deleted', ['item' => __('invoice')]));
        } else {
            session()->flash('warning', __('problem_deleting', ['item' => __('invoice_lowercase')]));
        }

        return redirect()->back();
    }

    public function deleteAttachment($id)
    {
        $file = app('App\Models\MiscModel')->getFile($id);
        if ($file->staffid == auth()->id() || auth()->user()->is_admin()) {
            echo app('App\Models\InvoicesModel')->deleteAttachment($id);
        } else {
            abort(400, 'Bad error');
        }
    }

    public function sendOverdueNotice($id)
    {
        $canView = $this->userCanViewInvoice($id);
        if (!$canView) {
            abort(403, 'Access denied');
        } else {
            if (!auth()->user()->can('view_invoices') && !auth()->user()->can('view_own_invoices') && $canView == false) {
                abort(403, 'Access denied');
            }
        }

        $send = app('App\Models\InvoicesModel')->sendInvoiceOverdueNotice($id);
        if ($send) {
            session()->flash('success', __('invoice_overdue_reminder_sent'));
        } else {
            session()->flash('warning', __('invoice_reminder_send_problem'));
        }

        return redirect(route('admin.invoices.list_invoices', $id));
    }

    public function pdf($id)
    {
        if (!$id) {
            return redirect(route('admin.invoices.list_invoices'));
        }

        $canView = $this->userCanViewInvoice($id);
        if (!$canView) {
            abort(403, 'Access denied');
        } else {
            if (!auth()->user()->can('view_invoices') && !auth()->user()->can('view_own_invoices') && $canView == false) {
                abort(403, 'Access denied');
            }
        }

        $invoice = app('App\Models\InvoicesModel')->find($id);
        $invoice = hooks()->applyFilters('before_admin_view_invoice_pdf', $invoice);
        $invoiceNumber = formatInvoiceNumber($invoice->id);

        try {
            $pdf = invoicePdf($invoice);
        } catch (Exception $e) {
            $message = $e->getMessage();
            echo $message;
            if (strpos($message, 'Unable to get the size of the image') !== false) {
                showPdfUnableToGetImageSizeError();
            }
            die;
        }

        $type = 'D';

        if (request()->get('output_type')) {
            $type = request()->get('output_type');
        }

        if (request()->get('print')) {
            $type = 'I';
        }

        return $pdf->Output(mb_strtoupper(slugIt($invoiceNumber)) . '.pdf', $type);
    }

    public function markAsSent($id)
    {
        if (!$id) {
            return redirect(route('admin.invoices.list_invoices'));
        }

        if (!$this->userCanViewInvoice($id)) {
            abort(403, 'Access denied');
        }

        $success = app('App\Models\InvoicesModel')->setInvoiceSent($id, true);

        if ($success) {
            session()->flash('success', __('invoice_marked_as_sent'));
        } else {
            session()->flash('warning', __('invoice_marked_as_sent_failed'));
        }

        return redirect(route('admin.invoices.list_invoices', $id));
    }

    public function getDueDate()
    {
        if (request()->isMethod('post')) {
            $date = request()->post('date');
            $dueDate = '';

            if (getOption('invoice_due_after') != 0) {
                $date = toSqlDate($date);
                $dueDate = _d(date('Y-m-d', strtotime('+' . getOption('invoice_due_after') . ' DAY', strtotime($date))));
            }

            return $dueDate;
        }
    }

}

