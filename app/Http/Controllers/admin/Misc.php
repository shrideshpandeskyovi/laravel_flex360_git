<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Misc extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('misc_model');
    }

    public function fetch_address_info_gmaps()
    {
        include_once(APPPATH . 'third_party/JD_Geocoder_Request.php');

        $data = $this->input->post();
        $address = $this->buildAddress($data);

        $apiKey = get_option('google_api_key');
        $response = $this->processGeocodingRequest($apiKey, $address);

        echo json_encode($response);
    }

    private function buildAddress($data)
    {
        $address = $data['address'];

        if (!empty($data['city'])) {
            $address .= ', ' . $data['city'];
        }

        if (!empty($data['country'])) {
            $address .= ', ' . $data['country'];
        }

        return $address;
    }

    private function processGeocodingRequest($apiKey, $address)
    {
        if (empty($apiKey)) {
            return [
                'response' => [
                    'status' => 'MISSING_API_KEY',
                    'error_message' => 'Add Google API Key in Setup->Settings->Google',
                ],
            ];
        }

        $georequest = new JD_Geocoder_Request($apiKey);
        $georequest->forwardSearch($address);

        return $georequest;
    }

    public function get_currency($id)
    {
        echo json_encode(get_currency($id));
    }

    public function get_taxes_dropdown_template()
    {
        $name = $this->input->post('name');
        $taxname = $this->input->post('taxname');
        echo $this->misc_model->get_taxes_dropdown_template($name, $taxname);
    }

    public function dismiss_cron_setup_message()
    {
        update_option('hide_cron_is_required_message', 1);
        redirect($_SERVER['HTTP_REFERER']);
    }
    public function dismiss_notice($optionKey)
{
    update_option($optionKey, 0);
    redirect($_SERVER['HTTP_REFERER']);
}

public function clear_system_popup()
{
    $this->session->unset_userdata('system-popup');
}

public function tinymce_file_browser()
{
    $data['connector']   = admin_url() . '/utilities/media_connector';
    $data['mediaLocale'] = get_media_locale();
    $this->app_css->add('app-css', base_url($this->app_css->core_file('assets/css', 'style.css')) . '?v=' . $this->app_css->core_version(), 'editor-media');
    $this->load->view('admin/includes/elfinder_tinymce', $data);
}

public function get_relation_data()
{
    if ($this->input->post()) {
        $type = $this->input->post('type');
        $data = get_relation_data($type, '', $this->input->post('extra'));
        $rel_id = $this->input->post('rel_id') ? $this->input->post('rel_id') : '';
        $relOptions = init_relation_options($data, $type, $rel_id);
        echo json_encode($relOptions);
        die;
    }
}

public function delete_sale_activity($id)
{
    if (is_admin()) {
        $this->db->where('id', $id);
        $this->db->delete(db_prefix() . 'sales_activity');
    }
}

public function upload_sales_file()
{
    handle_sales_attachments($this->input->post('rel_id'), $this->input->post('type'));
}

public function add_sales_external_attachment()
{
    if ($this->input->post()) {
        $file = $this->input->post('files');
        $this->misc_model->add_attachment_to_database($this->input->post('rel_id'), $this->input->post('type'), $file, $this->input->post('external'));
    }
}

public function toggle_file_visibility($id)
{
    $this->db->where('id', $id);
    $row = $this->db->get(db_prefix() . 'files')->row();
    $v = $row->visible_to_customer == 1 ? 0 : 1;
    $this->db->where('id', $id);
    $this->db->update(db_prefix() . 'files', ['visible_to_customer' => $v]);
    echo $v;
}

public function format_date()
{
    if ($this->input->post()) {
        $date = $this->input->post('date');
        $date = strtotime(current(explode('(', $date)));
        echo _d(date('Y-m-d', $date));
    }
}

public function send_file()
{
    if ($this->input->post('send_file_email')) {
        if ($this->input->post('file_path')) {
            $this->load->model('emails_model');
            $this->emails_model->add_attachment([
                'attachment' => $this->input->post('file_path'),
                'filename'   => $this->input->post('file_name'),
                'type'       => $this->input->post('filetype'),
                'read'       => true,
            ]);
            $message = $this->input->post('send_file_message');
            $message = nl2br($message);
            $success = $this->emails_model->send_simple_email($this->input->post('send_file_email'), $this->input->post('send_file_subject'), $message);
            if ($success) {
                set_alert('success', _l('custom_file_success_send', $this->input->post('send_file_email')));
            } else {
                set_alert('warning', _l('custom_file_fail_send'));
            }
        }
    }
    redirect($_SERVER['HTTP_REFERER']);
}

