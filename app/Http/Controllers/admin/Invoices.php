// app/Http/Controllers/InvoicesController.php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoicesController extends Controller
{
    public function __construct()
    {
        // Constructor logic (if any)
    }

    public function index($id = '')
    {
        return $this->listInvoices($id);
    }

    public function listInvoices($id = '')
    {
        if (!auth()->user()->can('view_invoices') && !auth()->user()->can('view_own_invoices')
            && config('app.allow_staff_view_invoices_assigned') == '0') {
            abort(403, 'Access denied');
        }

        closeSetupMenu();

        $paymentModes = DB::table('payment_modes')->get();
        $invoiceId = $id;
        $title = __('invoices');
        $invoicesYears = DB::table('invoices')->distinct()->get(['YEAR(date) as year']);
        $invoicesSaleAgents = DB::table('invoices')->distinct()->get(['sale_agent']);
        $invoicesStatuses = DB::table('invoices_statuses')->get();
        $bodyClass = 'invoices-total-manual';

        $data = compact('paymentModes', 'invoiceId', 'title', 'invoicesYears', 'invoicesSaleAgents', 'invoicesStatuses', 'bodyClass');

        return view('admin.invoices.manage', $data);
    }

    public function recurring($id = '')
    {
        if (!auth()->user()->can('view_invoices') && !auth()->user()->can('view_own_invoices')
            && config('app.allow_staff_view_invoices_assigned') == '0') {
            abort(403, 'Access denied');
        }

        closeSetupMenu();

        $data['invoiceid'] = $id;
        $data['title'] = __('invoices_list_recurring');
        $data['invoices_years'] = DB::table('invoices')->distinct()->get(['YEAR(date) as year']);
        $data['invoices_sale_agents'] = DB::table('invoices')->distinct()->get(['sale_agent']);

        return view('admin.invoices.recurring.list', $data);
    }

    public function table($clientId = '')
    {
        if (!auth()->user()->can('view_invoices') && !auth()->user()->can('view_own_invoices')
            && config('app.allow_staff_view_invoices_assigned') == '0') {
            abort(403, 'Access denied');
        }

        $paymentModes = DB::table('payment_modes')->get();
        $data['payment_modes'] = $paymentModes;

        $this->getTableData(($request->input('recurring') ? 'recurring_invoices' : 'invoices'), [
            'clientid' => $clientId,
            'data' => $data,
        ]);

        return response()->json($data);
    }

    public function clientChangeData($customerId, $currentInvoice = '')
    {
        if (request()->ajax()) {
            $projectsModel = new ProjectsModel();
            $data = [];
            $data['billing_shipping'] = DB::table('clients')->where('userid', $customerId)->first();
            $data['client_currency'] = DB::table('clients')->where('userid', $customerId)->value('currency');

            $data['customer_has_projects'] = $projectsModel->customerHasProjects($customerId);
            $data['billable_tasks'] = DB::table('tasks')->where('customer_id', $customerId)->where('billable', 1)->get();

            if ($currentInvoice != '') {
                $currentInvoiceStatus = DB::table('invoices')->where('id', $currentInvoice)->value('status');
            }

            $_data['invoices_to_merge'] = (!isset($currentInvoiceStatus) || (isset($currentInvoiceStatus) && $currentInvoiceStatus != InvoicesModel::STATUS_CANCELLED))
                ? $this->invoicesModel->checkForMergeInvoice($customerId, $currentInvoice)
                : [];

            $data['merge_info'] = view('admin.invoices.merge_invoice', $_data)->render();

            $currenciesModel = new CurrenciesModel();
            $__data['expenses_to_bill'] = (!isset($currentInvoiceStatus) || (isset($currentInvoiceStatus) && $currentInvoiceStatus != InvoicesModel::STATUS_CANCELLED))
                ? $this->invoicesModel->getExpensesToBill($customerId)
                : [];

            $data['expenses_bill_info'] = view('admin.invoices.bill_expenses', $__data)->render();

            return response()->json($data);
        }
    }

    public function updateNumberSettings($id)
    {
        $response = [
            'success' => false,
            'message' => '',
        ];

        if (auth()->user()->can('edit_invoices')) {
            $affectedRows = 0;

            DB::table('invoices')->where('id', $id)->update([
                'prefix' => request()->post('prefix'),
            ]);

            if (DB::table('invoices')->where('id', $id)->count() > 0) {
                $affectedRows++;
            }

            if ($affectedRows > 0) {
                $response['success'] = true;
                $response['message'] = __('updated_successfully', __('invoice'));
            }
        }

        return response()->json($response);
    }

    public function validateInvoiceNumber()
    {
        $isEdit = request()->post('isedit');
        $number = request()->post('number');
        $date = request()->post('date');
        $originalNumber = request()->post('original_number');
        $number = trim($number);
        $number = ltrim($number, '0');

        if ($isEdit == 'true') {
            if ($number == $originalNumber) {
                return response()->json(true);
            }
        }

        if (DB::table('invoices')
            ->whereYear('date', date('Y', strtotime(toSqlDate($date))))
            ->where('number', $number)
            ->where('status', '!=', InvoicesModel::STATUS_DRAFT)
            ->count() > 0) {
            return response()->json(false);
        } else {
            return response()->json(true);
        }
    }

    // ... (continue with other methods)

    private function getTableData($tableName, $params)
    {
        // Logic to get table data
    }
}
