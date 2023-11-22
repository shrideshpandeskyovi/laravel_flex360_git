<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Expenses;
use App\Models\PaymentModes;
use App\Models\Taxes;
use App\Models\Currencies;
use App\Services\Import\ImportExpenses;
use Exception;

class ExpensesController extends Controller
{
    protected $importExpenses;

    public function __construct(ImportExpenses $importExpenses)
    {
        $this->middleware('admin');
        $this->importExpenses = $importExpenses;
    }

    public function index($id = '')
    {
        return $this->listExpenses($id);
    }

    public function listExpenses($id = '')
    {
        close_setup_menu();

        if (!has_permission('expenses', '', 'view') && !has_permission('expenses', '', 'view_own')) {
            access_denied('expenses');
        }

        $paymentModes = PaymentModes::all();
        $data['payment_modes'] = $paymentModes;
        $data['expenseid'] = $id;
        $data['categories'] = Expenses::getCategory();
        $data['years'] = Expenses::getExpensesYears();
        $data['title'] = _l('expenses');

        return view('admin.expenses.manage', $data);
    }

    public function table($clientid = '')
    {
        if (!has_permission('expenses', '', 'view') && !has_permission('expenses', '', 'view_own')) {
            ajax_access_denied();
        }

        $paymentModes = PaymentModes::all();
        $data['payment_modes'] = $paymentModes;
        
        return Expenses::getTableData($clientid, $data);
    }

    public function expense($id = '')
    {
        if (request()->isMethod('post')) {
            if ($id == '') {
                if (!has_permission('expenses', '', 'create')) {
                    set_alert('danger', _l('access_denied'));
                    return response()->json([
                        'url' => admin_url('expenses/expense'),
                    ]);
                }

                $expense = Expenses::create(request()->input());

                if ($expense) {
                    set_alert('success', _l('added_successfully', _l('expense')));
                    return response()->json([
                        'url' => admin_url('expenses/list_expenses/' . $expense->id),
                        'expenseid' => $expense->id,
                    ]);
                }

                return response()->json([
                    'url' => admin_url('expenses/expense'),
                ]);
            }

            if (!has_permission('expenses', '', 'edit')) {
                set_alert('danger', _l('access_denied'));
                return response()->json([
                    'url' => admin_url('expenses/expense/' . $id),
                ]);
            }

            $expense = Expenses::find($id);
            $success = $expense->update(request()->input());

            if ($success) {
                set_alert('success', _l('updated_successfully', _l('expense')));
            }

            return response()->json([
                'url' => admin_url('expenses/list_expenses/' . $id),
                'expenseid' => $id,
            ]);
        }

        if ($id == '') {
            $title = _l('add_new', _l('expense'));
        } else {
            $data['expense'] = Expenses::find($id);

            if (!$data['expense'] || (!has_permission('expenses', '', 'view') && $data['expense']->addedfrom != get_staff_user_id())) {
                return blank_page(_l('expense_not_found'));
            }

            $title = _l('edit', _l('expense'));
        }

        $customer_id = request()->get('customer_id');
        $data['customer_id'] = $customer_id;

        $taxes = Taxes::all();
        $paymentModes = PaymentModes::where('invoices_only', '!=', 1)->get();
        $currencies = Currencies::all();

        $data['taxes'] = $taxes;
        $data['categories'] = Expenses::getCategory();
        $data['payment_modes'] = $paymentModes;
        $data['bodyclass'] = 'expense';
        $data['currencies'] = $currencies;
        $data['title'] = $title;

        return view('admin.expenses.expense', $data);
    }

    public function import()
    {
        if (!staff_can('create', 'expenses')) {
            access_denied('Items Import');
        }

        $this->importExpenses->setDatabaseFields(\Schema::getColumnListing('expenses'))
            ->setCustomFields(get_custom_fields('expenses'));

        if (request()->post('download_sample') === 'true') {
            $this->importExpenses->downloadSample();
        }

        if (
            request()->post()
            && request()->hasFile('file_csv') && request()->file('file_csv')->isValid()
        ) {
            $this->importExpenses->setSimulation(request()->post('simulate'))
                ->setTemporaryFileLocation(request()->file('file_csv')->path())
                ->setFilename(request()->file('file_csv')->getClientOriginalName())
                ->perform();

            $data['total_rows_post'] = $this->importExpenses->totalRows();

            if (!$this->importExpenses->isSimulation()) {
                set_alert('success', _l('import_total_imported', $this->importExpenses->totalImported()));
            }
        }

        $data['title'] = _l('import');
        return view('admin.expenses.import', $data);
    }