public function update_ei_items_order($type)
{
    $data = $this->input->post();
    foreach ($data['data'] as $order) {
        $this->db->where('id', $order[0]);
        $this->db->update(db_prefix() . 'itemable', ['item_order' => $order[1]]);
    }
}
public function add_reminder($rel_id, $rel_type)
{
    $message    = '';
    $alert_type = 'warning';

    if ($this->input->post()) {
        $success = $this->misc_model->add_reminder($this->input->post(), $rel_id);
        if ($success) {
            $alert_type = 'success';
            $message    = _l('reminder_added_successfully');
        }
    }

    echo json_encode([
        'alert_type' => $alert_type,
        'message'    => $message,
    ]);
}

public function get_reminders($id, $rel_type)
{
    if ($this->input->is_ajax_request()) {
        $this->app->get_table_data('reminders', [
            'id'       => $id,
            'rel_type' => $rel_type,
        ]);
    }
}

public function my_reminders()
{
    if ($this->input->is_ajax_request()) {
        $this->app->get_table_data('staff_reminders');
    }
}

public function reminders()
{
    $this->load->model('staff_model');
    $data['members']   = $this->staff_model->get('', ['active' => 1]);
    $data['title']     = _l('reminders');
    $data['bodyclass'] = 'all-reminders';
    $this->load->view('admin/utilities/all_reminders', $data);
}

public function reminders_table()
{
    if ($this->input->is_ajax_request()) {
        $this->app->get_table_data('all_reminders');
    }
}

public function delete_reminder($rel_id, $id, $rel_type)
{
    if (!$id && !$rel_id) {
        die('No reminder found');
    }

    $success    = $this->misc_model->delete_reminder($id);
    $alert_type = 'warning';
    $message    = _l('reminder_failed_to_delete');

    if ($success) {
        $alert_type = 'success';
        $message    = _l('reminder_deleted');
    }

    echo json_encode([
        'alert_type' => $alert_type,
        'message'    => $message,
    ]);
}

public function get_reminder($id)
{
    $reminder = $this->misc_model->get_reminders($id);

    if ($reminder && ($reminder->creator == get_staff_user_id() || is_admin())) {
        $reminder->date        = _dt($reminder->date);
        $reminder->description = clear_textarea_breaks($reminder->description);
        echo json_encode($reminder);
    }
}
public function edit_reminder($id)
{
    $reminder = $this->misc_model->get_reminders($id);

    if ($reminder && ($reminder->creator == get_staff_user_id() || is_admin()) && $reminder->isnotified == 0) {
        $success = $this->misc_model->edit_reminder($this->input->post(), $id);

        echo json_encode([
            'alert_type' => 'success',
            'message'    => ($success ? _l('updated_successfully', _l('reminder')) : ''),
        ]);
    }
}

public function run_cron_manually()
{
    if (is_admin()) {
        $this->load->model('cron_model');
        $this->cron_model->run(true);
        redirect(admin_url('settings?group=cronjob'));
    }
}

/* Since Version 1.0.1 - General search */
public function search()
{
    $q = $this->input->post('q');

    $recentSearches = array_reverse(get_staff_recent_search_history());
    $recentSearches[] = $q;
    $recentSearches = update_staff_recent_search_history($recentSearches);

    $data['result'] = $this->misc_model->perform_search($q);

    echo json_encode([
        'results' => $this->load->view('admin/search', $data, true),
        'history' => $recentSearches,
    ]);
}

public function remove_recent_search($index)
{
    $recentSearches = get_staff_recent_search_history();
    unset($recentSearches[$index]);
    update_staff_recent_search_history(array_reverse($recentSearches));
}

public function add_note($rel_id, $rel_type)
{
    if ($this->input->post()) {
        $success = $this->misc_model->add_note($this->input->post(), $rel_type, $rel_id);

        if ($success) {
            set_alert('success', _l('added_successfully', _l('note')));
        }
    }

    redirect($_SERVER['HTTP_REFERER']);
}

public function edit_note($id)
{
    if ($this->input->post()) {
        $success = $this->misc_model->edit_note($this->input->post(), $id);

        echo json_encode([
            'success' => $success,
            'message' => _l('note_updated_successfully'),
        ]);
    }
}

