<?php

use app\services\imap\Imap;
use app\services\LeadProfileBadges;
use app\services\leads\LeadsKanban;
use app\services\imap\ConnectionErrorException;
use Ddeboer\Imap\Exception\MailboxDoesNotExistException;

header('Content-Type: text/html; charset=utf-8');
defined('BASEPATH') or exit('No direct script access allowed');

class Leads extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('leads_model');
    }

    /* List all leads */
    public function index($id = '')
    {
        close_setup_menu();

        if (!is_staff_member()) {
            access_denied('Leads');
        }

        $data['switch_kanban'] = true;

        if ($this->session->userdata('leads_kanban_view') == 'true') {
            $data['switch_kanban'] = false;
            $data['bodyclass']     = 'kan-ban-body';
        }

        $data['staff'] = $this->staff_model->get('', ['active' => 1]);

        if (is_gdpr() && get_option('gdpr_enable_consent_for_leads') == '1') {
            $this->load->model('gdpr_model');
            $data['consent_purposes'] = $this->gdpr_model->get_consent_purposes();
        }

        $data['summary']  = get_leads_summary();
        $data['statuses'] = $this->leads_model->get_status();
        $data['sources']  = $this->leads_model->get_source();
        $data['title']    = _l('leads');
        // in case accessed the URL leads/index/ directly with id - used in search
        $data['leadid']   = $id;
        $data['isKanBan'] = $this->session->has_userdata('leads_kanban_view') &&
            $this->session->userdata('leads_kanban_view') == 'true';

        $this->load->view('admin/leads/manage_leads', $data);
    }

    public function table()
    {
        if (!is_staff_member()) {
            ajax_access_denied();
        }
        $this->app->get_table_data('leads');
    }
    public function kanban()
    {
        if (!is_staff_member()) {
            ajax_access_denied();
        }

        $data['statuses']      = $this->leads_model->get_status();
        $data['base_currency'] = get_base_currency();
        $data['summary']       = get_leads_summary();

        echo $this->load->view('admin/leads/kan-ban', $data, true);
    }

    /* Add or update lead */
    public function lead($id = '')
    {
        if (!is_staff_member() || ($id != '' && !$this->leads_model->staff_can_access_lead($id))) {
            ajax_access_denied();
        }

        if ($this->input->post()) {
            if ($id == '') {
                $id      = $this->leads_model->add($this->input->post());
                $message = $id ? _l('added_successfully', _l('lead')) : '';

                echo json_encode([
                    'success'  => $id ? true : false,
                    'id'       => $id,
                    'message'  => $message,
                    'leadView' => $id ? $this->_get_lead_data($id) : [],
                ]);
            } else {
                $emailOriginal   = $this->db->select('email')->where('id', $id)->get(db_prefix() . 'leads')->row()->email;
                $proposalWarning = false;
                $message         = '';
                $success         = $this->leads_model->update($this->input->post(), $id);

                if ($success) {
                    $emailNow = $this->db->select('email')->where('id', $id)->get(db_prefix() . 'leads')->row()->email;

                    $proposalWarning = (total_rows(db_prefix() . 'proposals', [
                        'rel_type' => 'lead',
                        'rel_id'   => $id,
                    ]) > 0 && ($emailOriginal != $emailNow) && $emailNow != '') ? true : false;

                    $message = _l('updated_successfully', _l('lead'));
                }
                echo json_encode([
                    'success'          => $success,
                    'message'          => $message,
                    'id'               => $id,
                    'proposal_warning' => $proposalWarning,
                    'leadView'         => $this->_get_lead_data($id),
                ]);
            }
            die;
        }

        echo json_encode([
            'leadView' => $this->_get_lead_data($id),
        ]);
    }

    private function _get_lead_data($id = '')
    {
        $reminder_data         = '';
        $data['lead_locked']   = false;
        $data['openEdit']      = $this->input->get('edit') ? true : false;
        $data['members']       = $this->staff_model->get('', ['is_not_staff' => 0, 'active' => 1]);
        $data['status_id']     = $this->input->get('status_id') ? $this->input->get('status_id') : get_option('leads_default_status');
        $data['base_currency'] = get_base_currency();

        if (is_numeric($id)) {
            $leadWhere = (has_permission('leads', '', 'view') ? [] : '(assigned = ' . get_staff_user_id() . ' OR addedfrom=' . get_staff_user_id() . ' OR is_public=1)');

            $lead = $this->leads_model->get($id, $leadWhere);

            if (!$lead) {
                header('HTTP/1.0 404 Not Found');
                echo _l('lead_not_found');
                die;
            }

            if (total_rows(db_prefix() . 'clients', ['leadid' => $id]) > 0) {
                $data['lead_locked'] = ((!is_admin() && get_option('lead_lock_after_convert_to_customer') == 1) ? true : false);
            }

            $reminder_data = $this->load->view('admin/includes/modals/reminder', [
                'id'             => $lead->id,
                'name'           => 'lead',
                'members'        => $data['members'],
                'reminder_title' => _l('lead_set_reminder_title'),
            ], true);

            $data['lead']          = $lead;
            $data['mail_activity'] = $this->leads_model->get_mail_activity($id);
            $data['notes']         = $this->misc_model->get_notes($id, 'lead');
            $data['activity_log']  = $this->leads_model->get_lead_activity_log($id);

            if (is_gdpr() && get_option('gdpr_enable_consent_for_leads') == '1') {
                $this->load->model('gdpr_model');
                $data['purposes'] = $this->gdpr_model->get_consent_purposes($lead->id, 'lead');
                $data['consents'] = $this->gdpr_model->get_consents(['lead_id' => $lead->id]);
            }

            $leadProfileBadges         = new LeadProfileBadges($id);
            $data['total_reminders']   = $leadProfileBadges->getCount('reminders');
            $data['total_notes']       = $leadProfileBadges->getCount('notes');
            $data['total_attachments'] = $leadProfileBadges->getCount('attachments');
            $data['total_tasks']       = $leadProfileBadges->getCount('tasks');
            $data['total_proposals']   = $leadProfileBadges->getCount('proposals');
        }

        $data['statuses'] = $this->leads_model->get_status();
        $data['sources']  = $this->leads_model->get_source();

        $data = hooks()->apply_filters('lead_view_data', $data);

        return [
            'data'          => $this->load->view('admin/leads/lead', $data, true),
            'reminder_data' => $reminder_data,
        ];
    }

    public function leads_kanban_load_more()
    {
        if (!is_staff_member()) {
            ajax_access_denied();
        }

        $status = $this->input->get('status');
        $page   = $this->input->get('page');

        $this->db->where('id', $status);
        $status = $this->db->get(db_prefix() . 'leads_status')->row_array();

        $leads = (new LeadsKanban($status['id']))
            ->search($this->input->get('search'))
            ->sortBy(
                $this->input->get('sort_by'),
                $this->input->get('sort')
            )
            ->page($page)->get();

        foreach ($leads as $lead) {
            $this->load->view('admin/leads/_kan_ban_card', [
                'lead'   => $lead,
                'status' => $status,
            ]);
        }
    }
    public function switch_kanban($set = 0)
{
    $set = ($set == 1) ? 'true' : 'false';
    $this->session->set_userdata([
        'leads_kanban_view' => $set,
    ]);
    redirect($_SERVER['HTTP_REFERER']);
}

