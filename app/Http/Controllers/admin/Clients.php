<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Contracts;
use App\Models\Proposals;
use App\Models\Invoices;
use App\Models\Estimates;
use App\Models\Projects;
use App\Models\ClientsModel;


class ClientsController extends AdminController
{
    protected $contractsModel;
    protected $proposalsModel;
    protected $invoicesModel;
    protected $estimatesModel;
    protected $projectsModel;
    protected $clientsModel;
    protected $clientsModel;
    protected $estimatesModel;
    protected $invoicesModel;
    protected $creditNotesModel;
    protected $paymentModesModel;
    protected $projectsModel;
    protected $miscModel;
    protected $staffModel;
    protected $currenciesModel;
    protected $gdprModel;
    protected $proposalsModel;
    protected $clientsModel;
    protected $gdprModel;
    protected $proposalsModel;
    protected $gdprModel;
    protected $proposalsModel;
  


    public function __construct(
        Contracts $contractsModel,
        Proposals $proposalsModel,
        Invoices $invoicesModel,
        Estimates $estimatesModel,
        Projects $projectsModel,
        ClientsModel $clientsModel,
        ClientsModel $clientsModel,
        EstimatesModel $estimatesModel,
        InvoicesModel $invoicesModel,
        CreditNotesModel $creditNotesModel,
        PaymentModesModel $paymentModesModel,
        ProjectsModel $projectsModel,
        MiscModel $miscModel,
        StaffModel $staffModel,
        CurrenciesModel $currenciesModel,
        GDPRModel $gdprModel,
        ProposalsModel $proposalsModel,
        ClientsModel $clientsModel,
        GDPRModel $gdprModel,
        ProposalsModel $proposalsModel,
        ClientsModel $clientsModel,
     
    ) {
        $this->contractsModel = $contractsModel;
        $this->proposalsModel = $proposalsModel;
        $this->invoicesModel = $invoicesModel;
        $this->estimatesModel = $estimatesModel;
        $this->projectsModel = $projectsModel;
        $this->clientsModel = $clientsModel;
        $this->clientsModel = $clientsModel;
        $this->estimatesModel = $estimatesModel;
        $this->invoicesModel = $invoicesModel;
        $this->creditNotesModel = $creditNotesModel;
        $this->paymentModesModel = $paymentModesModel;
        $this->projectsModel = $projectsModel;
        $this->miscModel = $miscModel;
        $this->staffModel = $staffModel;
        $this->currenciesModel = $currenciesModel;
        $this->gdprModel = $gdprModel;
        $this->proposalsModel = $proposalsModel;
        $this->clientsModel = $clientsModel;
        $this->gdprModel = $gdprModel;
        $this->proposalsModel = $proposalsModel;
        $this->clientsModel = $clientsModel;
       
    }

    public function index()
    {
        if (!$this->has_permission('customers', '', 'view')) {
            if (!$this->have_assigned_customers() && !$this->has_permission('customers', '', 'create')) {
                $this->access_denied('customers');
            }
        }

        $data['contract_types'] = $this->contractsModel->get_contract_types();
        $data['groups'] = $this->clientsModel->get_groups();
        $data['title'] = __('clients');

        $data['proposal_statuses'] = $this->proposalsModel->get_statuses();

        $data['invoice_statuses'] = $this->invoicesModel->get_statuses();

        $data['estimate_statuses'] = $this->estimatesModel->get_statuses();

        $data['project_statuses'] = $this->projectsModel->get_project_statuses();

        $data['customer_admins'] = $this->clientsModel->get_customers_admin_unique_ids();

        $whereContactsLoggedIn = '';
        if (!$this->has_permission('customers', '', 'view')) {
            $whereContactsLoggedIn = ' AND userid IN (SELECT customer_id FROM customer_admins WHERE staff_id=' . $this->get_staff_user_id() . ')';
        }

        $data['contacts_logged_in_today'] = $this->clientsModel->get_contacts('', 'last_login LIKE "' . now()->format('Y-m-d') . '%"' . $whereContactsLoggedIn);

        $data['countries'] = $this->clientsModel->get_clients_distinct_countries();

        return view('admin.clients.manage', $data);
    }

    public function table()
    {
        if (!$this->has_permission('customers', '', 'view')) {
            if (!$this->have_assigned_customers() && !$this->has_permission('customers', '', 'create')) {
                $this->ajax_access_denied();
            }
        }

        $this->app->get_table_data('clients');
    }

