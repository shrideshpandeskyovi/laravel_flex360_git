<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\View;

class CustomFieldsController extends Controller
{
    private $pdfFields = [];
    private $clientPortalFields = [];
    private $clientEditableFields = [];

    public function __construct()
    {
        parent::__construct();
        $this->load->model('custom_fields_model');
        if (!is_admin()) {
            access_denied('Access Custom Fields');
        }
        // Add the pdf allowed fields
        $this->pdfFields = $this->custom_fields_model->get_pdf_allowed_fields();
        $this->clientPortalFields = $this->custom_fields_model->get_client_portal_allowed_fields();
        $this->clientEditableFields = $this->custom_fields_model->get_client_editable_fields();
    }

    /* List all custom fields */
    public function index()
    {
        if (request()->ajax()) {
            $this->app->get_table_data('custom_fields');
        }
        $data['title'] = _l('custom_fields');
        return view('admin.custom_fields.manage', $data);
    }

    public function field($id = '')
    {
        if (request()->post()) {
            if ($id == '') {
                $id = $this->custom_fields_model->add(request()->post());
                set_alert('success', _l('added_successfully', _l('custom_field')));
                return response()->json(['id' => $id]);
            }
            $success = $this->custom_fields_model->update(request()->post(), $id);
            if (is_array($success) && isset($success['cant_change_option_custom_field'])) {
                set_alert('warning', _l('cf_option_in_use'));
            } elseif ($success === true) {
                set_alert('success', _l('updated_successfully', _l('custom_field')));
            }
            return response()->json(['id' => $id]);
        }

        if ($id == '') {
            $title = _l('add_new', _l('custom_field_lowercase'));
        } else {
            $data['custom_field'] = $this->custom_fields_model->get($id);
            $title = _l('edit', _l('custom_field_lowercase'));
        }

        $data['pdf_fields'] = $this->pdfFields;
        $data['client_portal_fields'] = $this->clientPortalFields;
        $data['client_editable_fields'] = $this->clientEditableFields;
        $data['title'] = $title;
        return view('admin.custom_fields.customfield', $data);
    }

    /* Delete announcement from the database */
    public function delete($id)
    {
        if (!$id) {
            return redirect(admin_url('custom_fields'));
        }
        $response = $this->custom_fields_model->delete($id);
        if ($response == true) {
            set_alert('success', _l('deleted', _l('custom_field')));
        } else {
            set_alert('warning', _l('problem_deleting', _l('custom_field_lowercase')));
        }
        return redirect(admin_url('custom_fields'));
    }

    /* Change custom field status active or inactive */
    public function change_custom_field_status($id, $status)
    {
        if (request()->ajax()) {
            $this->custom_fields_model->change_custom_field_status($id, $status);
        }
    }

    public function validate_default_date()
    {
        $date = strtotime(request()->post('date'));
        $type = request()->post('type');

        return response()->json([
            'valid' => $date !== false,
            'sample' => $date ? $type == 'date_picker' ? _d(date('Y-m-d', $date)) : _dt(date('Y-m-d H:i', $date)) : null,
        ]);
    }
}