public function export($id)
{
    if (is_admin()) {
        $this->load->library('gdpr/gdpr_lead');
        $this->gdpr_lead->export($id);
    }
}

/* Delete lead from database */
public function delete($id)
{
    if (!$id) {
        redirect(admin_url('leads'));
    }

    if (!has_permission('leads', '', 'delete')) {
        access_denied('Delete Lead');
    }

    $response = $this->leads_model->delete($id);
    if (is_array($response) && isset($response['referenced'])) {
        set_alert('warning', _l('is_referenced', _l('lead_lowercase')));
    } elseif ($response === true) {
        set_alert('success', _l('deleted', _l('lead')));
    } else {
        set_alert('warning', _l('problem_deleting', _l('lead_lowercase')));
    }

    $ref = $_SERVER['HTTP_REFERER'];

    // if user access leads/inded/ID to prevent redirecting on the same url because will throw 404
    if (!$ref || strpos($ref, 'index/' . $id) !== false) {
        redirect(admin_url('leads'));
    }

    redirect($ref);
}

public function mark_as_lost($id)
{
    if (!is_staff_member() || !$this->leads_model->staff_can_access_lead($id)) {
        ajax_access_denied();
    }
    $message = '';
    $success = $this->leads_model->mark_as_lost($id);
    if ($success) {
        $message = _l('lead_marked_as_lost');
    }
    echo json_encode([
        'success'  => $success,
        'message'  => $message,
        'leadView' => $this->_get_lead_data($id),
        'id'       => $id,
    ]);
}
public function unmark_as_lost($id)
{
    if (!is_staff_member() || !$this->leads_model->staff_can_access_lead($id)) {
        ajax_access_denied();
    }
    $message = '';
    $success = $this->leads_model->unmark_as_lost($id);
    if ($success) {
        $message = _l('lead_unmarked_as_lost');
    }
    echo json_encode([
        'success'  => $success,
        'message'  => $message,
        'leadView' => $this->_get_lead_data($id),
        'id'       => $id,
    ]);
}

public function mark_as_junk($id)
{
    if (!is_staff_member() || !$this->leads_model->staff_can_access_lead($id)) {
        ajax_access_denied();
    }
    $message = '';
    $success = $this->leads_model->mark_as_junk($id);
    if ($success) {
        $message = _l('lead_marked_as_junk');
    }
    echo json_encode([
        'success'  => $success,
        'message'  => $message,
        'leadView' => $this->_get_lead_data($id),
        'id'       => $id,
    ]);
}

public function unmark_as_junk($id)
{
    if (!is_staff_member() || !$this->leads_model->staff_can_access_lead($id)) {
        ajax_access_denied();
    }
    $message = '';
    $success = $this->leads_model->unmark_as_junk($id);
    if ($success) {
        $message = _l('lead_unmarked_as_junk');
    }
    echo json_encode([
        'success'  => $success,
        'message'  => $message,
        'leadView' => $this->_get_lead_data($id),
        'id'       => $id,
    ]);
}

