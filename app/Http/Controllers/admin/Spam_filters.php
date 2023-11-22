<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class SpamFiltersController extends AdminController
{
    public function __construct()
    {
        parent::__construct();

        if (!is_admin()) {
            access_denied('Spam Filters');
        }

        $this->load->model('spam_filters_model');
    }

    public function view($relType, $filterType = '')
    {
        if ($this->input->is_ajax_request()) {
            $aColumns = [
                'value',
            ];
            $sIndexColumn = 'id';
            $sTable = db_prefix() . 'spam_filters';
            $result = data_tables_init($aColumns, $sIndexColumn, $sTable, [], [
                'AND type ="' . $filterType . '" AND rel_type="' . $relType . '"',
            ], [
                'id',
            ]);
            $output = $result['output'];
            $rResult = $result['rResult'];
            foreach ($rResult as $aRow) {
                $row = [];
                for ($i = 0; $i < count($aColumns); $i++) {
                    $_data = $aRow[$aColumns[$i]];
                    $row[] = $_data;
                }

                $options = '<div class="tw-flex tw-items-center tw-space-x-3">';
                $options .= '<a href="#" class="tw-text-neutral-500 hover:tw-text-neutral-700 focus:tw-text-neutral-700" data-type="' . $filterType . '" data-value="' . $aRow['value'] . '" onclick="edit_spam_filter(this,' . $aRow['id'] . '); return false;">
                    <i class="fa-regular fa-pen-to-square fa-lg"></i>
                </a>';

                $options .= '<a href="' . admin_url('spam_filters/delete/' . $aRow['id'] . '/' . $relType) . '"
                class="tw-mt-px tw-text-neutral-500 hover:tw-text-neutral-700 focus:tw-text-neutral-700 _delete">
                    <i class="fa-regular fa-trash-can fa-lg"></i>
                </a>';
                $options .= '</div>';

                $row[] = $options;
                $output['aaData'][] = $row;
            }
            echo json_encode($output);
            die();
        }

        $data['rel_type'] = $relType;
        $data['title'] = _l('spam_filters');
        $this->load->view('admin/spam_filters/list', $data);
    }

    public function filter($type)
    {
        $message = '';
        $success = false;
        if ($this->input->post()) {
            if ($this->input->post('id')) {
                $success = $this->spam_filters_model->edit($this->input->post());
                if ($success == true) {
                    $message = _l('updated_successfully', _l('spam_filter'));
                }
            } else {
                $success = $this->spam_filters_model->add($this->input->post(), $type);
                if ($success == true) {
                    $message = _l('added_successfully', _l('spam_filter'));
                }
            }
        }
        echo json_encode([
            'success' => $success,
            'message' => $message,
        ]);
    }

    public function delete($id, $type)
    {
        $success = $this->spam_filters_model->delete($id, $type);
        if ($success) {
            set_alert('success', _l('deleted', _l('spam_filter')));
        }

        return redirect(admin_url('spam_filters/view/' . $type));
    }
}