    public function bulkAction()
    {
        hooks()->do_action('before_do_bulk_action_for_expenses');
        $total_deleted = 0;
        $total_updated = 0;

        if (request()->post()) {
            $ids = request()->post('ids');
            $amount = request()->post('amount');
            $date = request()->post('date');
            $category = request()->post('category');
            $paymentmode = request()->post('paymentmode');

            if (is_array($ids)) {
                foreach ($ids as $id) {
                    if (request()->post('mass_delete')) {
                        if (staff_can('delete', 'expenses')) {
                            $expense = Expenses::find($id);

                            if ($expense) {
                                $expense->delete();
                                $total_deleted++;
                            }
                        }
                    } else {
                        if (staff_can('edit', 'expenses')) {
                            $expense = Expenses::find($id);

                            if ($expense) {
                                $expense->update(array_filter([
                                    'paymentmode' => $paymentmode ?: null,
                                    'category' => $category ?: null,
                                    'date' => $date ? to_sql_date($date) : null,
                                    'amount' => $amount ?: null,
                                ]));

                                if ($expense->wasChanged()) {
                                    $total_updated++;
                                }
                            }
                        }
                    }
                }
            }

            if ($total_updated > 0) {
                set_alert('success', _l('updated_successfully', _l('expenses')));
            } elseif (request()->post('mass_delete')) {
                set_alert('success', _l('total_expenses_deleted', $total_deleted));
            }
        }
    }
    public function getExpensesTotal()
{
    if (request()->post()) {
        $data['totals'] = Expenses::getExpensesTotal(request()->post());

        if ($data['totals']['currency_switcher'] == true) {
            $currencies = Currencies::all();
            $data['currencies'] = $currencies;
        }

        $data['expenses_years'] = Expenses::getExpensesYears();

        if (count($data['expenses_years']) >= 1 && $data['expenses_years'][0]['year'] != date('Y')) {
            array_unshift($data['expenses_years'], ['year' => date('Y')]);
        }

        $data['_currency'] = $data['totals']['currencyid'];

        return view('admin.expenses.expenses_total_template', $data);
    }
}

public function delete($id)
{
    if (!has_permission('expenses', '', 'delete')) {
        access_denied('expenses');
    }

    if (!$id) {
        return redirect(admin_url('expenses/list_expenses'));
    }

    $response = Expenses::delete($id);

    if ($response === true) {
        set_alert('success', _l('deleted', _l('expense')));
    } else {
        if (is_array($response) && $response['invoiced'] == true) {
            set_alert('warning', _l('expense_invoice_delete_not_allowed'));
        } else {
            set_alert('warning', _l('problem_deleting', _l('expense_lowercase')));
        }
    }

    if (strpos(request()->server('HTTP_REFERER'), 'expenses/') !== false) {
        return redirect(admin_url('expenses/list_expenses'));
    } else {
        return redirect(request()->server('HTTP_REFERER'));
    }
}

public function copy($id)
{
    if (!has_permission('expenses', '', 'create')) {
        access_denied('expenses');
    }

    $newExpenseId = Expenses::copy($id);

    if ($newExpenseId) {
        set_alert('success', _l('expense_copy_success'));
        return redirect(admin_url('expenses/expense/' . $newExpenseId));
    } else {
        set_alert('warning', _l('expense_copy_fail'));
    }

    return redirect(admin_url('expenses/list_expenses/' . $id));
}

public function convertToInvoice($id)
{
    if (!has_permission('invoices', '', 'create')) {
        access_denied('Convert Expense to Invoice');
    }

    if (!$id) {
        return redirect(admin_url('expenses/list_expenses'));
    }

    $draftInvoice = request()->get('save_as_draft') ? true : false;

    $params = [];
    if (request()->get('include_note') == 'true') {
        $params['include_note'] = true;
    }

    if (request()->get('include_name') == 'true') {
        $params['include_name'] = true;
    }

    $invoiceId = Expenses::convertToInvoice($id, $draftInvoice, $params);

    if ($invoiceId) {
        set_alert('success', _l('expense_converted_to_invoice'));
        return redirect(admin_url('invoices/invoice/' . $invoiceId));
    } else {
        set_alert('warning', _l('expense_converted_to_invoice_fail'));
    }

    return redirect(admin_url('expenses/list_expenses/' . $id));
}
public function getExpenseDataAjax($id)
{
    if (!has_permission('expenses', '', 'view') && !has_permission('expenses', '', 'view_own')) {
        echo _l('access_denied');
        die;
    }

    $expense = Expenses::get($id);

    if (!$expense || (!has_permission('expenses', '', 'view') && $expense->addedfrom != get_staff_user_id())) {
        echo _l('expense_not_found');
        die;
    }

    $data['expense'] = $expense;

    if ($expense->billable == 1) {
        if ($expense->invoiceid !== null) {
            $data['invoice'] = Invoices::get($expense->invoiceid);
        }
    }

    $data['child_expenses'] = Expenses::getChildExpenses($id);
    $data['members'] = Staff::where('active', 1)->get();
    return view('admin.expenses.expense_preview_template', $data);
}

public function getCustomerChangeData($customerId = '')
{
    return response()->json([
        'customer_has_projects' => customer_has_projects($customerId),
        'client_currency' => Clients::getCustomerDefaultCurrency($customerId),
    ]);
}

public function categories()
{
    if (!is_admin()) {
        access_denied('expenses');
    }

    if (request()->ajax()) {
        return ExpensesCategories::getTableData();
    }

    $data['title'] = _l('expense_categories');
    return view('admin.expenses.manage_categories', $data);
}

public function category()
{
    if (!is_admin() && get_option('staff_members_create_inline_expense_categories') == '0') {
        access_denied('expenses');
    }

    if (request()->post()) {
        $data = request()->post();

        if (!$data['id']) {
            $category = Expenses::addCategory($data);

            return response()->json([
                'success' => $category ? true : false,
                'message' => $category ? _l('added_successfully', _l('expense_category')) : '',
                'id' => $category ? $category->id : null,
                'name' => $data['name'],
            ]);
        } else {
            $categoryId = $data['id'];
            unset($data['id']);

            $success = Expenses::updateCategory($data, $categoryId);

            $message = _l('updated_successfully', _l('expense_category'));

            return response()->json(['success' => $success, 'message' => $message]);
        }
    }
}

public function deleteCategory($id)
{
    if (!is_admin()) {
        access_denied('expenses');
    }

    if (!$id) {
        return redirect(admin_url('expenses/categories'));
    }

    $response = Expenses::deleteCategory($id);

    if (is_array($response) && isset($response['referenced'])) {
        set_alert('warning', _l('is_referenced', _l('expense_category_lowercase')));
    } elseif ($response == true) {
        set_alert('success', _l('deleted', _l('expense_category')));
    } else {
        set_alert('warning', _l('problem_deleting', _l('expense_category_lowercase')));
    }

    return redirect(admin_url('expenses/categories'));
}

public function addExpenseAttachment($id)
{
    handle_expense_attachments($id);

    return response()->json([
        'url' => admin_url('expenses/list_expenses/' . $id),
    ]);
}

public function deleteExpenseAttachment($id, $preview = '')
{
    $file = Files::where('rel_id', $id)
        ->where('rel_type', 'expense')
        ->first();

    if ($file->staffid == get_staff_user_id() || is_admin()) {
        $success = Expenses::deleteExpenseAttachment($id);

        if ($success) {
            set_alert('success', _l('deleted', _l('expense_receipt')));
        } else {
            set_alert('warning', _l('problem_deleting', _l('expense_receipt_lowercase')));
        }

        if ($preview == '') {
            return redirect(admin_url('expenses/expense/' . $id));
        } else {
            return redirect(admin_url('expenses/list_expenses/' . $id));
        }
    } else {
        access_denied('expenses');
    }
}

   
}