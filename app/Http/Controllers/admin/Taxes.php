<?php

namespace App\Http\Controllers;

use App\Models\Tax;
use Illuminate\Http\Request;

class TaxesController extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->middleware('admin');
    }

    /* List all taxes */
    public function index()
    {
        if (request()->ajax()) {
            return $this->app->get_table_data('taxes');
        }
        $data['title'] = _l('taxes');
        return view('admin.taxes.manage', $data);
    }

    /* Add or edit tax / ajax */
    public function manage()
    {
        if (request()->post()) {
            $data = request()->post();
            if (empty($data['taxid'])) {
                $success = Tax::create($data);
                $message = '';
                if ($success == true) {
                    $message = _l('added_successfully', _l('tax'));
                }
                return response()->json([
                    'success' => $success,
                    'message' => $message,
                ]);
            } else {
                $success = Tax::where('id', $data['taxid'])->update($data);
                $message = '';
                if ($success == true) {
                    $message = _l('updated_successfully', _l('tax'));
                }
                return response()->json([
                    'success' => $success,
                    'message' => $message,
                ]);
            }
        }
    }

    /* Delete tax from database */
    public function delete($id)
    {
        if (!$id) {
            return redirect(admin_url('taxes'));
        }
        $response = Tax::deleteTax($id);
        if (is_array($response) && isset($response['referenced'])) {
            set_alert('warning', _l('is_referenced', _l('tax_lowercase')));
        } elseif ($response == true) {
            set_alert('success', _l('deleted', _l('tax')));
        } else {
            set_alert('warning', _l('problem_deleting', _l('tax_lowercase')));
        }
        return redirect(admin_url('taxes'));
    }

    public function taxNameExists()
    {
        if (request()->post()) {
            $tax_id = request()->post('taxid');
            if ($tax_id != '') {
                $currentTax = Tax::find($tax_id);
                if ($currentTax->name == request()->post('name')) {
                    return response()->json(true);
                }
            }

            $totalRows = Tax::where('name', request()->post('name'))->count();
            if ($totalRows > 0) {
                return response()->json(false);
            } else {
                return response()->json(true);
            }
        }
    }
}