public function add_activity()
{
    $leadid = $this->input->post('leadid');
    if (!is_staff_member() || !$this->leads_model->staff_can_access_lead($leadid)) {
        ajax_access_denied();
    }
    if ($this->input->post()) {
        $message = $this->input->post('activity');
        $aId     = $this->leads_model->log_lead_activity($leadid, $message);
        if ($aId) {
            $this->db->where('id', $aId);
            $this->db->update(db_prefix() . 'lead_activity_log', ['custom_activity' => 1]);
        }
        echo json_encode(['leadView' => $this->_get_lead_data($leadid), 'id' => $leadid]);
    }
}

public function get_convert_data($id)
{
    if (!is_staff_member() || !$this->leads_model->staff_can_access_lead($id)) {
        ajax_access_denied();
    }
    if (is_gdpr() && get_option('gdpr_enable_consent_for_contacts') == '1') {
        $this->load->model('gdpr_model');
        $data['purposes'] = $this->gdpr_model->get_consent_purposes($id, 'lead');
    }
    $data['lead'] = $this->leads_model->get($id);
    $this->load->view('admin/leads/convert_to_customer', $data);
}

/**
 * Convert lead to client
 * @since  version 1.0.1
 * @return mixed
 */
public function convert_to_customer()
{
    if (!is_staff_member()) {
        access_denied('Lead Convert to Customer');
    }

    if ($this->input->post()) {
        $default_country = get_option('customer_default_country');
        $data = $this->input->post();
        $data['password'] = $this->input->post('password', false);

        $original_lead_email = $data['original_lead_email'];
        unset($data['original_lead_email']);

        $notes = $consents = $merge_db_fields = $merge_db_contact_fields = $include_leads_custom_fields = [];

        // Unset and retrieve data if available
        $this->unsetAndRetrieve($data, 'transfer_notes', $notes);
        $this->unsetAndRetrieve($data, 'transfer_consent', $consents);
        $this->unsetAndRetrieve($data, 'merge_db_fields', $merge_db_fields);
        $this->unsetAndRetrieve($data, 'merge_db_contact_fields', $merge_db_contact_fields);
        $this->unsetAndRetrieve($data, 'include_leads_custom_fields', $include_leads_custom_fields);

        // Set default country if needed
        if ($data['country'] == '' && $default_country != '') {
            $data['country'] = $default_country;
        }

        // Map billing fields
        $this->mapBillingFields($data);

        $data['is_primary'] = 1;
        $id = $this->clients_model->add($data, true);

        if ($id) {
            $primary_contact_id = get_primary_contact_user_id($id);

            // Process notes and consents
            $this->processNotes($notes, $id);
            $this->processConsents($consents, $primary_contact_id);

            // Auto-assign customer admin
            $this->autoAssignCustomerAdmin($id);

            // Log lead activity and update lead status
            $this->updateLeadStatus($data['leadid']);

            // Check if lead email is different than client email
            $this->checkLeadEmailDifference($data, $original_lead_email, $id);

            // Include leads custom fields
            $this->includeLeadsCustomFields($include_leads_custom_fields, $data, $id, $primary_contact_id);

            // Set the lead to status client
            $this->setLeadStatusToClient($data['leadid']);

            // GDPR: Move proposals and delete lead
            $this->handleGDPR($id, $data['leadid']);

            log_activity('Created Lead Client Profile [LeadID: ' . $data['leadid'] . ', ClientID: ' . $id . ']');
            hooks()->do_action('lead_converted_to_customer', ['lead_id' => $data['leadid'], 'customer_id' => $id]);
            redirect(admin_url('clients/client/' . $id));
        }
    }
}

// Helper function to unset and retrieve data if available
private function unsetAndRetrieve(&$data, $key, &$variable)
{
    if (isset($data[$key])) {
        $variable = $data[$key];
        unset($data[$key]);
    }
}

// Helper function to map billing fields
private function mapBillingFields(&$data)
{
    $data['billing_street'] = $data['address'];
    $data['billing_city'] = $data['city'];
    $data['billing_state'] = $data['state'];
    $data['billing_zip'] = $data['zip'];
    $data['billing_country'] = $data['country'];
}

// Helper function to process notes
private function processNotes($notes, $id)
{
    if (!empty($notes)) {
        foreach ($notes as $note) {
            $this->db->insert(db_prefix() . 'notes', [
                'rel_id' => $id,
                'rel_type' => 'customer',
                'dateadded' => $note['dateadded'],
                'addedfrom' => $note['addedfrom'],
                'description' => $note['description'],
                'date_contacted' => $note['date_contacted'],
            ]);
        }
    }
}

// Helper function to process consents
private function processConsents($consents, $primary_contact_id)
{
    if (!empty($consents)) {
        foreach ($consents as $consent) {
            unset($consent['id']);
            unset($consent['purpose_name']);
            $consent['lead_id'] = 0;
            $consent['contact_id'] = $primary_contact_id;
            $this->gdpr_model->add_consent($consent);
        }
    }
}

// Helper function to auto-assign customer admin
private function autoAssignCustomerAdmin($id)
{
    if (!has_permission('customers', '', 'view') && get_option('auto_assign_customer_admin_after_lead_convert') == 1) {
        $this->db->insert(db_prefix() . 'customer_admins', [
            'date_assigned' => date('Y-m-d H:i:s'),
            'customer_id' => $id,
            'staff_id' => get_staff_user_id(),
        ]);
    }
}