    public function all_contacts()
    {
        if ($this->request->ajax()) {
            $this->app->get_table_data('all_contacts');
        }

        if ($this->is_gdpr() && get_option('gdpr_enable_consent_for_contacts') == '1') {
            $data['consent_purposes'] = $this->gdprModel->get_consent_purposes();
        }

        $data['title'] = _l('customer_contacts');
        return view('admin.clients.all_contacts', $data);
    }

    public function client($id = '')
    {
        if (!$this->has_permission('customers', '', 'view')) {
            if ($id != '' && !$this->is_customer_admin($id)) {
                $this->access_denied('customers');
            }
        }

        if ($this->request->post() && !$this->request->ajax()) {
            if ($id == '') {
                if (!$this->has_permission('customers', '', 'create')) {
                    $this->access_denied('customers');
                }

                $data = $this->request->post();

                $save_and_add_contact = false;
                if (isset($data['save_and_add_contact'])) {
                    unset($data['save_and_add_contact']);
                    $save_and_add_contact = true;
                }
                $id = $this->clientsModel->add($data);
                if (!$this->has_permission('customers', '', 'view')) {
                    $assign['customer_admins'] = [];
                    $assign['customer_admins'][] = $this->get_staff_user_id();
                    $this->clientsModel->assign_admins($assign, $id);
                }
                if ($id) {
                    set_alert('success', _l('added_successfully', _l('client')));
                    if ($save_and_add_contact == false) {
                        redirect(admin_url('clients/client/' . $id));
                    } else {
                        redirect(admin_url('clients/client/' . $id . '?group=contacts&new_contact=true'));
                    }
                }
            } else {
                if (!$this->has_permission('customers', '', 'edit')) {
                    if (!$this->is_customer_admin($id)) {
                        $this->access_denied('customers');
                    }
                }
                $success = $this->clientsModel->update($this->request->post(), $id);
                if ($success == true) {
                    set_alert('success', _l('updated_successfully', _l('client')));
                }
                redirect(admin_url('clients/client/' . $id));
            }
        }

        $group = !$this->request->get('group') ? 'profile' : $this->request->get('group');
        $data['group'] = $group;

        if ($group != 'contacts' && $contact_id = $this->request->get('contactid')) {
            redirect(admin_url('clients/client/' . $id . '?group=contacts&contactid=' . $contact_id));
        }

        $data['groups'] = $this->clientsModel->get_groups();

        if ($id == '') {
            $title = _l('add_new', _l('client_lowercase'));
        } else {
            $client = $this->clientsModel->get($id);
            $data['customer_tabs'] = get_customer_profile_tabs($id);

            if (!$client) {
                show_404();
            }

            $data['contacts'] = $this->clientsModel->get_contacts($id);
            $data['tab'] = isset($data['customer_tabs'][$group]) ? $data['customer_tabs'][$group] : null;

            if (!$data['tab']) {
                show_404();
            }

            if ($group == 'profile') {
                $data['customer_groups'] = $this->clientsModel->get_customer_groups($id);
                $data['customer_admins'] = $this->clientsModel->get_admins($id);
            } elseif ($group == 'attachments') {
                $data['attachments'] = get_all_customer_attachments($id);
            } elseif ($group == 'vault') {
                $data['vault_entries'] = hooks()->apply_filters('check_vault_entries_visibility', $this->clientsModel->get_vault_entries($id));

                if ($data['vault_entries'] === -1) {
                    $data['vault_entries'] = [];
                }
            } elseif ($group == 'estimates') {
                $data['estimate_statuses'] = $this->estimatesModel->get_statuses();
            } elseif ($group == 'invoices') {
                $data['invoice_statuses'] = $this->invoicesModel->get_statuses();
            } elseif ($group == 'credit_notes') {
                $data['credit_notes_statuses'] = $this->creditNotesModel->get_statuses();
                $data['credits_available'] = $this->creditNotesModel->total_remaining_credits_by_customer($id);
            } elseif ($group == 'payments') {
                $data['payment_modes'] = $this->paymentModesModel->get();
            } elseif ($group == 'notes') {
                $data['user_notes'] = $this->miscModel->get_notes($id, 'customer');
            } elseif ($group == 'projects') {
                $data['project_statuses'] = $this->projectsModel->get_project_statuses();
            } elseif ($group == 'statement') {
                if (!$this->has_permission('invoices', '', 'view') && !$this->has_permission('payments', '', 'view')) {
                    set_alert('danger', _l('access_denied'));
                    redirect(admin_url('clients/client/' . $id));
                }

                $data = array_merge($data, prepare_mail_preview_data('customer_statement', $id));
            } elseif ($group == 'map') {
                if (get_option('google_api_key') != '' && !empty($client->latitude) && !empty($client->longitude)) {
                    $this->appScripts->add('map-js', asset($this->appScripts->core_file('assets/js', 'map.js')) . '?v=' . $this->appCss->core_version());

                    $this->appScripts->add('google-maps-api-js', [
                        'path' => 'https://maps.googleapis.com/maps/api/js?key=' . get_option('google_api_key') . '&callback=initMap',
                        'attributes' => [
                            'async',
                            'defer',
                            'latitude' => "$client->latitude",
                            'longitude' => "$client->longitude",
                            'mapMarkerTitle' => "$client->company",
                        ],
                    ]);
                }
            }

            $data['staff'] = $this->staffModel->get('', ['active' => 1]);

            $data['client'] = $client;
            $title = $client->company;

            if (!empty($data['client']->company)) {
                if (is_empty_customer_company($data['client']->userid)) {
                    $data['client']->company = '';
                }
            }
        }

        $data['currencies'] = $this->currenciesModel->get();

        if ($id != '') {
            $customer_currency = $data['client']->default_currency;

            foreach ($data['currencies'] as $currency) {
                if ($customer_currency != 0) {
                    if ($currency['id'] == $customer_currency) {
                        $customer_currency = $currency;
                        break;
                    }
                } else {
                    if ($currency['isdefault'] == 1) {
                        $customer_currency = $currency;
                        break;
                    }
                }
            }

            if (is_array($customer_currency)) {
                $customer_currency = (object) $customer_currency;
            }

            $data['customer_currency'] = $customer_currency;

            $slug_zip_folder = (
                $client->company != ''
                ? $client->company
                : get_contact_full_name(get_primary_contact_user_id($client->userid))
            );

            $data['zip_in_folder'] = slug_it($slug_zip_folder);
        }

        $data['bodyclass'] = 'customer-profile dynamic-create-groups';
        $data['title'] = $title;

        return view('admin.clients.client', $data);
    }

