<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\View;

class CurrenciesController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('currencies_model');
        if (!is_admin()) {
            access_denied('Currencies');
        }
    }

    /* List all currencies */
    public function index()
    {
        if (request()->ajax()) {
            $this->app->get_table_data('currencies');
        }
        $data['title'] = _l('currencies');
        return view('admin.currencies.manage', $data);
    }

    /* Update currency or add new / ajax */
    public function manage()
    {
        if (request()->post()) {
            $data = request()->post();
            if ($data['currencyid'] == '') {
                $success = $this->currencies_model->add($data);
                $message = '';
                if ($success == true) {
                    $message = _l('added_successfully', _l('currency'));
                }
                return response()->json([
                    'success' => $success,
                    'message' => $message,
                ]);
            } else {
                $success = $this->currencies_model->edit($data);
                $message = '';
                if ($success == true) {
                    $message = _l('updated_successfully', _l('currency'));
                }
                return response()->json([
                    'success' => $success,
                    'message' => $message,
                ]);
            }
        }
    }

    /* Make currency your base currency */
    public function make_base_currency($id)
    {
        if (!$id) {
            return redirect(admin_url('currencies'));
        }
        $response = $this->currencies_model->make_base_currency($id);
        if (is_array($response) && isset($response['has_transactions_currency'])) {
            set_alert('danger', _l('has_transactions_currency_base_change'));
        } elseif ($response == true) {
            set_alert('success', _l('base_currency_set'));
        }
        return redirect(admin_url('currencies'));
    }

    /* Delete currency from database */
    public function delete($id)
    {
        if (!$id) {
            return redirect(admin_url('currencies'));
        }
        $response = $this->currencies_model->delete($id);
        if (is_array($response) && isset($response['referenced'])) {
            set_alert('warning', _l('is_referenced', _l('currency_lowercase')));
        } elseif (is_array($response) && isset($response['is_default'])) {
            set_alert('warning', _l('cant_delete_base_currency'));
        } elseif ($response == true) {
            set_alert('success', _l('deleted', _l('currency')));
        } else {
            set_alert('warning', _l('problem_deleting', _l('currency_lowercase')));
        }
        return redirect(admin_url('currencies'));
    }

    /* Get symbol by currency id passed */
    public function get_currency_symbol($id)
    {
        if (request()->ajax()) {
            return response()->json([
                'symbol' => $this->currencies_model->get_currency_symbol($id),
            ]);
        }
    }
}