// Helper function to update lead status
private function updateLeadStatus($leadId)
{
    $this->leads_model->log_lead_activity($leadId, 'not_lead_activity_converted', false, serialize([get_staff_full_name()]));
    $defaultStatus = $this->leads_model->get_status('', ['isdefault' => 1]);
    $this->db->where('id', $leadId);
    $this->db->update(db_prefix() . 'leads', [
        'date_converted' => date('Y-m-d H:i:s'),
        'status' => $defaultStatus[0]['id'],
        'junk' => 0,
        'lost' => 0,
    ]);
}

// Helper function to check lead email difference
private function checkLeadEmailDifference($data, $originalLeadEmail, $id)
{
    $contact = $this->clients_model->get_contact(get_primary_contact_user_id($id));
    if ($contact->email != $originalLeadEmail && $originalLeadEmail != '') {
        $this->leads_model->log_lead_activity($data['leadid'], 'not_lead_activity_converted_email', false, serialize([
            $originalLeadEmail,
            $contact->email,
        ]));
    }
}

// Helper function to include leads custom fields
private function includeLeadsCustomFields($includeLeadsCustomFields, $data, $id, $primaryContactId)
{
    if (isset($includeLeadsCustomFields)) {
        foreach ($includeLeadsCustomFields as $fieldId => $value) {
            // ... (The rest of the code is not provided for brevity)
        }
    }
}

// Helper function to set lead status to client
private function setLeadStatusToClient($leadId)
{
    $this->db->where('isdefault', 1);
    $statusClientId = $this->db->get(db_prefix() . 'leads_status')->row()->id;
    $this->db->where('id', $leadId);
    $this->db->update(db_prefix() . 'leads', ['status' => $statusClientId]);
}

// Helper function to handle GDPR
private function handleGDPR($id, $leadId)
{
    if (is_gdpr() && get_option('gdpr_after_lead_converted_delete') == '1') {
        // When lead is deleted
        // move all proposals to the actual customer record
        $this->db->where('rel_id', $leadId);
        $this->db->where('rel_type', 'lead');
        $this->db->update('proposals', [
            'rel_id' => $id,
            'rel_type' => 'customer',
        ]);

        $this->leads_model->delete($leadId);

        $this->db->where('userid', $id);
        $this->db->update(db_prefix() . 'clients', ['leadid' => null]);
    }
}
public function update_lead_status()
{
    if ($this->input->post() && $this->input->is_ajax_request()) {
        $this->leads_model->update_lead_status($this->input->post());
    }
}

public function update_status_order()
{
    if ($post_data = $this->input->post()) {
        $this->leads_model->update_status_order($post_data);
    }
}

public function add_lead_attachment()
{
    $id = $this->input->post('id');
    $lastFile = $this->input->post('last_file');

    if (!$this->canAccessLead($id)) {
        ajax_access_denied();
    }

    handle_lead_attachments($id);
    echo json_encode(['leadView' => $lastFile ? $this->getLeadData($id) : [], 'id' => $id]);
}

public function add_external_attachment()
{
    if ($this->input->post()) {
        $this->leads_model->add_attachment_to_database(
            $this->input->post('lead_id'),
            $this->input->post('files'),
            $this->input->post('external')
        );
    }
}

public function delete_attachment($id, $lead_id)
{
    if (!$this->canAccessLead($lead_id)) {
        ajax_access_denied();
    }

    echo json_encode([
        'success' => $this->leads_model->delete_lead_attachment($id),
        'leadView' => $this->getLeadData($lead_id),
        'id' => $lead_id,
    ]);
}

public function delete_note($id, $lead_id)
{
    if (!$this->canAccessLead($lead_id)) {
        ajax_access_denied();
    }

    echo json_encode([
        'success' => $this->misc_model->delete_note($id),
        'leadView' => $this->getLeadData($lead_id),
        'id' => $lead_id,
    ]);
}

public function update_all_proposal_emails_linked_to_lead($id)
{
    $success = false;
    $email = '';

    if ($this->input->post('update')) {
        $this->load->model('proposals_model');
        $this->db->select('email');
        $this->db->where('id', $id);
        $email = $this->db->get(db_prefix() . 'leads')->row()->email;

        $proposals = $this->proposals_model->get('', [
            'rel_type' => 'lead',
            'rel_id' => $id,
        ]);

        $affected_rows = 0;

        foreach ($proposals as $proposal) {
            $this->db->where('id', $proposal['id']);
            $this->db->update(db_prefix() . 'proposals', [
                'email' => $email,
            ]);

            if ($this->db->affected_rows() > 0) {
                $affected_rows++;
            }
        }

        if ($affected_rows > 0) {
            $success = true;
        }
    }

    echo json_encode([
        'success' => $success,
        'message' => _l('proposals_emails_updated', [
            _l('lead_lowercase'),
            $email,
        ]),
    ]);
}

// Helper function to check lead access
private function canAccessLead($lead_id)
{
    return is_staff_member() && $this->leads_model->staff_can_access_lead($lead_id);
}