    public function export($contact_id)
    {
        if (is_admin()) {
            $gdprContact = new \App\Libraries\GDPR\GDPRContact();
            $gdprContact->export($contact_id);
        }
    }

    public function checkDuplicateCustomerName(Request $request)
    {
        if ($request->has_permission('customers', '', 'create')) {
            $companyName = trim($request->input('company'));
            $response = [
                'exists' => (bool) $this->clientsModel->totalRows(['company' => $companyName]),
                'message' => _l('company_exists_info', '<b>' . $companyName . '</b>'),
            ];
            return response()->json($response);
        }
    }

    public function saveLongitudeAndLatitude(Request $request, $client_id)
    {
        if (!$request->has_permission('customers', '', 'edit')) {
            if (!$request->is_customer_admin($client_id)) {
                $this->ajaxAccessDenied();
            }
        }

        $this->clientsModel->updateClientCoordinates($client_id, $request->input('longitude'), $request->input('latitude'));

        return $this->db->affectedRows() > 0 ? 'success' : 'false';
    }

    public function formContact(Request $request, $customer_id, $contact_id = '')
    {
        if (!$request->has_permission('customers', '', 'view')) {
            if (!$request->is_customer_admin($customer_id)) {
                return response()->json([
                    'success' => false,
                    'message' => _l('access_denied'),
                ], 400);
            }
        }

        $data['customer_id'] = $customer_id;
        $data['contactid'] = $contact_id;

        if (is_automatic_calling_codes_enabled()) {
            $clientCountryId = $this->clientsModel->getClientCountryId($customer_id);
            $clientCountry = get_country($clientCountryId);
            $callingCode = $clientCountry ? '+' . ltrim($clientCountry->calling_code, '+') : null;
        } else {
            $callingCode = null;
        }

        if ($request->isMethod('post')) {
            $data = $request->all();
            $data['password'] = $request->input('password', false);

            if ($callingCode && !empty($data['phonenumber']) && $data['phonenumber'] == $callingCode) {
                $data['phonenumber'] = '';
            }

            unset($data['contactid']);

            if ($contact_id == '') {
                if (!$request->has_permission('customers', '', 'create')) {
                    if (!$request->is_customer_admin($customer_id)) {
                        return response()->json([
                            'success' => false,
                            'message' => _l('access_denied'),
                        ], 400);
                    }
                }

                $id = $this->clientsModel->addContact($data, $customer_id);
                $message = '';
                $success = false;

                if ($id) {
                    handle_contact_profile_image_upload($id);
                    $success = true;
                    $message = _l('added_successfully', _l('contact'));
                }

                return response()->json([
                    'success' => $success,
                    'message' => $message,
                    'has_primary_contact' => $this->clientsModel->hasPrimaryContact($customer_id),
                    'is_individual' => $this->clientsModel->isIndividualCustomer($customer_id),
                ]);
            }

            if (!$request->has_permission('customers', '', 'edit')) {
                if (!$request->is_customer_admin($customer_id)) {
                    return response()->json([
                        'success' => false,
                        'message' => _l('access_denied'),
                    ], 400);
                }
            }

            $original_contact = $this->clientsModel->getContact($contact_id);
            $success = $this->clientsModel->updateContact($data, $contact_id);
            $message = '';
            $proposal_warning = false;
            $original_email = '';
            $updated = false;

            if (is_array($success)) {
                if (isset($success['set_password_email_sent'])) {
                    $message = _l('set_password_email_sent_to_client');
                } elseif (isset($success['set_password_email_sent_and_profile_updated'])) {
                    $updated = true;
                    $message = _l('set_password_email_sent_to_client_and_profile_updated');
                }
            } else {
                if ($success == true) {
                    $updated = true;
                    $message = _l('updated_successfully', _l('contact'));
                }
            }

            if (handle_contact_profile_image_upload($contact_id) && !$updated) {
                $message = _l('updated_successfully', _l('contact'));
                $success = true;
            }

            if ($updated == true) {
                $contact = $this->clientsModel->getContact($contact_id);

                if (
                    $this->clientsModel->hasProposalsWithEmail($contact->userid, $original_contact->email) > 0 &&
                    ($original_contact->email != $contact->email)
                ) {
                    $proposal_warning = true;
                    $original_email = $original_contact->email;
                }
            }

            return response()->json([
                'success' => $success,
                'proposal_warning' => $proposal_warning,
                'message' => $message,
                'original_email' => $original_email,
                'has_primary_contact' => $this->clientsModel->hasPrimaryContact($customer_id),
            ]);
        }

        $data['calling_code'] = $callingCode;

        if ($contact_id == '') {
            $title = _l('add_new', _l('contact_lowercase'));
        } else {
            $data['contact'] = $this->clientsModel->getContact($contact_id);

            if (!$data['contact']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contact Not Found',
                ], 400);
            }