public function delete_note($id)
{
    $success = $this->misc_model->delete_note($id);

    if (!$this->input->is_ajax_request()) {
        if ($success) {
            set_alert('success', _l('deleted', _l('note')));
        }

        redirect($_SERVER['HTTP_REFERER']);
    } else {
        echo json_encode(['success' => $success]);
    }
}

/* Remove customizer open from database */
public function set_setup_menu_closed()
{
    if ($this->input->is_ajax_request()) {
        $this->session->set_userdata([
            'setup-menu-open' => '',
        ]);
    }
}

/* Set session that user clicked on setup_menu menu link to stay open */
public function set_setup_menu_open()
{
    if ($this->input->is_ajax_request()) {
        $this->session->set_userdata([
            'setup-menu-open' => true,
        ]);
    }
}

/* User dismiss announcement */
public function dismiss_announcement($id)
{
    $this->misc_model->dismiss_announcement($id);
    redirect($_SERVER['HTTP_REFERER']);
}
public function set_notifications_read()
{
    if ($this->input->is_ajax_request()) {
        echo json_encode([
            'success' => $this->misc_model->set_notifications_read(),
        ]);
    }
}

public function set_notification_read_inline($id)
{
    $this->misc_model->set_notification_read_inline($id);
}

public function set_desktop_notification_read($id)
{
    $this->misc_model->set_desktop_notification_read($id);
}

public function mark_all_notifications_as_read_inline()
{
    $this->misc_model->mark_all_notifications_as_read_inline();
}

public function notifications_check()
{
    $notificationsIds = [];

    if (get_option('desktop_notifications') == '1') {
        $notifications = $this->misc_model->get_user_notifications();

        $notificationsPluck = array_filter($notifications, function ($n) {
            return $n['isread'] == 0;
        });

        $notificationsIds = array_pluck($notificationsPluck, 'id');
    }

    echo json_encode([
        'html'             => $this->load->view('admin/includes/notifications', [], true),
        'notificationsIds' => $notificationsIds,
    ]);
}

/* Check if staff email exists / ajax */
public function staff_email_exists()
{
    if ($this->input->is_ajax_request()) {
        if ($this->input->post()) {
            // First we need to check if the email is the same
            $member_id = $this->input->post('memberid');

            if ($member_id != '') {
                $this->db->where('staffid', $member_id);
                $_current_email = $this->db->get(db_prefix() . 'staff')->row();

                if ($_current_email->email == $this->input->post('email')) {
                    echo json_encode(true);
                    die();
                }
            }

            $this->db->where('email', $this->input->post('email'));
            $total_rows = $this->db->count_all_results(db_prefix() . 'staff');

            if ($total_rows > 0) {
                echo json_encode(false);
            } else {
                echo json_encode(true);
            }

            die();
        }
    }
}

/* Check if client email exists/  ajax */
public function contact_email_exists()
{
    if ($this->input->is_ajax_request()) {
        if ($this->input->post()) {
            // First we need to check if the email is the same
            $userid = $this->input->post('userid');

            if ($userid != '') {
                $this->db->where('id', $userid);
                $_current_email = $this->db->get(db_prefix() . 'contacts')->row();

                if ($_current_email->email == $this->input->post('email')) {
                    echo json_encode(true);
                    die();
                }
            }

            $this->db->where('email', $this->input->post('email'));
            $total_rows = $this->db->count_all_results(db_prefix() . 'contacts');

            if ($total_rows > 0) {
                echo json_encode(false);
            } else {
                echo json_encode(true);
            }

            die();
        }
    }
}

/* Goes blank page but with message access denied / message set from session flashdata */
public function access_denied()
{
    $this->load->view('admin/blank_page');
}