// Helper function to get lead data
private function getLeadData($lead_id)
{
    return $this->_get_lead_data($lead_id);
}
public function save_form_data()
{
    $data = $this->input->post();

    // Ensure form data is present
    if (!isset($data['formData']) || (isset($data['formData']) && !$data['formData'])) {
        echo json_encode([
            'success' => false,
        ]);
        die;
    }

    // Prevent potential issues with CodeIgniter XSS filtering
    $data['formData'] = preg_replace('/=\\\\/m', "=''", $data['formData']);

    $this->db->where('id', $data['id']);
    $this->db->update(db_prefix() . 'web_to_lead', [
        'form_data' => $data['formData'],
    ]);

    if ($this->db->affected_rows() > 0) {
        echo json_encode([
            'success' => true,
            'message' => _l('updated_successfully', _l('web_to_lead_form')),
        ]);
    } else {
        echo json_encode([
            'success' => false,
        ]);
    }
}

public function form($id = '')
{
    if (!is_admin()) {
        access_denied('Web To Lead Access');
    }

    if ($this->input->post()) {
        $this->handleFormSubmission($id);
    }

    $data = $this->prepareFormData($id);
    $this->loadFormView($data);
}

// Helper function to handle form submission
private function handleFormSubmission($id)
{
    if ($id == '') {
        $data = $this->input->post();
        $id   = $this->leads_model->add_form($data);

        if ($id) {
            set_alert('success', _l('added_successfully', _l('web_to_lead_form')));
            redirect(admin_url('leads/form/' . $id));
        }
    } else {
        $success = $this->leads_model->update_form($id, $this->input->post());

        if ($success) {
            set_alert('success', _l('updated_successfully', _l('web_to_lead_form')));
        }

        redirect(admin_url('leads/form/' . $id));
    }
}

// Helper function to prepare form data
private function prepareFormData($id)
{
    $data = [
        'formData' => [],
        'title' => _l('web_to_lead'),
        'bodyclass' => 'web-to-lead-form',
        'db_fields' => [],
    ];

    // ... (remaining logic from the original 'form' function)

    return $data;
}

// Helper function to load the form view
private function loadFormView($data)
{
    $this->load->model('roles_model');
    $data['roles'] = $this->roles_model->get();
    $data['sources'] = $this->leads_model->get_source();
    $data['statuses'] = $this->leads_model->get_status();
    $data['members'] = $this->staff_model->get('', ['active' => 1, 'is_not_staff' => 0]);
    $data['languages'] = $this->app->get_available_languages();

    $this->load->view('admin/leads/formbuilder', $data);
}
public function forms($id = '')
{
    if (!is_admin()) {
        access_denied('Web To Lead Access');
    }

    if ($this->input->is_ajax_request()) {
        $this->app->get_table_data('web_to_lead');
    }

    $data['title'] = _l('web_to_lead');
    $this->load->view('admin/leads/forms', $data);
}

public function delete_form($id)
{
    if (!is_admin()) {
        access_denied('Web To Lead Access');
    }

    $this->handleFormDeletion($id);

    redirect(admin_url('leads/forms'));
}

// Helper function to handle form deletion
private function handleFormDeletion($id)
{
    $success = $this->leads_model->delete_form($id);

    if ($success) {
        set_alert('success', _l('deleted', _l('web_to_lead_form')));
    }
}

// Sources
public function sources()
{
    $this->checkAdminAccess('Leads Sources');

    $data['sources'] = $this->leads_model->get_source();
    $data['title']   = 'Leads sources';
    $this->load->view('admin/leads/manage_sources', $data);
}

public function source()
{
    $this->checkAdminAccess('Leads Sources');

    if ($this->input->post()) {
        $this->handleSourceFormSubmission();
    }
}

// Helper function to handle source form submission
private function handleSourceFormSubmission()
{
    $data = $this->input->post();

    if (!$data['id']) {
        $this->addOrUpdateSource($data, 'lead_source', 'Leads Sources');
    } else {
        $this->updateSource($data, 'lead_source');
    }
}

// Helper function to add or update leads source
private function addOrUpdateSource($data, $type, $accessDeniedMsg)
{
    $inline = isset($data['inline']);

    if (isset($data['inline'])) {
        unset($data['inline']);
    }

    $id = $this->leads_model->add_source($data);

    if (!$inline) {
        if ($id) {
            set_alert('success', _l('added_successfully', _l($type)));
        }
    } else {
        echo json_encode(['success' => $id ? true : false, 'id' => $id]);
    }
}

// Helper function to update leads source
private function updateSource($data, $type)
{
    $id = $data['id'];
    unset($data['id']);

    $success = $this->leads_model->update_source($data, $id);

    if ($success) {
        set_alert('success', _l('updated_successfully', _l($type)));
    }
}

public function delete_source($id)
{
    $this->checkAdminAccess('Delete Lead Source');

    if (!$id) {
        redirect(admin_url('leads/sources'));
    }

    $this->handleSourceDeletion($id);

    redirect(admin_url('leads/sources'));
}

