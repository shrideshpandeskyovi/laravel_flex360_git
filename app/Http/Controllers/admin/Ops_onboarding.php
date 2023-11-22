<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Ops_onboarding extends AdminController
{

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Ops_onboarding_model');
    }

    /* List all candidates */
    public function index()
    {
        if ($this->input->is_ajax_request()) {
            $this->app->get_table_data('test_onboarding');
        }
        $data['staff_members'] = $this->staff_model->get('', ['active' => 1]);
        $data['title']         = _l('staff_members');
        $data['roles']         = $this->roles_model->get();
        $this->load->view('admin/test_onboarding/manage', $data);
    }

    public function update_status()
    {
        try {
            $id     = $this->input->post('id');
            $status = $this->input->post('status');
            $result = $this->Ops_onboarding_model->update_Status($id, $status);

            if ($result) {
                $this->updateCallVu($id, 'callvu_assesmentresult_payload', 'CALL_VU_REGISTRATION');
                echo "Assessment status updated successfully.";
            } else {
                echo "Failed to update assessment status.";
            }
        } catch (Exception $ex) {
            print_r($ex);
            die;
        }
    }

    public function update_background_check_status()
    {
        try {
            $id     = $this->input->post('id');
            $status = $this->input->post('status');
            $source = $this->input->post('source');
            $result = $this->Ops_onboarding_model->update_background_check_Status($id, $status, $source);

            if ($result) {
                $this->updateCallVu($id, 'bgv_payload', 'CALL_VU_REGISTRATION');
                echo "Background check status updated successfully.";
            } else {
                echo "Failed to update background check status.";
            }
        } catch (Exception $ex) {
            print_r($ex);
            die;
        }
    }

    private function updateCallVu($id, $payloadField, $urlOption)
    {
        $staff = get_staff($id);

        if (!empty($staff)) {
            $payload = $staff->$payloadField;
        }

        $url = get_option($urlOption);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $result = curl_exec($ch);

        if ($result === false) {
            return ['status' => 'false', 'error' => curl_error($ch)];
        }

        curl_close($ch);
        $result_array = json_decode($result, true);
    }
    public function update_system_access_status()
{
    try {
        $id = $this->input->post('id');
        $status = $this->input->post('status');
        $result = $this->Ops_onboarding_model->update_system_access_status($id, $status);

        if ($result) {
            $this->updateCallVu($id, 'system_access_payload', 'CALL_VU_REGISTRATION');
            echo "system_access_status updated successfully.";
        } else {
            echo "Failed to update system_access_status.";
        }

    } catch (Exception $ex) {
        print_r($ex);
        die;
    }
}

public function enable_flexpert($id)
{
    $this->toggleFlexpertStatus($id, 'Y', 'agent');
}

public function disable_flexpert($id)
{
    $this->toggleFlexpertStatus($id, 'N', 'user');
}

public function update_trainingID()
{
    $this->db->where_in('role', array('2', '5'));
    $users = $this->db->get('tblstaff')->result();

    foreach ($users as $user) {
        $this->updateTrainingIDForUser($user);
    }
}

private function updateCallVu($id, $payloadField, $urlOption)
{
    $staff = get_staff($id);

    if (!empty($staff)) {
        $payload = $staff->$payloadField;
    }

    $url = get_option($urlOption);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $result = curl_exec($ch);

    if ($result === false) {
        return ['status' => 'false', 'error' => curl_error($ch)];
    }

    curl_close($ch);
    $result_array = json_decode($result, true);
}

private function toggleFlexpertStatus($id, $status, $userType)
{
    $staff = $this->db->where('staffid', $id)->get('staff')->row();
    $status = $this->Ops_onboarding_model->enable_flexpert($staff->staffid, $status);
    $this->db->query("update sb_users set user_type = '$userType' where email = '".$staff->email."'");
    $response = [];
    $response['status'] = $status;
    echo json_encode($response);
}

private function updateTrainingIDForUser($user)
{
    if (!isset($user->TrainingID) || empty($user->TrainingID)) {
        $trID = strtolower($this->random_strings(16));
        $this->db->where('staffid', $user->staffid);
        $this->db->update('tblstaff', array('TrainingID' => $trID));
        echo "Updated TrainingID for staffid: $user->staffid<br>";
    }
}

private function random_strings($length_of_string)
{
    $str_result = '0123456789abcdefghijklmnopqrstuvwxyz';
    return substr(
        str_shuffle($str_result),
        0,
        $length_of_string
    );
}

}
