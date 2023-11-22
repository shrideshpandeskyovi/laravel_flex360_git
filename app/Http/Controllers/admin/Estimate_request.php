<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\EstimateRequest;
use App\Models\Lead;
use App\Models\Client;
use App\Models\Staff;
use App\Services\EstimateRequestService; // Adjust the namespace based on your service class

class EstimateRequestController extends Controller
{
    protected $estimateRequestService;

    public function __construct(EstimateRequestService $estimateRequestService)
    {
        $this->estimateRequestService = $estimateRequestService;
        $this->middleware('admin');
    }

    public function convert($estimateRequestId)
    {
        if (request()->post()) {
            $convertTo = request()->post('convert_to');
            $relId = request()->post('rel_id');
            $relType = request()->post('rel_type');

            if ($relId != '' && $relType != '') {
                if ($convertTo == 'estimate') {
                    if (!staff_can('create', 'estimates')) {
                        access_denied();
                    }
                    return redirect()->route("{$convertTo}s.create", ['customer_id' => $relId, 'estimate_request_id' => $estimateRequestId]);
                } else {
                    if (!staff_can('create', 'proposals')) {
                        access_denied();
                    }
                    return redirect()->route("{$convertTo}s.create", ['rel_id' => $relId, 'rel_type' => $relType, 'estimate_request_id' => $estimateRequestId]);
                }
            }

            if (!staff_can('create', 'customers')) {
                access_denied();
            }

            $defaultCountry = get_option('customer_default_country');
            $data = request()->post();
            $data['password'] = request()->post('password', false);

            if ($data['country'] == '' && $defaultCountry != '') {
                $data['country'] = $defaultCountry;
            }

            $data['billing_street'] = $data['address'];
            $data['billing_city'] = $data['city'];
            $data['billing_state'] = $data['state'];
            $data['billing_zip'] = $data['zip'];
            $data['billing_country'] = $data['country'];

            $data['is_primary'] = 1;

            unset($data['requestid'], $data['convert_to'], $data['rel_type'], $data['rel_id']);

            $id = $this->clients_model->add($data, true);

            if ($id) {
                if (!staff_can('view', 'customers')) {
                    $this->db->insert(db_prefix() . 'customer_admins', [
                        'date_assigned' => now(),
                        'customer_id' => $id,
                        'staff_id' => get_staff_user_id(),
                    ]);
                }

                set_alert('success', _l('estimate_request_client_created_success'));

                return redirect()->route(
                    $convertTo == 'estimate' ?
                    "{$convertTo}s.create" : "{$convertTo}s.create",
                    [
                        'customer_id' => $id,
                        'estimate_request_id' => $estimateRequestId,
                    ]
                );
            }
        }
    }

    public function updateAssignedStaff()
    {
        if (request()->post() && request()->ajax()) {
            if (!staff_can('edit', 'estimate_request')) {
                ajax_access_denied();
            }

            if ($this->estimateRequestService->updateRequestAssigned(request()->post())) {
                return response()->json([
                    'success' => true,
                    'message' => _l('estimate_request_updated'),
                ]);
            }

            return response()->json(['success' => false]);
        }
    }