// Helper function to handle source deletion
private function handleSourceDeletion($id)
{
    $response = $this->leads_model->delete_source($id);

    if (is_array($response) && isset($response['referenced'])) {
        set_alert('warning', _l('is_referenced', _l('lead_source_lowercase')));
    } elseif ($response == true) {
        set_alert('success', _l('deleted', _l('lead_source')));
    } else {
        set_alert('warning', _l('problem_deleting', _l('lead_source_lowercase')));
    }
}

// Statuses
public function statuses()
{
    $this->checkAdminAccess('Leads Statuses');

    $data['statuses'] = $this->leads_model->get_status();
    $data['title']    = 'Leads statuses';
    $this->load->view('admin/leads/manage_statuses', $data);
}

public function status()
{
    $this->checkAdminAccess('Leads Statuses');

    if ($this->input->post()) {
        $this->handleStatusFormSubmission();
    }
}

// Helper function to handle status form submission
private function handleStatusFormSubmission()
{
    $data = $this->input->post();

    if (!$data['id']) {
        $this->addOrUpdateStatus($data, 'lead_status', 'Leads Statuses');
    } else {
        $this->updateStatus($data, 'lead_status');
    }
}

// Helper function to add or update leads status
private function addOrUpdateStatus($data, $type, $accessDeniedMsg)
{
    $inline = isset($data['inline']);

    if (isset($data['inline'])) {
        unset($data['inline']);
    }

    $id = $this->leads_model->add_status($data);

    if (!$inline) {
        if ($id) {
            set_alert('success', _l('added_successfully', _l($type)));
        }
    } else {
        echo json_encode(['success' => $id ? true : false, 'id' => $id]);
    }
}

// Helper function to update leads status
private function updateStatus($data, $type)
{
    $id = $data['id'];
    unset($data['id']);

    $success = $this->leads_model->update_status($data, $id);

    if ($success) {
        set_alert('success', _l('updated_successfully', _l($type)));
    }
}
public function delete_status($id)
{
    $this->checkAdminAccess('Leads Statuses');

    if (!$id) {
        redirect(admin_url('leads/statuses'));
    }

    $this->handleStatusDeletion($id);

    redirect(admin_url('leads/statuses'));
}

// Helper function to handle status deletion
private function handleStatusDeletion($id)
{
    $response = $this->leads_model->delete_status($id);

    if (is_array($response) && isset($response['referenced'])) {
        set_alert('warning', _l('is_referenced', _l('lead_status_lowercase')));
    } elseif ($response == true) {
        set_alert('success', _l('deleted', _l('lead_status')));
    } else {
        set_alert('warning', _l('problem_deleting', _l('lead_status_lowercase')));
    }
}

public function add_note($rel_id)
{
    $this->checkLeadAccess($rel_id);

    if ($this->input->post()) {
        $data = $this->input->post();

        $this->handleNoteAddition($data, $rel_id);
    }

    echo json_encode(['leadView' => $this->_get_lead_data($rel_id), 'id' => $rel_id]);
}

// Helper function to handle lead note addition
private function handleNoteAddition($data, $rel_id)
{
    if ($data['contacted_indicator'] == 'yes') {
        $contacted_date         = to_sql_date($data['custom_contact_date'], true);
        $data['date_contacted'] = $contacted_date;
    }

    unset($data['contacted_indicator']);
    unset($data['custom_contact_date']);

    $data['description'] = isset($data['lead_note_description']) ? $data['lead_note_description'] : $data['description'];

    if (isset($data['lead_note_description'])) {
        unset($data['lead_note_description']);
    }

    $note_id = $this->misc_model->add_note($data, 'lead', $rel_id);

    if ($note_id && isset($contacted_date)) {
        $this->updateLeadLastContact($rel_id, $contacted_date);
        $this->logLeadActivity($rel_id, 'not_lead_activity_contacted', $contacted_date);
    }
}

// Helper function to update lead's last contact date
private function updateLeadLastContact($rel_id, $contacted_date)
{
    $this->db->where('id', $rel_id);
    $this->db->update(db_prefix() . 'leads', [
        'lastcontact' => $contacted_date,
    ]);
}

// Helper function to log lead activity
private function logLeadActivity($rel_id, $activity_type, $contacted_date)
{
    if ($this->db->affected_rows() > 0) {
        $this->leads_model->log_lead_activity($rel_id, $activity_type, false, serialize([
            get_staff_full_name(get_staff_user_id()),
            _dt($contacted_date),
        ]));
    }
}

public function email_integration_folders()
{
    $this->checkAdminAccess('Leads Test Email Integration');

    app_check_imap_open_function();

    $this->handleEmailIntegrationFolders();
}

