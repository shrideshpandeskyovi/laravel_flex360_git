<?php

namespace App\Http\Controllers;

use App\Services\Imap\Imap;
use App\Services\Imap\ConnectionErrorException;
use Ddeboer\Imap\Exception\MailboxDoesNotExistException;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class DepartmentsController extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('departments_model');

        if (!is_admin()) {
            access_denied('Departments');
        }
    }

    public function index()
    {
        if (request()->ajax()) {
            $this->app->get_table_data('departments');
        }
        $data['email_exist_as_staff'] = $this->email_exist_as_staff();
        $data['title'] = __('departments');
        return view('admin.departments.manage', $data);
    }

    public function department($id = '')
    {
        if (request()->post()) {
            $message = '';
            $data = request()->post();
            $data['password'] = request()->post('password', false);

            if (isset($data['fakeusernameremembered']) || isset($data['fakepasswordremembered'])) {
                unset($data['fakeusernameremembered']);
                unset($data['fakepasswordremembered']);
            }

            if (!request()->post('id')) {
                $id = $this->departments_model->add($data);
                if ($id) {
                    $success = true;
                    $message = __('added_successfully', __('department'));
                }
                return response()->json([
                    'success' => $success,
                    'message' => $message,
                    'email_exist_as_staff' => $this->email_exist_as_staff(),
                ]);
            } else {
                $id = $data['id'];
                unset($data['id']);
                $success = $this->departments_model->update($data, $id);
                if ($success) {
                    $message = __('updated_successfully', __('department'));
                }
                return response()->json([
                    'success' => $success,
                    'message' => $message,
                    'email_exist_as_staff' => $this->email_exist_as_staff(),
                ]);
            }
        }
    }

    public function delete($id)
    {
        if (!$id) {
            return redirect(admin_url('departments'));
        }
        $response = $this->departments_model->delete($id);
        if (is_array($response) && isset($response['referenced'])) {
            set_alert('warning', __('is_referenced', __('department_lowercase')));
        } elseif ($response == true) {
            set_alert('success', __('deleted', __('department')));
        } else {
            set_alert('warning', __('problem_deleting', __('department_lowercase')));
        }
        return redirect(admin_url('departments'));
    }

    public function email_exists()
    {
        $departmentid = request()->post('departmentid');
        if ($departmentid) {
            $currentEmail = DB::table(db_prefix() . 'departments')
                ->where('departmentid', $departmentid)
                ->first();

            if ($currentEmail->email == request()->post('email')) {
                return response()->json(true);
            }
        }

        $exists = DB::table(db_prefix() . 'departments')
            ->where('email', request()->post('email'))
            ->count();

        return response()->json($exists === 0);
    }

    public function folders()
    {
        app_check_imap_open_function();

        $imap = new Imap(
            request()->post('username') ? request()->post('username') : request()->post('email'),
            request()->post('password', false),
            request()->post('host'),
            request()->post('encryption')
        );

        try {
            return response()->json($imap->getSelectableFolders());
        } catch (ConnectionErrorException $e) {
            return response()->json([
                'alert_type' => 'warning',
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function test_imap_connection()
    {
        app_check_imap_open_function();

        $imap = new Imap(
            request()->post('username') ? request()->post('username') : request()->post('email'),
            request()->post('password', false),
            request()->post('host'),
            request()->post('encryption')
        );

        try {
            $connection = $imap->testConnection();

            try {
                $folder = request()->post('folder');
                $connection->getMailbox(empty($folder) ? 'INBOX' : $folder);
            } catch (MailboxDoesNotExistException $e) {
                return response()->json([
                    'alert_type' => 'warning',
                    'message' => $e->getMessage(),
                ]);
            }

            return response()->json([
                'alert_type' => 'success',
                'message' => __('lead_email_connection_ok'),
            ]);
        } catch (ConnectionErrorException $e) {
            return response()->json([
                'alert_type' => 'warning',
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function email_exist_as_staff()
    {
        return DB::table(db_prefix() . 'departments')
            ->whereIn('email', function ($query) {
                $query->select('email')
                    ->from(db_prefix() . 'staff');
            })
            ->count() > 0;
    }
}