    public function updateTags($estimateRequestId)
    {
        if (request()->post() && request()->ajax()) {
            if (!staff_can('edit', 'estimate_request')) {
                ajax_access_denied();
            }

            $tags = request()->post('tags');
            if ($this->handleTagsSave($tags, $estimateRequestId, 'estimate_request')) {
                return response()->json([
                    'success' => true,
                    'message' => _l('estmate_request_tags_updated'),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => _l('something_went_wrong'),
            ]);
        }
    }

    public function updateRequestStatus()
    {
        if (request()->post() && request()->ajax()) {
            if (!staff_can('edit', 'estimate_request')) {
                ajax_access_denied();
            }

            if ($this->estimateRequestService->updateRequestStatus(request()->post())) {
                return response()->json([
                    'success' => true,
                    'message' => _l('estimate_request_updated'),
                    'status_name' => $this->estimateRequestService->getStatus(request()->post('status'))->name,
                ]);
            }

            return response()->json(['success' => false]);
        }
    }

    public function view($id)
    {
        if (!staff_can('view', 'estimate_request')
            && !staff_can('view_own', 'estimate_request')) {
            access_denied('Estimate Request');
        }
        $data['estimate_request'] = EstimateRequest::find($id);

        if (!$data['estimate_request']) {
            abort(404);
        }

        if (!empty($data['estimate_request']->email)) {
            $data['lead'] = Lead::where('email', $data['estimate_request']->email)->first();
            $data['contact'] = Client::where('email', $data['estimate_request']->email)->first();
        }

        $data['statuses'] = $this->estimateRequestService->getStatus();
        $data['members'] = Staff::where(['active' => 1, 'is_not_staff' => 0])->get();
        $data['title'] = _l('estimate_request');
        return view('admin.estimate_request.estimate_request', $data);
    }

    public function delete($id)
    {
        if (!$id) {
            return redirect()->route('estimate_request.index');
        }

        if (!staff_can('delete', 'estimate_request')) {
            access_denied('Delete Lead');
        }

        $response = $this->estimateRequestService->delete($id);
        if (is_array($response) && isset($response['referenced'])) {
            set_alert('warning', _l('is_referenced', _l('estimate_request_lowercase')));
        } elseif ($response === true) {
            set_alert('success', _l('deleted', _l('lead')));
        } else {
            set_alert('warning', _l('problem_deleting', _l('lead_lowercase')));
        }

        return redirect()->route('estimate_request.index');
    }

    public function table()
    {
        if (!staff_can('view', 'estimate_request')
            && !staff_can('view_own', 'estimate_request')) {
            ajax_access_denied();
        }
        return $this->app->get_table_data('estimate_request');
    }

    // Statuses
    public function statuses()
    {
        if (!is_admin()) {
            access_denied('Estimate Request Statuses');
        }
        $data['statuses'] = $this->estimateRequestService->getStatus();
        $data['title'] = 'Estimate Request statuses';
        return view('admin.estimate_request.manage_statuses', $data);
    }

    public function status()
    {
        if (!is_admin()) {
            access_denied('Estimate Request Statuses');
        }
        if (request()->post()) {
            $data = request()->post();
            if (!request()->post('id')) {
                $inline = isset($data['inline']);
                if (isset($data['inline'])) {
                    unset($data['inline']);
                }
                $id = $this->estimateRequestService->addStatus($data);
                if (!$inline) {
                    if ($id) {
                        set_alert('success', _l('added_successfully', _l('estimate_request_status')));
                    }
                } else {
                    return response()->json(['success' => $id ? true : false, 'id' => $id]);
                }
            } else {
                $id = $data['id'];
                unset($data['id']);
                $success = $this->estimateRequestService->updateStatus($data, $id);
                if ($success) {
                    set_alert('success', _l('updated_successfully', _l('estimate_request_status')));
                }
            }
        }
    }
    
    public function __construct(EstimateRequestService $estimateRequestService)
    {
        $this->estimateRequestService = $estimateRequestService;
        $this->middleware('admin');
    }

    public function deleteStatus($id)
    {
        if (!is_admin()) {
            access_denied('Estimate Request Statuses');
        }
        if (!$id) {
            return redirect()->route('estimate_request.statuses');
        }

        $response = $this->estimateRequestService->deleteStatus($id);
        if (is_array($response) && isset($response['referenced'])) {
            set_alert('warning', _l('is_referenced', _l('estimate_request_status_lowercase')));
        } elseif (is_array($response) && isset($response['flag'])) {
            set_alert('warning', _l('not_delete_estimate_request_default_status'));
        } elseif ($response == true) {
            set_alert('success', _l('deleted', _l('estimate_request_status')));
        } else {
            set_alert('warning', _l('problem_deleting', _l('estimate_request_status_lowercase')));
        }
        return redirect()->route('estimate_request.statuses');
    }

    public function index()
    {
        close_setup_menu();
        if (!staff_can('view', 'estimate_request') && !staff_can('view_own', 'estimate_request')) {
            access_denied('Estimate Request');
        }

        $data['staff'] = Staff::where('active', 1)->get();
        $data['title'] = _l('estimate_requests');
        return view('admin.estimate_request.manage_request', $data);
    }

    public function saveFormData()
    {
        if (!is_admin()) {
            ajax_access_denied();
        }

        $data = request()->input();

        // form data should be always sent to the request and never should be empty
        // this code is added to prevent losing the old form in case any errors
        if (!isset($data['formData']) || isset($data['formData']) && !$data['formData']) {
            return response()->json(['success' => false]);
        }

        // If user paste with styling eq from some editor word and the Codeigniter XSS feature remove and apply xss=remove, may break the json.
        $data['formData'] = preg_replace('/=\\\\/m', "=''", $data['formData']);

        $_formData = json_decode($data['formData']);
        $emailField = null;

        foreach ($_formData as $field) {
            if (isset($field->subtype) && $field->subtype === 'email') {
                $emailField = $field;
                break;
            }
        }

        if (!$emailField) {
            return response()->json([
                'success' => false,
                'message' => _l('estimate_request_form_email_field_is_required'),
            ]);
        }

        if (!isset($emailField->required) || !$emailField->required) {
            return response()->json([
                'success' => false,
                'message' => _l('estimate_request_form_email_field_set_to_required'),
            ]);
        }

        EstimateRequest::where('id', $data['id'])->update([
            'form_data' => $data['formData'],
        ]);

        if (EstimateRequest::where('id', $data['id'])->count() > 0) {
            $response = [
                'success' => true,
                'message' => _l('updated_successfully', _l('estimate_request_form')),
            ];
        } else {
            $response = ['success' => false];
        }
        return response()->json($response);
    }

    public function form($id = '')
    {
        if (!is_admin()) {
            access_denied('Estimate Request Form Access');
        }

        $roles = Roles::all();

        if (request()->post()) {
            if ($id == '') {
                $data = request()->input();
                $id = $this->estimateRequestService->addForm($data);
                if ($id) {
                    set_alert('success', _l('added_successfully', _l('estimate_request_form')));
                    return redirect()->route('estimate_request.form', $id);
                }
            } else {
                $success = $this->estimateRequestService->updateForm($id, request()->input());
                if ($success) {
                    set_alert('success', _l('updated_successfully', _l('estimate_request_form')));
                }
                return redirect()->route('estimate_request.form', $id);
            }
        }

        $formData = [];
        $title = _l('estimate_request_form');
        $statuses = $this->estimateRequestService->getStatus();

        if ($id != '') {
            $form = $this->estimateRequestService->getForm(['id' => $id]);
            $title = $form->name . ' - ' . _l('estimate_request_form');
            $formData = $form->form_data;
        }

        $members = Staff::where('active', 1)->where('is_not_staff', 0)->get();
        $languages = $this->app->get_available_languages();

        $predefinedFields = [];

        $fields = [
            'email' => 'Email',
        ];

        $className = 'form-control';

        foreach ($fields as $field => $label) {
            $_field_object = new \stdClass();
            $type = 'text';
            $subtype = '';

            if ($field == 'email') {
                $subtype = 'email';
            }

            $field_array = [
                'subtype' => $subtype,
                'type' => $type,
                'label' => $label,
                'className' => $className,
                'name' => $field,
            ];

            if ($field == 'email') {
                $field_array['required'] = true;
            }

            $_field_object->label = $label;
            $_field_object->name = $field;
            $_field_object->fields = [];
            $_field_object->fields[] = $field_array;
            $predefinedFields[] = $_field_object;
        }

        $bodyclass = 'estimate-request-form';

        return view('admin.estimate_request.formbuilder', compact('formData', 'title', 'statuses', 'form', 'members', 'roles', 'languages', 'predefinedFields', 'bodyclass'));
    }

    public function forms($id = '')
    {
        if (!is_admin()) {
            access_denied('Estimate Request Access');
        }

        if (request()->ajax()) {
            return $this->app->get_table_data('estimate_request_form');
        }

        $title = _l('estimate_request_forms');
        return view('admin.estimate_request.forms', compact('title'));
    }

    public function deleteForm($id)
    {
        if (!is_admin()) {
            access_denied('Estimate Request Access');
        }

        $success = $this->estimateRequestService->deleteForm($id);
        if ($success) {
            set_alert('success', _l('deleted', _l('estimate_request')));
        }

        return redirect()->route('estimate_request.forms');
    }
}