            $title = $data['contact']->firstname . ' ' . $data['contact']->lastname;
        }

        $data['customer_permissions'] = get_contact_permissions();
        $data['title'] = $title;

        return view('admin.clients.modals.contact', $data);
    }
    public function confirmRegistration($client_id)
    {
        if (!is_admin()) {
            access_denied('Customer Confirm Registration, ID: ' . $client_id);
        }

        $this->clientsModel->confirmRegistration($client_id);
        set_alert('success', _l('customer_registration_successfully_confirmed'));
        return redirect()->back();
    }

    public function updateFileShareVisibility(Request $request)
    {
        if ($request->isMethod('post')) {
            $file_id = $request->input('file_id');
            $share_contacts_id = $request->input('share_contacts_id', []);

            $this->clientsModel->deleteSharedCustomerFiles($file_id);

            foreach ($share_contacts_id as $share_contact_id) {
                $this->clientsModel->insertSharedCustomerFile($file_id, $share_contact_id);
            }
        }
    }

    public function deleteContactProfileImage($contact_id)
    {
        $this->clientsModel->deleteContactProfileImage($contact_id);
    }

    public function markAsActive($id)
    {
        $this->clientsModel->markAsActive($id);
        return redirect()->route('clients.client', $id);
    }

    public function consents($id)
    {
        if (!$request->has_permission('customers', '', 'view')) {
            if (!$request->isCustomerAdmin(get_user_id_by_contact_id($id))) {
                return response()->json([
                    'success' => false,
                    'message' => _l('access_denied'),
                ], 400);
            }
        }

        $purposes = $this->gdprModel->getConsentPurposes($id, 'contact');
        $consents = $this->gdprModel->getConsents(['contact_id' => $id]);

        return view('admin.gdpr.contact_consent', compact('purposes', 'consents', 'id'));
    }

    public function updateAllProposalEmailsLinkedToCustomer(Request $request, $contact_id)
    {
        $success = false;
        $email = '';

        if ($request->input('update')) {
            $contact = $this->clientsModel->getContact($contact_id);

            $proposals = $this->proposalsModel->get([
                'rel_type' => 'customer',
                'rel_id' => $contact->userid,
                'email' => $request->input('original_email'),
            ]);

            $affected_rows = 0;

            foreach ($proposals as $proposal) {
                $this->proposalsModel->update($proposal['id'], ['email' => $contact->email]);

                if ($this->proposalsModel->getAffectedRows() > 0) {
                    $affected_rows++;
                }
            }

            if ($affected_rows > 0) {
                $success = true;
            }
        }

        return response()->json([
            'success' => $success,
            'message' => _l('proposals_emails_updated', [
                _l('contact_lowercase'),
                $contact->email,
            ]),
        ]);
    }
    public function assignAdmins(Request $request, $id)
    {
        if (!$request->hasPermission('customers', '', 'create') && !$request->hasPermission('customers', '', 'edit')) {
            return redirect()->route('access_denied', ['customers']);
        }

        $success = $this->clientsModel->assignAdmins($request->post(), $id);

        if ($success) {
            session()->flash('alert-success', _l('updated_successfully', _l('client')));
        }

        return redirect()->route('clients.client', ['id' => $id, 'tab' => 'customer_admins']);
    }

    public function deleteCustomerAdmin($customer_id, $staff_id)
    {
        if (!$request->hasPermission('customers', '', 'create') && !$request->hasPermission('customers', '', 'edit')) {
            return redirect()->route('access_denied', ['customers']);
        }

        $this->clientsModel->deleteCustomerAdmin($customer_id, $staff_id);
        return redirect()->route('clients.client', ['id' => $customer_id, 'tab' => 'customer_admins']);
    }

    public function deleteContact($customer_id, $id)
    {
        if (!$request->hasPermission('customers', '', 'delete')) {
            if (!$request->isCustomerAdmin($customer_id)) {
                return redirect()->route('access_denied', ['customers']);
            }
        }

        $contact = $this->clientsModel->getContact($id);
        $hasProposals = false;

        if ($contact && is_gdpr()) {
            if (total_rows(db_prefix() . 'proposals', ['email' => $contact->email]) > 0) {
                $hasProposals = true;
            }
        }

        $this->clientsModel->deleteContact($id);

        if ($hasProposals) {
            session()->flash('gdpr_delete_warning', true);
        }

        return redirect()->route('clients.client', ['id' => $customer_id, 'group' => 'contacts']);
    }

    public function contacts($client_id)
    {
        return $this->app->getTableData('contacts', ['client_id' => $client_id]);
    }

    public function uploadAttachment($id)
    {
        handleClientAttachmentsUpload($id);
    }

    public function addExternalAttachment(Request $request)
    {
        if ($request->isMethod('post')) {
            $this->miscModel->addAttachmentToDatabase(
                $request->input('clientid'),
                'customer',
                $request->input('files'),
                $request->input('external')
            );
        }
    }

    public function deleteAttachment($customer_id, $id)
    {
        if ($request->hasPermission('customers', '', 'delete') || $request->isCustomerAdmin($customer_id)) {
            $this->clientsModel->deleteAttachment($id);
        }

        return redirect()->back();
    }

    public function delete($id)
    {
        if (!$request->hasPermission('customers', '', 'delete')) {
            return redirect()->route('access_denied', ['customers']);
        }

        if (!$id) {
            return redirect()->route('clients');
        }

        $response = $this->clientsModel->delete($id);

        if (is_array($response) && isset($response['referenced'])) {
            session()->flash('alert-warning', _l('customer_delete_transactions_warning', _l('invoices') . ', ' . _l('estimates') . ', ' . _l('credit_notes')));
        } elseif ($response == true) {
            session()->flash('alert-success', _l('deleted', _l('client')));
        } else {
            session()->flash('alert-warning', _l('problem_deleting', _l('client_lowercase')));
        }

        return redirect()->route('clients');
    }

    public function loginAsClient($id)
    {
        if (is_admin()) {
            login_as_client($id);
        }

        hooks()->do_action('after_contact_login');

        return redirect()->to(site_url());
    }

    public function getCustomerBillingAndShippingDetails($id)
    {
        return response()->json($this->clientsModel->getCustomerBillingAndShippingDetails($id));
    }

    public function changeContactStatus($id, $status)
    {
        if ($request->hasPermission('customers', '', 'edit') || $request->isCustomerAdmin(get_user_id_by_contact_id($id))) {
            if ($request->ajax()) {
                $this->clientsModel->changeContactStatus($id, $status);
            }
        }
    }

    public function changeClientStatus($id, $status)
    {
        if ($request->ajax()) {
            $this->clientsModel->changeClientStatus($id, $status);
        }
    }

    public function zipCreditNotes($id)
    {
        $hasPermissionView = $request->hasPermission('credit_notes', '', 'view');

        if (!$hasPermissionView && !$request->hasPermission('credit_notes', '', 'view_own')) {
            return redirect()->route('access_denied', ['Zip Customer Credit Notes']);
        }

        if ($request->isMethod('post')) {
            $this->load->library('app_bulk_pdf_export', [
                'export_type'       => 'credit_notes',
                'status'            => $request->post('credit_note_zip_status'),
                'date_from'         => $request->post('zip-from'),
                'date_to'           => $request->post('zip-to'),
                'redirect_on_error' => admin_url('clients/client/' . $id . '?group=credit_notes'),
            ]);

            $this->app_bulk_pdf_export->setClientId($id);
            $this->app_bulk_pdf_export->inFolder($request->post('file_name'));
            $this->app_bulk_pdf_export->export();
        }
    }

    public function zipInvoices($id)
    {
        $hasPermissionView = $request->hasPermission('invoices', '', 'view');
        if (!$hasPermissionView && !$request->hasPermission('invoices', '', 'view_own')
            && get_option('allow_staff_view_invoices_assigned') == '0') {
            return redirect()->route('access_denied', ['Zip Customer Invoices']);
        }

        if ($request->isMethod('post')) {
            $this->load->library('app_bulk_pdf_export', [
                'export_type'       => 'invoices',
                'status'            => $request->post('invoice_zip_status'),
                'date_from'         => $request->post('zip-from'),
                'date_to'           => $request->post('zip-to'),
                'redirect_on_error' => admin_url('clients/client/' . $id . '?group=invoices'),
            ]);

            $this->app_bulk_pdf_export->setClientId($id);
            $this->app_bulk_pdf_export->inFolder($request->post('file_name'));
            $this->app_bulk_pdf_export->export();
        }
    }

    public function zipEstimates($id)
    {
        $hasPermissionView = $request->hasPermission('estimates', '', 'view');
        if (!$hasPermissionView && !$request->hasPermission('estimates', '', 'view_own')
            && get_option('allow_staff_view_estimates_assigned') == '0') {
            return redirect()->route('access_denied', ['Zip Customer Estimates']);
        }

        if ($request->isMethod('post')) {
            $this->load->library('app_bulk_pdf_export', [
                'export_type'       => 'estimates',
                'status'            => $request->post('estimate_zip_status'),
                'date_from'         => $request->post('zip-from'),
                'date_to'           => $request->post('zip-to'),
                'redirect_on_error' => admin_url('clients/client/' . $id . '?group=estimates'),
            ]);

            $this->app_bulk_pdf_export->setClientId($id);
            $this->app_bulk_pdf_export->inFolder($request->post('file_name'));
            $this->app_bulk_pdf_export->export();
        }
    }

    public function zipPayments($id)
    {
        $hasPermissionView = $request->hasPermission('payments', '', 'view');

        if (!$hasPermissionView && !$request->hasPermission('invoices', '', 'view_own')
            && get_option('allow_staff_view_invoices_assigned') == '0') {
            return redirect()->route('access_denied', ['Zip Customer Payments']);
        }

        $this->load->library('app_bulk_pdf_export', [
                'export_type'       => 'payments',
                'payment_mode'      => $request->post('paymentmode'),
                'date_from'         => $request->post('zip-from'),
                'date_to'           => $request->post('zip-to'),
                'redirect_on_error' => admin_url('clients/client/' . $id . '?group=payments'),
            ]);

        $this->app_bulk_pdf_export->setClientId($id);
        $this->app_bulk_pdf_export->setClientIdColumn(db_prefix() . 'clients.userid');
        $this->app_bulk_pdf_export->inFolder($request->post('file_name'));
        $this->app_bulk_pdf_export->export();
    }

    public function import()
    {
        if (!$request->hasPermission('customers', '', 'create')) {
            return redirect()->route('access_denied', ['customers']);
        }

        $dbFields = $this->db->list_fields(db_prefix() . 'contacts');
        foreach ($dbFields as $key => $contactField) {
            if ($contactField == 'phonenumber') {
                $dbFields[$key] = 'contact_phonenumber';
            }
        }

        $dbFields = array_merge($dbFields, $this->db->list_fields(db_prefix() . 'clients'));

        $this->load->library('import/import_customers', [], 'import');

        $this->import->setDatabaseFields($dbFields)
                     ->setCustomFields(get_custom_fields('customers'));

        if ($request->post('download_sample') === 'true') {
            $this->import->downloadSample();
        }

        if ($request->post()
            && isset($_FILES['file_csv']['name']) && $_FILES['file_csv']['name'] != '') {
            $this->import->setSimulation($request->post('simulate'))
                          ->setTemporaryFileLocation($_FILES['file_csv']['tmp_name'])
                          ->setFilename($_FILES['file_csv']['name'])
                          ->perform();

            $data['total_rows_post'] = $this->import->totalRows();

            if (!$this->import->isSimulation()) {
                session()->flash('alert-success', _l('import_total_imported', $this->import->totalImported()));
            }
        }

        $data['groups']    = $this->clientsModel->getGroups();
        $data['title']     = _l('import');
        $data['bodyclass'] = 'dynamic-create-groups';
        return view('admin.clients.import', $data);
    }

    public function groups()
    {
        if (!is_admin()) {
            return redirect()->route('access_denied', ['Customer Groups']);
        }

        if ($request->ajax()) {
            return $this->app->getTableData('customers_groups');
        }

        $data['title'] = _l('customer_groups');
        return view('admin.clients.groups_manage', $data);
    }
    public function group(Request $request)
    {
        if (!$request->isAdmin() && get_option('staff_members_create_inline_customer_groups') == '0') {
            return redirect()->route('access_denied', ['Customer Groups']);
        }

        if ($request->ajax()) {
            $data = $request->post();

            if ($data['id'] == '') {
                $id      = $this->clientsModel->addGroup($data);
                $message = $id ? _l('added_successfully', _l('customer_group')) : '';
                return response()->json([
                    'success' => $id ? true : false,
                    'message' => $message,
                    'id'      => $id,
                    'name'    => $data['name'],
                ]);
            } else {
                $success = $this->clientsModel->editGroup($data);
                $message = '';

                if ($success == true) {
                    $message = _l('updated_successfully', _l('customer_group'));
                }

                return response()->json([
                    'success' => $success,
                    'message' => $message,
                ]);
            }
        }
    }

    public function deleteGroup($id)
    {
        if (!$request->isAdmin()) {
            return redirect()->route('access_denied', ['Delete Customer Group']);
        }

        if (!$id) {
            return redirect()->route('clients.groups');
        }

        $response = $this->clientsModel->deleteGroup($id);

        if ($response == true) {
            session()->flash('alert-success', _l('deleted', _l('customer_group')));
        } else {
            session()->flash('alert-warning', _l('problem_deleting', _l('customer_group_lowercase')));
        }

        return redirect()->route('clients.groups');
    }

    public function bulkAction(Request $request)
    {
        hooks()->do_action('before_do_bulk_action_for_customers');
        $totalDeleted = 0;

        if ($request->isMethod('post')) {
            $ids    = $request->post('ids');
            $groups = $request->post('groups');

            if (is_array($ids)) {
                foreach ($ids as $id) {
                    if ($request->post('mass_delete')) {
                        if ($this->clientsModel->delete($id)) {
                            $totalDeleted++;
                        }
                    } else {
                        if (!is_array($groups)) {
                            $groups = false;
                        }

                        $this->clientGroupsModel->syncCustomerGroups($id, $groups);
                    }
                }
            }
        }

        if ($request->post('mass_delete')) {
            session()->flash('alert-success', _l('total_clients_deleted', $totalDeleted));
        }
    }

    public function vaultEntryCreate($customerId)
    {
        $data = $request->post();

        if (isset($data['fakeusernameremembered'])) {
            unset($data['fakeusernameremembered']);
        }

        if (isset($data['fakepasswordremembered'])) {
            unset($data['fakepasswordremembered']);
        }

        unset($data['id']);
        $data['creator']      = get_staff_user_id();
        $data['creator_name'] = get_staff_full_name($data['creator']);
        $data['description']  = nl2br($data['description']);
        $data['password']     = $this->encryption->encrypt($request->post('password', false));

        if (empty($data['port'])) {
            unset($data['port']);
        }

        $this->clientsModel->vaultEntryCreate($data, $customerId);
        session()->flash('alert-success', _l('added_successfully', _l('vault_entry')));
        return redirect()->to($_SERVER['HTTP_REFERER']);
    }

    public function vaultEntryUpdate(Request $request, $entryId)
    {
        $entry = $this->clientsModel->getVaultEntry($entryId);

        if ($entry->creator == get_staff_user_id() || is_admin()) {
            $data = $request->post();

            if (isset($data['fakeusernameremembered'])) {
                unset($data['fakeusernameremembered']);
            }

            if (isset($data['fakepasswordremembered'])) {
                unset($data['fakepasswordremembered']);
            }

            $data['last_updated_from'] = get_staff_full_name(get_staff_user_id());
            $data['description']       = nl2br($data['description']);

            if (!empty($data['password'])) {
                $data['password'] = $this->encryption->encrypt($request->post('password', false));
            } else {
                unset($data['password']);
            }

            if (empty($data['port'])) {
                unset($data['port']);
            }

            $this->clientsModel->vaultEntryUpdate($entryId, $data);
            session()->flash('alert-success', _l('updated_successfully', _l('vault_entry')));
        }

        return redirect()->to($_SERVER['HTTP_REFERER']);
    }

    public function vaultEntryDelete($id)
    {
        $entry = $this->clientsModel->getVaultEntry($id);

        if ($entry->creator == get_staff_user_id() || is_admin()) {
            $this->clientsModel->vaultEntryDelete($id);
        }

        return redirect()->to($_SERVER['HTTP_REFERER']);
    }

    public function vaultEncryptPassword(Request $request)
    {
        $id            = $request->post('id');
        $userPassword = $request->post('user_password', false);
        $user          = $this->staffModel->find(get_staff_user_id());

        if (!app_hasher()->CheckPassword($userPassword, $user->password)) {
            return response()->json(['error_msg' => _l('vault_password_user_not_correct')], 401);
        }

        $vault    = $this->clientsModel->getVaultEntry($id);
        $password = $this->encryption->decrypt($vault->password);

        $password = html_escape($password);

        // Failed to decrypt
        if (!$password) {
            return response()->json(['error_msg' => _l('failed_to_decrypt_password')], 400);
        }

        return response()->json(['password' => $password]);
    }

    public function getVaultEntry($id)
    {
        $entry = $this->clientsModel->getVaultEntry($id);
        unset($entry->password);
        $entry->description = clear_textarea_breaks($entry->description);
        return response()->json($entry);
    }

    public function statementPdf(Request $request)
    {
        $customerId = $request->get('customer_id');

        if (!$request->hasPermission(['invoices.view', 'payments.view'])) {
            session()->flash('alert-danger', _l('access_denied'));
            return redirect()->route('clients.client', ['id' => $customerId]);
        }

        $from = $request->get('from');
        $to   = $request->get('to');

        $data['statement'] = $this->clientsModel->getStatement($customerId, to_sql_date($from), to_sql_date($to));

        try {
            $pdf = statement_pdf($data['statement']);
        } catch (Exception $e) {
            $message = $e->getMessage();
            echo $message;
            if (strpos($message, 'Unable to get the size of the image') !== false) {
                show_pdf_unable_to_get_image_size_error();
            }
            die;
        }

        $type = 'D';
        if ($request->get('print')) {
            $type = 'I';
        }

        return $pdf->stream(slug_it(_l('customer_statement') . '-' . $data['statement']['client']->company) . '.pdf');
    }

    public function sendStatement(Request $request)
    {
        $customerId = $request->get('customer_id');

        if (!$request->hasPermission(['invoices.view', 'payments.view'])) {
            session()->flash('alert-danger', _l('access_denied'));
            return redirect()->route('clients.client', ['id' => $customerId]);
        }

        $from = $request->get('from');
        $to   = $request->get('to');

        $sendTo = $request->post('send_to');
        $cc     = $request->post('cc');

        $success = $this->clientsModel->sendStatementToEmail($customerId, $sendTo, $from, $to, $cc);

        // In case client use another language
        load_admin_language();

        if ($success) {
            session()->flash('alert-success', _l('statement_sent_to_client_success'));
        } else {
            session()->flash('alert-danger', _l('statement_sent_to_client_fail'));
        }

        return redirect()->route('clients.client', ['id' => $customerId, 'group' => 'statement']);
    }

    public function statement(Request $request)
    {
        if (!$request->hasPermission(['invoices.view', 'payments.view'])) {
            return response('access_denied', 400);
        }

        $customerId = $request->get('customer_id');
        $from       = $request->get('from');
        $to         = $request->get('to');

        $data['statement'] = $this->clientsModel->getStatement($customerId, to_sql_date($from), to_sql_date($to));

        $data['from'] = $from;
        $data['to']   = $to;

        $viewData['html'] = view('admin.clients.groups._statement', $data)->render();

        return response()->json($viewData);
    }
}