// Helper function to handle email integration folders
private function handleEmailIntegrationFolders()
{
    $imap = new Imap(
        $this->input->post('email'),
        $this->input->post('password', false),
        $this->input->post('imap_server'),
        $this->input->post('encryption')
    );

    try {
        echo json_encode($imap->getSelectableFolders());
    } catch (ConnectionErrorException $e) {
        echo json_encode([
            'alert_type' => 'warning',
            'message'    => $e->getMessage(),
        ]);
    }
}
public function test_email_integration()
{
    $this->checkAdminAccess('Leads Test Email Integration');
    app_check_imap_open_function(admin_url('leads/email_integration'));

    $mail = $this->leads_model->get_email_integration();
    $password = $mail->password;

    if (false == $this->encryption->decrypt($password)) {
        set_alert('danger', _l('failed_to_decrypt_password'));
        redirect(admin_url('leads/email_integration'));
    }

    $imap = new Imap(
        $mail->email,
        $this->encryption->decrypt($password),
        $mail->imap_server,
        $mail->encryption
    );

    try {
        $connection = $imap->testConnection();

        try {
            $connection->getMailbox($mail->folder);
            set_alert('success', _l('lead_email_connection_ok'));
        } catch (MailboxDoesNotExistException $e) {
            set_alert('danger', str_replace(["\n", 'Mailbox'], ['<br />', 'Folder'], addslashes($e->getMessage())));
        }
    } catch (ConnectionErrorException $e) {
        $error = str_replace("\n", '<br />', addslashes($e->getMessage()));
        set_alert('danger', _l('lead_email_connection_not_ok') . '<br /><br /><b>' . $error . '</b>');
    }

    redirect(admin_url('leads/email_integration'));
}

public function email_integration()
{
    $this->checkAdminAccess('Leads Email Integration');

    if ($this->input->post()) {
        $data = $this->input->post();
        $data['password'] = $this->input->post('password', false);

        $this->handleEmailIntegrationUpdate($data);
    }

    $this->loadEmailIntegrationView();
}

// Helper function to handle email integration update
private function handleEmailIntegrationUpdate($data)
{
    if (isset($data['fakeusernameremembered'])) {
        unset($data['fakeusernameremembered']);
    }

    if (isset($data['fakepasswordremembered'])) {
        unset($data['fakepasswordremembered']);
    }

    $success = $this->leads_model->update_email_integration($data);

    if ($success) {
        set_alert('success', _l('leads_email_integration_updated'));
    }

    redirect(admin_url('leads/email_integration'));
}

// Helper function to load the email integration view
private function loadEmailIntegrationView()
{
    $data['roles'] = $this->roles_model->get();
    $data['sources'] = $this->leads_model->get_source();
    $data['statuses'] = $this->leads_model->get_status();
    $data['members'] = $this->staff_model->get('', ['is_not_staff' => 0, 'active' => 1]);
    $data['title'] = _l('leads_email_integration');
    $data['mail'] = $this->leads_model->get_email_integration();
    $data['bodyclass'] = 'leads-email-integration';

    $this->load->view('admin/leads/email_integration', $data);
}

public function change_status_color()
{
    if ($this->input->post() && is_admin()) {
        $this->leads_model->change_status_color($this->input->post());
    }
}

public function import()
{
    $this->checkImportAccess();

    $dbFields = $this->getDbFieldsForLeads();

    $this->load->library('import/import_leads', [], 'import');
    $this->import->setDatabaseFields($dbFields)->setCustomFields(get_custom_fields('leads'));

    if ($this->input->post('download_sample') === 'true') {
        $this->import->downloadSample();
    }

    $this->handleImport($dbFields);
}

// Helper function to check import access
private function checkImportAccess()
{
    if (!is_admin() && get_option('allow_non_admin_members_to_import_leads') != '1') {
        access_denied('Leads Import');
    }
}

// Helper function to get database fields for leads
private function getDbFieldsForLeads()
{
    $dbFields = $this->db->list_fields(db_prefix() . 'leads');
    array_push($dbFields, 'tags');
    return $dbFields;
}

// Helper function to handle the import process
private function handleImport($dbFields)
{
    if ($this->input->post() && isset($_FILES['file_csv']['name']) && $_FILES['file_csv']['name'] != '') {
        $this->import->setSimulation($this->input->post('simulate'))
                      ->setTemporaryFileLocation($_FILES['file_csv']['tmp_name'])
                      ->setFilename($_FILES['file_csv']['name'])
                      ->perform();

        $this->handleImportResult();
    }

    $this->loadImportView();
}

// Helper function to handle the import result
private function handleImportResult()
{
    $data['total_rows_post'] = $this->import->totalRows();

    if (!$this->import->isSimulation()) {
        set_alert('success', _l('import_total_imported', $this->import->totalImported()));
    }
}

// Helper function to load the import view
private function loadImportView()
{
    $data['statuses'] = $this->leads_model->get_status();
    $data['sources'] = $this->leads_model->get_source();
    $data['members'] = $this->staff_model->get('', ['is_not_staff' => 0, 'active' => 1]);
    $data['title'] = _l('import');

    $this->load->view('admin/leads/import', $data);
}

public function validate_unique_field()
{
    if ($this->input->post()) {
        $this->checkUniqueFieldValidation();
    }
}

// Helper function to check unique field validation
private function checkUniqueFieldValidation()
{
    $leadId = $this->input->post('lead_id');
    $field = $this->input->post('field');
    $value = $this->input->post($field);

    if ($leadId != '') {
        $this->checkSameFieldValue($leadId, $field, $value);
    }

    $this->handleUniqueFieldValidation($field, $value);
}

// Helper function to check if the field value is the same
private function checkSameFieldValue($leadId, $field, $value)
{
    $this->db->select($field);
    $this->db->where('id', $leadId);
    $row = $this->db->get(db_prefix() . 'leads')->row();

    if ($row->{$field} == $value) {
        echo json_encode(true);
        die();
    }
}