/* Goes to blank page with message page not found / message set from session flashdata */
public function not_found()
{
    $this->load->view('admin/blank_page');
}
public function change_maximum_number_of_digits_to_decimal_fields($digits)
{
    if (!is_admin()) {
        echo 'You need to be logged in as administrator to perform this action.';
        return;
    }

    hooks()->do_action('before_change_maximum_number_of_digits_to_decimal_fields');

    $tables = $this->db->query("SELECT *
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_SCHEMA='" . APP_DB_NAME . "'")->result_array();

    foreach ($tables as $table_data) {
        $table  = $table_data['TABLE_NAME'];
        $fields = $this->db->list_fields($table);

        foreach ($fields as $field) {
            $field_info = $this->db->query('SHOW FIELDS
                FROM ' . $table . " where Field ='" . $field . "'")->result_array();

            $field_type = strtolower($field_info[0]['Type']);

            if (strpos($field_type, 'decimal') !== false) {
                $field_null = strtoupper($field_info[0]['Null']);
                $field_is_null = ($field_null == 'YES') ? 'NULL' : 'NOT NULL';

                $total_decimals = strafter($field_info[0]['Type'], ',');
                $total_decimals = strbefore($total_decimals, ')');

                $field_default_value = ($field_info[0]['Default'] == null) ? '' : ' DEFAULT 0.' . str_repeat(0, $total_decimals);

                $this->db->query("ALTER TABLE $table CHANGE $field $field DECIMAL($digits,$total_decimals) $field_is_null$field_default_value;");
            }
        }
    }
}

public function change_decimal_places($total_decimals)
{
    if (!is_admin()) {
        echo 'You need to be logged in as administrator to perform this action.';
        return;
    }

    hooks()->do_action('before_change_decimal_places');

    $notChangableFields = ['estimated_hours'];

    $tables = $this->db->query("SELECT *
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_SCHEMA='" . APP_DB_NAME . "'")->result_array();

    foreach ($tables as $table_data) {
        $table  = $table_data['TABLE_NAME'];
        $fields = $this->db->list_fields($table);

        foreach ($fields as $field) {
            if (!in_array($field, $notChangableFields)) {
                $field_info = $this->db->query('SHOW FIELDS
                    FROM ' . $table . " where Field ='" . $field . "'")->result_array();

                $field_type = strtolower($field_info[0]['Type']);

                if (strpos($field_type, 'decimal') !== false) {
                    $field_null = strtoupper($field_info[0]['Null']);
                    $field_is_null = ($field_null == 'YES') ? 'NULL' : 'NOT NULL';

                    $field_default_value = ($field_info[0]['Default'] == null) ? '' : ' DEFAULT 0.' . str_repeat(0, $total_decimals);

                    $this->db->query("ALTER TABLE $table CHANGE $field $field DECIMAL(15,$total_decimals) $field_is_null$field_default_value;");
                }
            }
        }
    }

    echo '<p><strong>Table columns with decimal places updated successfully.</strong></p>';
}

public function convert_tables_to_innodb_engine()
{
    if (!is_admin()) {
        echo 'You need to be logged in as administrator to perform this action.';
        return;
    }

    $databaseName = APP_DB_NAME;
    $tables = $this->db->query("SELECT TABLE_NAME,
                         ENGINE
                        FROM information_schema.TABLES
                        WHERE TABLE_SCHEMA = '$databaseName' and ENGINE = 'myISAM'")->result_array();

    foreach ($tables as $table) {
        $tableName = $table['TABLE_NAME'];
        $this->db->query("ALTER TABLE $tableName ENGINE=InnoDB;");
    }

    echo 'Table engines successfully changed to InnoDB';
}

/**
 * The upgrade script for 232 does not perform the queries below for backward compatibility
 * Mostly it changes the varchar maximum length because of InnoDB index
 */
public function upgrade_232_database()
{
    if (!is_admin()) {
        die('You must be logged in as administrator to perform this action');
    }

    if (get_option('_232_upgrade_db_queries_performed') === '1') {
        die('This action is already processed');
    }

    $charset = $this->db->char_set;
    $collat  = $this->db->dbcollat;

    $this->db->query('ALTER TABLE `' . db_prefix() . 'contacts` CHANGE `lastname` `lastname` VARCHAR(191) CHARACTER SET ' . $charset . ' COLLATE ' . $collat . ' NOT NULL;');
    $this->db->query('ALTER TABLE `' . db_prefix() . 'contacts` CHANGE `firstname` `firstname` VARCHAR(191) CHARACTER SET ' . $charset . ' COLLATE ' . $collat . ' NOT NULL;');
    $this->db->query('ALTER TABLE `' . db_prefix() . 'clients` CHANGE `company` `company` VARCHAR(191) CHARACTER SET ' . $charset . ' COLLATE ' . $collat . ' NULL DEFAULT NULL;');
    // ... (repeat for other tables)

    add_option('_232_upgrade_db_queries_performed', '1', 0);
}

}
