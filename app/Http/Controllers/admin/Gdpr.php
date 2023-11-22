<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Gdpr; // Adjust the namespace and model as per your Laravel project structure

class GdprController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth'); // Add any middleware if needed
        $notAdminAllowed = ['lead_consent_opt_action', 'contact_consent_opt_action'];
        if (!auth()->user()->isAdmin() && !in_array(request()->segment(3), $notAdminAllowed)) {
            abort(403, 'Unauthorized action.');
        }
        $this->gdprModel = new Gdpr; // Adjust the model name
    }

    public function index()
    {
        $page = request()->input('page', 'general');
        $data['page'] = $page;
        $data['save'] = true;
        if ($page == 'forgotten') {
            $data['requests'] = $this->gdprModel->getRemovalRequests();
            $data['not_pending_requests'] = $this->gdprModel->totalRows('gdpr_requests', ['status' => 'pending']);
        } elseif ($page == 'consent') {
            $data['consent_purposes'] = $this->gdprModel->getConsentPurposes();
        }
        $data['title'] = __('gdpr');
        return view('admin.gdpr.index', $data);
    }

    public function save()
    {
        $page = request()->input('page', 'general');
        $settings = request()->input('settings');

        $noXSS = ['terms_and_conditions', 'privacy_policy', 'gdpr_consent_public_page_top_block', 'gdpr_page_top_information_block'];

        if ($page == 'portability') {
            $settings['gdpr_lead_data_portability_allowed'] = isset($settings['gdpr_lead_data_portability_allowed'])
                ? serialize($settings['gdpr_lead_data_portability_allowed'])
                : serialize([]);

            $settings['gdpr_contact_data_portability_allowed'] = isset($settings['gdpr_contact_data_portability_allowed'])
                ? serialize($settings['gdpr_contact_data_portability_allowed'])
                : serialize([]);
        }

        foreach ($settings as $name => $val) {
            if (in_array($name, $noXSS)) {
                $val = clean($settings[$name]);
            }
            update_option($name, $val);
        }

        return redirect(route('gdpr.index', ['page' => $page]));
    }

    public function change_removal_request_status($id, $status)
    {
        $this->gdprModel->update($id, ['status' => $status]);
    }

    public function consent_purpose($id = null)
    {
        if (request()->isMethod('post')) {
            $data = request()->input();

            $data['description'] = nl2br($data['description']);

            if (!$id) {
                $this->gdprModel->addConsentPurpose(['name' => $data['name'], 'description' => $data['description']]);
            } else {
                $update = ['description' => $data['description']];
                if (isset($data['name'])) {
                    $update['name'] = $data['name'];
                }
                $this->gdprModel->updateConsentPurpose($id, $update);
            }

            return redirect(route('gdpr.index', ['page' => 'consent']));
        }

        $data = [];
        if (!is_null($id)) {
            $data['purpose'] = $this->gdprModel->getConsentPurpose($id);
        }

        return view('admin.gdpr.pages.includes.consent', $data);
    }

    public function delete_consent_purpose($id)
    {
        $this->gdprModel->deleteConsentPurpose($id);
        return redirect(route('gdpr.index', ['page' => 'consent']));
    }

    public function enable()
    {
        update_option('enable_gdpr', 1);
        return redirect(route('gdpr.index'));
    }

    public function contact_consent_opt_action()
    {
        if (request()->isMethod('post')) {
            $data = request()->input();
            $contactId = $data['contact_id'];
            $clientId = get_user_id_by_contact_id($contactId);

            if (!auth()->user()->can('view', 'customers') && !is_customer_admin($clientId)) {
                abort(403, 'Unauthorized action.');
            }

            $data = $this->prepareConsentOptActionData($data);
            $data['contact_id'] = $contactId;
            $this->gdprModel->addConsent($data);

            if (str_contains(url()->previous(), 'all_contacts')) {
                return redirect(route('clients.all_contacts', ['consents' => $contactId]));
            } else {
                return redirect(route('clients.client', ['id' => $clientId, 'group' => 'contacts', 'consents' => $contactId]));
            }
        }
    }

    public function lead_consent_opt_action()
    {
        if (request()->isMethod('post')) {
            $data = request()->input();
            $leadId = $data['lead_id'];

            $this->load->model('leads_model');
            if (!auth()->user()->isStaffMember() || !$this->leads_model->staff_can_access_lead($leadId)) {
                abort(403, 'Unauthorized action.');
            }

            $data = $this->prepareConsentOptActionData($data);
            $data['lead_id'] = $leadId;
            $this->gdprModel->addConsent($data);

            return response()->json(['lead_id' => $leadId]);
        }
    }

    private function prepareConsentOptActionData($data)
    {
        return [
            'action' => $data['action'],
            'purpose_id' => $data['purpose_id'],
            'description' => nl2br($data['description']),
            'opt_in_purpose_description' => isset($data['opt_in_purpose_description']) ? nl2br($data['opt_in_purpose_description']) : '',
            'staff_name' => auth()->user()->getFullName(),
        ];
    }
}