// Helper function to handle unique field validation
private function handleUniqueFieldValidation($field, $value)
{
    echo total_rows(db_prefix() . 'leads', [$field => $value]) > 0 ? 'false' : 'true';
}

public function bulk_action()
{
    $this->checkStaffMemberAccess();
    hooks()->do_action('before_do_bulk_action_for_leads');
    $totalDeleted = $this->performBulkAction();

    $this->handleBulkActionResult($totalDeleted);
}

// Helper function to perform bulk action
private function performBulkAction()
{
    $totalDeleted = 0;

    if ($this->input->post()) {
        $ids = $this->input->post('ids');
        $status = $this->input->post('status');
        $source = $this->input->post('source');
        $assigned = $this->input->post('assigned');
        $visibility = $this->input->post('visibility');
        $tags = $this->input->post('tags');
        $lastContact = $this->input->post('last_contact');
        $lost = $this->input->post('lost');
        $hasPermissionDelete = has_permission('leads', '', 'delete');

        $this->handleBulkActionIds($ids, $status, $source, $assigned, $visibility, $tags, $lastContact, $lost, $hasPermissionDelete, $totalDeleted);
    }

    return $totalDeleted;
}

// Helper function to handle bulk action IDs
private function handleBulkActionIds($ids, $status, $source, $assigned, $visibility, $tags, $lastContact, $lost, $hasPermissionDelete, &$totalDeleted)
{
    if (is_array($ids)) {
        foreach ($ids as $id) {
            $this->handleBulkActionId($id, $status, $source, $assigned, $lastContact, $visibility, $tags, $lost, $hasPermissionDelete, $totalDeleted);
        }
    }
}

// Helper function to handle bulk action for a single ID
private function handleBulkActionId($id, $status, $source, $assigned, $lastContact, $visibility, $tags, $lost, $hasPermissionDelete, &$totalDeleted)
{
    if ($this->input->post('mass_delete')) {
        $this->deleteLead($id, $hasPermissionDelete, $totalDeleted);
    } else {
        $this->updateLead($id, $status, $source, $assigned, $lastContact, $visibility, $tags, $lost);
    }
}

// Helper function to delete a lead
private function deleteLead($id, $hasPermissionDelete, &$totalDeleted)
{
    if ($hasPermissionDelete) {
        if ($this->leads_model->delete($id)) {
            $totalDeleted++;
        }
    }
}

// Helper function to update a lead
private function updateLead($id, $status, $source, $assigned, $lastContact, $visibility, $tags, $lost)
{
    $update = [];

    if ($status) {
        $this->leads_model->update_lead_status(['status' => $status, 'leadid' => $id]);
    }

    $this->updateLeadFields($id, $source, $assigned, $lastContact, $visibility, $update);
    $this->updateLeadTags($tags, $id, 'lead');
    $this->handleLostStatus($lost, $id);
}

// Helper function to update lead fields
private function updateLeadFields($id, $source, $assigned, $lastContact, $visibility, &$update)
{
    if ($source) {
        $update['source'] = $source;
    }

    if ($assigned) {
        $update['assigned'] = $assigned;
    }

    if ($lastContact) {
        $this->updateLastContact($id, $lastContact, $update);
    }

    if ($visibility) {
        $this->updateVisibility($id, $visibility, $update);
    }

    $this->performLeadUpdate($id, $update);
}

// Helper function to update last contact date
private function updateLastContact($id, $lastContact, &$update)
{
    $lastContact = to_sql_date($lastContact, true);
    $update['lastcontact'] = $lastContact;
}

// Helper function to update visibility
private function updateVisibility($id, $visibility, &$update)
{
    $update['is_public'] = $visibility == 'public' ? 1 : 0;
}

// Helper function to perform lead update
private function performLeadUpdate($id, $update)
{
    if (count($update) > 0) {
        $this->db->where('id', $id);
        $this->db->update(db_prefix() . 'leads', $update);
    }
}

// Helper function to update lead tags
private function updateLeadTags($tags, $id, $type)
{
    if ($tags) {
        handle_tags_save($tags, $id, $type);
    }
}

// Helper function to handle lost status
private function handleLostStatus($lost, $id)
{
    if ($lost == 'true') {
        $this->leads_model->mark_as_lost($id);
    }
}

// Helper function to handle bulk action result
private function handleBulkActionResult($totalDeleted)
{
    if ($this->input->post('mass_delete')) {
        set_alert('success', _l('total_leads_deleted', $totalDeleted));
    }
}

public function download_files($lead_id)
{
    $this->checkLeadAccess($lead_id);

    $files = $this->leads_model->get_lead_attachments($lead_id);

    $this->handleDownloadFiles($files, $lead_id);
}

// Helper function to handle downloading lead files
private function handleDownloadFiles($files, $leadId)
{
    if (count($files) == 0) {
        redirect($_SERVER['HTTP_REFERER']);
    }

    $path = get_upload_path_by_type('lead') . $leadId;

    $this->load->library('zip');

    foreach ($files as $file) {
        $this->zip->read_file($path . '/' . $file['file_name']);
    }

    $this->zip->download('files.zip');
    $this->zip->clear_data();
}


}
