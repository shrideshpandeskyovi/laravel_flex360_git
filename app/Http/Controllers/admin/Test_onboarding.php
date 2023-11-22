<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\TestOnboarding;

class TestOnboardingController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth'); // Assuming you want to use authentication middleware
    }

    public function index(Request $request)
    {
        if ($request->ajax()) {
            return response()->json(TestOnboarding::all());
        }

        $data['staff_members'] = User::where('active', 1)->get();
        $data['title'] = __('staff_members');
        $data['roles'] = Role::all(); // Assuming you have a Role model

        return view('admin.test_onboarding.manage', $data);
    }

    public function changeFlexpertStatus($id, $status)
    {
        $newStatus = ($status == 1) ? 'Y' : 'N';
        
        TestOnboarding::find($id)->update(['flexpert_status' => $newStatus]);

        // Additional checks or permissions can be added here if needed.

        return response()->json(['success' => true]);
    }
    public function __construct()
    {
        parent::__construct();

        if (get_option('access_tickets_to_none_staff_members') == 0 && !is_staff_member()) {
            return redirect()->route('admin.index');
        }

        $this->ticketsModel = new TicketsModel();
    }

    public function index($status = '', $userId = '')
    {
        close_setup_menu();

        if (!is_numeric($status)) {
            $status = '';
        }

        if (request()->ajax()) {
            if (!request()->post('filters_ticket_id')) {
                $tableParams = [
                    'status' => $status,
                    'userid' => $userId,
                ];
            } else {
                // request for other tickets when a single ticket is opened
                $tableParams = [
                    'userid' => request()->post('filters_userid'),
                    'where_not_ticket_id' => request()->post('filters_ticket_id'),
                ];
                if ($tableParams['userid'] == 0) {
                    unset($tableParams['userid']);
                    $tableParams['by_email'] = request()->post('filters_email');
                }
            }

            $this->app->get_table_data('tickets', $tableParams);
        }

        $data['chosen_ticket_status'] = $status;
        $data['weekly_tickets_opening_statistics'] = json_encode($this->ticketsModel->get_weekly_tickets_opening_statistics());
        $data['title'] = __('support_tickets');
        $data['statuses'] = $this->ticketsModel->get_ticket_status();
        $data['staff_deparments_ids'] = Departments::get_staff_departments(get_staff_user_id(), true);
        $data['departments'] = Departments::get();
        $data['priorities'] = $this->ticketsModel->get_priority();
        $data['services'] = $this->ticketsModel->get_service();
        $data['ticket_assignees'] = $this->ticketsModel->get_tickets_assignes_disctinct();
        $data['bodyclass'] = 'tickets-page';
        add_admin_tickets_js_assets();
        $data['default_tickets_list_statuses'] = hooks()->apply_filters('default_tickets_list_statuses', [1, 2, 4]);
        return view('admin.tickets.list', $data);
    }

    public function add($userId = false)
    {
        if (request()->post()) {
            $data = request()->all();
            $data['message'] = html_purify(request()->input('message', false));
            $id = $this->ticketsModel->add($data, get_staff_user_id());
            if ($id) {
                set_alert('success', __('new_ticket_added_successfully', $id));
                return redirect()->route('admin.tickets.ticket', ['id' => $id]);
            }
        }

        if ($userId !== false) {
            $data['userid'] = $userId;
            $data['client'] = Clients::find($userId);
        }

        $this->load->model('knowledge_base_model');
        $this->load->model('departments_model');

        $data['departments'] = Departments::get();
        $data['predefined_replies'] = $this->ticketsModel->get_predefined_reply();
        $data['priorities'] = $this->ticketsModel->get_priority();
        $data['services'] = $this->ticketsModel->get_service();
        $whereStaff = [];
        if (get_option('access_tickets_to_none_staff_members') == 0) {
            $whereStaff['is_not_staff'] = 0;
        }
        $data['staff'] = Staff::get('', $whereStaff);
        $data['articles'] = KnowledgeBase::get();
        $data['bodyclass'] = 'ticket';
        $data['title'] = __('new_ticket');

        if (request()->get('project_id') && request()->get('project_id') > 0) {
            $data['project_id'] = request()->get('project_id');
            $data['userid'] = get_client_id_by_project_id($data['project_id']);
            if (total_rows(db_prefix() . 'contacts', ['active' => 1, 'userid' => $data['userid']]) == 1) {
                $contact = Clients::get_contacts($data['userid']);
                if (isset($contact[0])) {
                    $data['contact'] = $contact[0];
                }
            }
        } elseif (request()->get('contact_id') && request()->get('contact_id') > 0 && request()->get('userid')) {
            $contact_id = request()->get('contact_id');
            if (total_rows(db_prefix() . 'contacts', ['active' => 1, 'id' => $contact_id]) == 1) {
                $contact = Clients::get_contact($contact_id);
                if ($contact) {
                    $data['contact'] = (array) $contact;
                }
            }
        }

        add_admin_tickets_js_assets();
        return view('admin.tickets.add', $data);
    }
    
    public function __construct()
    {
        parent::__construct();

        if (get_option('access_tickets_to_none_staff_members') == 0 && !is_staff_member()) {
            return redirect()->route('admin.index');
        }

        $this->ticketsModel = new TicketsModel();
    }

    public function delete($ticketId)
    {
        if (!$ticketId) {
            return redirect()->route('admin.tickets');
        }

        $response = $this->ticketsModel->delete($ticketId);

        if ($response == true) {
            set_alert('success', __('deleted', __('ticket')));
        } else {
            set_alert('warning', __('problem_deleting', __('ticket_lowercase')));
        }

        if (strpos(request()->server('HTTP_REFERER'), 'tickets/ticket') !== false) {
            return redirect()->route('admin.tickets');
        } else {
            return redirect(request()->server('HTTP_REFERER'));
        }
    }

    public function delete_attachment($id)
    {
        if (is_admin() || (!is_admin() && get_option('allow_non_admin_staff_to_delete_ticket_attachments') == '1')) {
            if (get_option('staff_access_only_assigned_departments') == 1 && !is_admin()) {
                $attachment = $this->ticketsModel->get_ticket_attachment($id);
                $ticket = $this->ticketsModel->get_ticket_by_id($attachment->ticketid);

                $staff_departments = Departments::get_staff_departments(get_staff_user_id(), true);

                if (!in_array($ticket->department, $staff_departments)) {
                    set_alert('danger', __('ticket_access_by_department_denied'));
                    return redirect()->route('admin.access_denied');
                }
            }

            $this->ticketsModel->delete_ticket_attachment($id);
        }

        return redirect(request()->server('HTTP_REFERER'));
    }

    public function update_staff_replying($ticketId, $userId = '')
    {
        if (request()->ajax()) {
            return response()->json(['success' => $this->ticketsModel->update_staff_replying($ticketId, $userId)]);
        }
    }

    public function check_staff_replying($ticketId)
    {
        if (request()->ajax()) {
            $ticket = $this->ticketsModel->get_staff_replying($ticketId);
            $isAnotherReplying = $ticket->staff_id_replying !== null && $ticket->staff_id_replying !== get_staff_user_id();
            
            return response()->json([
                'is_other_staff_replying' => $isAnotherReplying,
                'message' => $isAnotherReplying ? __('staff_is_currently_replying', get_staff_full_name($ticket->staff_id_replying)) : '',
            ]);
        }
    }

    public function ticket($id)
    {
        if (!$id) {
            return redirect()->route('admin.tickets.add');
        }

        $data['ticket'] = $this->ticketsModel->get_ticket_by_id($id);
        $data['merged_tickets'] = $this->ticketsModel->get_merged_tickets_by_primary_id($id);

        if (!$data['ticket']) {
            return blank_page(__('ticket_not_found'));
        }

        if (get_option('staff_access_only_assigned_departments') == 1) {
            if (!is_admin()) {
                $staff_departments = Departments::get_staff_departments(get_staff_user_id(), true);
                if (!in_array($data['ticket']->department, $staff_departments)) {
                    set_alert('danger', __('ticket_access_by_department_denied'));
                    return redirect()->route('admin.access_denied');
                }
            }
        }

        if (request()->post()) {
            $returnToTicketList = false;
            $data = request()->all();

            if (isset($data['ticket_add_response_and_back_to_list'])) {
                $returnToTicketList = true;
                unset($data['ticket_add_response_and_back_to_list']);
            }

            $data['message'] = html_purify(request()->input('message', false));
            $replyid = $this->ticketsModel->add_reply($data, $id, get_staff_user_id());

            if ($replyid) {
                set_alert('success', __('replied_to_ticket_successfully', $id));
            }

            if (!$returnToTicketList) {
                return redirect()->route('admin.tickets.ticket', ['id' => $id]);
            } else {
                set_ticket_open(0, $id);
                return redirect()->route('admin.tickets');
            }
        }

        $this->load->model('knowledge_base_model');
        $this->load->model('departments_model');

        $data['statuses'] = $this->ticketsModel->get_ticket_status();
        $data['statuses']['callback_translate'] = 'ticket_status_translate';

        $data['departments'] = Departments::get();
        $data['predefined_replies'] = $this->ticketsModel->get_predefined_reply();
        $data['priorities'] = $this->ticketsModel->get_priority();
        $data['services'] = $this->ticketsModel->get_service();
        $whereStaff = [];
        if (get_option('access_tickets_to_none_staff_members') == 0) {
            $whereStaff['is_not_staff'] = 0;
        }
        $data['staff'] = Staff::get('', $whereStaff);
        $data['articles'] = KnowledgeBase::get();
        $data['ticket_replies'] = $this->ticketsModel->get_ticket_replies($id);
        $data['bodyclass'] = 'top-tabs ticket single-ticket';
        $data['title'] = $data['ticket']->subject;
        add_admin_tickets_js_assets();
        return view('admin.tickets.single', $data);
    }
    public function __construct()
    {
        parent::__construct();

        if (get_option('access_tickets_to_none_staff_members') == 0 && !is_staff_member()) {
            return redirect()->route('admin.index');
        }

        $this->ticketsModel = new TicketsModel();
    }

    public function delete($ticketId)
    {
        if (!$ticketId) {
            return redirect()->route('admin.tickets');
        }

        $response = $this->ticketsModel->delete($ticketId);

        if ($response == true) {
            set_alert('success', __('deleted', __('ticket')));
        } else {
            set_alert('warning', __('problem_deleting', __('ticket_lowercase')));
        }

        if (strpos(request()->server('HTTP_REFERER'), 'tickets/ticket') !== false) {
            return redirect()->route('admin.tickets');
        } else {
            return redirect(request()->server('HTTP_REFERER'));
        }
    }

    public function delete_attachment($id)
    {
        if (is_admin() || (!is_admin() && get_option('allow_non_admin_staff_to_delete_ticket_attachments') == '1')) {
            if (get_option('staff_access_only_assigned_departments') == 1 && !is_admin()) {
                $attachment = $this->ticketsModel->get_ticket_attachment($id);
                $ticket = $this->ticketsModel->get_ticket_by_id($attachment->ticketid);

                $staff_departments = Departments::get_staff_departments(get_staff_user_id(), true);

                if (!in_array($ticket->department, $staff_departments)) {
                    set_alert('danger', __('ticket_access_by_department_denied'));
                    return redirect()->route('admin.access_denied');
                }
            }

            $this->ticketsModel->delete_ticket_attachment($id);
        }

        return redirect(request()->server('HTTP_REFERER'));
    }

    public function update_staff_replying($ticketId, $userId = '')
    {
        if (request()->ajax()) {
            return response()->json(['success' => $this->ticketsModel->update_staff_replying($ticketId, $userId)]);
        }
    }

    public function check_staff_replying($ticketId)
    {
        if (request()->ajax()) {
            $ticket = $this->ticketsModel->get_staff_replying($ticketId);
            $isAnotherReplying = $ticket->staff_id_replying !== null && $ticket->staff_id_replying !== get_staff_user_id();
            
            return response()->json([
                'is_other_staff_replying' => $isAnotherReplying,
                'message' => $isAnotherReplying ? __('staff_is_currently_replying', get_staff_full_name($ticket->staff_id_replying)) : '',
            ]);
        }
    }

    public function ticket($id)
    {
        if (!$id) {
            return redirect()->route('admin.tickets.add');
        }

        $data['ticket'] = $this->ticketsModel->get_ticket_by_id($id);
        $data['merged_tickets'] = $this->ticketsModel->get_merged_tickets_by_primary_id($id);

        if (!$data['ticket']) {
            return blank_page(__('ticket_not_found'));
        }

        if (get_option('staff_access_only_assigned_departments') == 1) {
            if (!is_admin()) {
                $staff_departments = Departments::get_staff_departments(get_staff_user_id(), true);
                if (!in_array($data['ticket']->department, $staff_departments)) {
                    set_alert('danger', __('ticket_access_by_department_denied'));
                    return redirect()->route('admin.access_denied');
                }
            }
        }

        if (request()->post()) {
            $returnToTicketList = false;
            $data = request()->all();

            if (isset($data['ticket_add_response_and_back_to_list'])) {
                $returnToTicketList = true;
                unset($data['ticket_add_response_and_back_to_list']);
            }

            $data['message'] = html_purify(request()->input('message', false));
            $replyid = $this->ticketsModel->add_reply($data, $id, get_staff_user_id());

            if ($replyid) {
                set_alert('success', __('replied_to_ticket_successfully', $id));
            }

            if (!$returnToTicketList) {
                return redirect()->route('admin.tickets.ticket', ['id' => $id]);
            } else {
                set_ticket_open(0, $id);
                return redirect()->route('admin.tickets');
            }
        }

        $this->load->model('knowledge_base_model');
        $this->load->model('departments_model');

        $data['statuses'] = $this->ticketsModel->get_ticket_status();
        $data['statuses']['callback_translate'] = 'ticket_status_translate';

        $data['departments'] = Departments::get();
        $data['predefined_replies'] = $this->ticketsModel->get_predefined_reply();
        $data['priorities'] = $this->ticketsModel->get_priority();
        $data['services'] = $this->ticketsModel->get_service();
        $whereStaff = [];
        if (get_option('access_tickets_to_none_staff_members') == 0) {
            $whereStaff['is_not_staff'] = 0;
        }
        $data['staff'] = Staff::get('', $whereStaff);
        $data['articles'] = KnowledgeBase::get();
        $data['ticket_replies'] = $this->ticketsModel->get_ticket_replies($id);
        $data['bodyclass'] = 'top-tabs ticket single-ticket';
        $data['title'] = $data['ticket']->subject;
        add_admin_tickets_js_assets();
        return view('admin.tickets.single', $data);
    }
    public function __construct()
    {
        parent::__construct();
        $this->ticketsModel = new TicketsModel();
    }

    public function edit_message()
    {
        if (request()->post()) {
            $data = request()->post();
            $data['data'] = html_purify(request()->input('data', false));

            if ($data['type'] == 'reply') {
                TicketsModel::where('id', $data['id'])
                    ->update(['message' => $data['data']]);
            } elseif ($data['type'] == 'ticket') {
                TicketsModel::where('ticketid', $data['id'])
                    ->update(['message' => $data['data']]);
            }

            if (TicketsModel::affected_rows() > 0) {
                set_alert('success', __('ticket_message_updated_successfully'));
            }

            return redirect()->route('admin.tickets.ticket', ['id' => $data['main_ticket']]);
        }
    }

    public function delete_ticket_reply($ticketId, $replyId)
    {
        if (!$replyId) {
            return redirect()->route('admin.tickets');
        }

        $response = $this->ticketsModel->delete_ticket_reply($ticketId, $replyId);

        if ($response == true) {
            set_alert('success', __('deleted', __('ticket_reply')));
        } else {
            set_alert('warning', __('problem_deleting', __('ticket_reply')));
        }

        return redirect()->route('admin.tickets.ticket', ['id' => $ticketId]);
    }

    public function change_status_ajax($id, $status)
    {
        if (request()->ajax()) {
            return response()->json($this->ticketsModel->change_ticket_status($id, $status));
        }
    }

    public function update_single_ticket_settings()
    {
        if (request()->post()) {
            session()->flash('active_tab', true);
            session()->flash('active_tab_settings', true);

            if (request()->post('merge_ticket_ids') !== 0) {
                $ticketsToMerge = explode(',', request()->post('merge_ticket_ids'));

                $alreadyMergedTickets = $this->ticketsModel->get_already_merged_tickets($ticketsToMerge);
                if (count($alreadyMergedTickets) > 0) {
                    return response()->json([
                        'success' => false,
                        'message' => __('cannot_merge_tickets_with_ids', implode(',', $alreadyMergedTickets)),
                    ]);
                }
            }

            $success = $this->ticketsModel->update_single_ticket_settings(request()->post());

            if ($success) {
                session()->flash('active_tab', true);
                session()->flash('active_tab_settings', true);

                if (get_option('staff_access_only_assigned_departments') == 1) {
                    $ticket = $this->ticketsModel->get_ticket_by_id(request()->post('ticketid'));
                    $staff_departments = Departments::get_staff_departments(get_staff_user_id(), true);

                    if (!in_array($ticket->department, $staff_departments) && !is_admin()) {
                        set_alert('success', __('ticket_settings_updated_successfully_and_reassigned', $ticket->department_name));
                        return response()->json([
                            'success' => $success,
                            'department_reassigned' => true,
                        ]);
                    }
                }

                set_alert('success', __('ticket_settings_updated_successfully'));
            }

            return response()->json([
                'success' => $success,
            ]);
        }
    }

    // Priorities
    public function priorities()
    {
        if (!is_admin()) {
            access_denied('Ticket Priorities');
        }

        $data['priorities'] = $this->ticketsModel->get_priority();
        $data['title'] = __('ticket_priorities');

        return view('admin.tickets.priorities.manage', $data);
    }

    public function priority()
    {
        if (!is_admin()) {
            access_denied('Ticket Priorities');
        }

        if (request()->post()) {
            if (!request()->post('id')) {
                $id = $this->ticketsModel->add_priority(request()->post());

                if ($id) {
                    set_alert('success', __('added_successfully', __('ticket_priority')));
                }
            } else {
                $data = request()->post();
                $id = $data['id'];
                unset($data['id']);

                $success = $this->ticketsModel->update_priority($data, $id);

                if ($success) {
                    set_alert('success', __('updated_successfully', __('ticket_priority')));
                }
            }

            return response()->json([]);
        }
    }

    public function delete_priority($id)
    {
        if (!is_admin()) {
            access_denied('Ticket Priorities');
        }

        if (!$id) {
            return redirect()->route('admin.tickets.priorities');
        }

        $response = $this->ticketsModel->delete_priority($id);

        if (is_array($response) && isset($response['referenced'])) {
            set_alert('warning', __('is_referenced', __('ticket_priority_lowercase')));
        } elseif ($response == true) {
            set_alert('success', __('deleted', __('ticket_priority')));
        } else {
            set_alert('warning', __('problem_deleting', __('ticket_priority_lowercase')));
        }

        return redirect()->route('admin.tickets.priorities');
    }

    public function predefined_replies()
    {
        if (!is_admin()) {
            access_denied('Predefined Replies');
        }

        if (request()->ajax()) {
            $aColumns = ['name'];
            $sIndexColumn = 'id';
            $sTable = db_prefix() . 'tickets_predefined_replies';

            $result = data_tables_init($aColumns, $sIndexColumn, $sTable, [], [], ['id']);
            $output = $result['output'];
            $rResult = $result['rResult'];

            foreach ($rResult as $aRow) {
                $row = [];

                for ($i = 0; $i < count($aColumns); $i++) {
                    $_data = $aRow[$aColumns[$i]];

                    if ($aColumns[$i] == 'name') {
                        $_data = '<a href="' . route('admin.tickets.predefined_reply', ['id' => $aRow['id']]) . '">' . $_data . '</a>';
                    }

                    $row[] = $_data;
                }

                $options = '<div class="tw-flex tw-items-center tw-space-x-3">';
                $options .= '<a href="' . route('admin.tickets.predefined_reply', ['id' => $aRow['id']]) . '" class="tw-text-neutral-500 hover:tw-text-neutral-700 focus:tw-text-neutral-700">
                    <i class="fa-regular fa-pen-to-square fa-lg"></i>
                </a>';

                $options .= '<a href="' . route('admin.tickets.delete_predefined_reply', ['id' => $aRow['id']]) . '"
                class="tw-mt-px tw-text-neutral-500 hover:tw-text-neutral-700 focus:tw-text-neutral-700 _delete">
                    <i class="fa-regular fa-trash-can fa-lg"></i>
                </a>';
                $options .= '</div>';
                $row[] = $options;

                $output['aaData'][] = $row;
            }

            return response()->json($output);
        }

        $data['title'] = __('predefined_replies');

        return view('admin.tickets.predefined_replies.manage', $data);
    }
    
    public function status()
    {
        if (!is_admin()) {
            access_denied('Ticket Statuses');
        }

        if (request()->post()) {
            if (!request()->post('id')) {
                $id = $this->ticketsModel->add_ticket_status(request()->post());
                if ($id) {
                    set_alert('success', __('added_successfully', __('ticket_status')));
                }
            } else {
                $data = request()->post();
                $id = $data['id'];
                unset($data['id']);
                $success = $this->ticketsModel->update_ticket_status($data, $id);
                if ($success) {
                    set_alert('success', __('updated_successfully', __('ticket_status')));
                }
            }
            return response()->json(['success' => true]);
        }
    }

    public function delete_ticket_status($id)
    {
        if (!is_admin()) {
            access_denied('Ticket Statuses');
        }

        if (!$id) {
            return redirect()->route('admin.tickets.statuses');
        }

        $response = $this->ticketsModel->delete_ticket_status($id);

        if (is_array($response) && isset($response['default'])) {
            set_alert('warning', __('cant_delete_default', __('ticket_status_lowercase')));
        } elseif (is_array($response) && isset($response['referenced'])) {
            set_alert('danger', __('is_referenced', __('ticket_status_lowercase')));
        } elseif ($response == true) {
            set_alert('success', __('deleted', __('ticket_status')));
        } else {
            set_alert('warning', __('problem_deleting', __('ticket_status_lowercase')));
        }

        return redirect()->route('admin.tickets.statuses');
    }

    public function services()
    {
        if (!is_admin()) {
            access_denied('Ticket Services');
        }

        if (request()->ajax()) {
            $aColumns = [
                'serviceid',
                'name',
            ];
            $sIndexColumn = 'serviceid';
            $sTable = db_prefix() . 'services'; // Replace with the appropriate table name
            $result = data_tables_init($aColumns, $sIndexColumn, $sTable, [], [], [
                'serviceid',
            ]);
            $output = $result['output'];
            $rResult = $result['rResult'];
            foreach ($rResult as $aRow) {
                $row = [];
                for ($i = 0; $i < count($aColumns); $i++) {
                    $_data = $aRow[$aColumns[$i]];
                    if ($aColumns[$i] == 'name') {
                        $_data = '<a href="#" onclick="edit_service(this,' . $aRow['serviceid'] . ');return false" data-name="' . $aRow['name'] . '">' . $_data . '</a>';
                    }
                    $row[] = $_data;
                }
                $options = icon_btn('#', 'fa-regular fa-pen-to-square', 'btn-default', [
                    'data-name' => $aRow['name'],
                    'onclick' => 'edit_service(this,' . $aRow['serviceid'] . '); return false;',
                ]);
                $row[] = $options .= icon_btn('tickets/delete_service/' . $aRow['serviceid'], 'fa fa-remove', 'btn-danger _delete');
                $output['aaData'][] = $row;
            }
            return response()->json($output);
        }

        $data['title'] = __('services');
        return view('admin.tickets.services.manage', $data);
    }

    public function service($id = '')
    {
        if (!is_admin() && get_option('staff_members_save_tickets_predefined_replies') == '0') {
            access_denied('Ticket Services');
        }

        if (request()->post()) {
            $postData = request()->post();
            if (!$postData['id']) {
                $requestFromTicketArea = isset($postData['ticket_area']);
                if (isset($postData['ticket_area'])) {
                    unset($postData['ticket_area']);
                }
                $id = $this->ticketsModel->add_service($postData);
                if (!$requestFromTicketArea) {
                    if ($id) {
                        set_alert('success', __('added_successfully', __('service')));
                    }
                } else {
                    return response()->json(['success' => $id ? true : false, 'id' => $id, 'name' => $postData['name']]);
                }
            } else {
                $id = $postData['id'];
                unset($postData['id']);
                $success = $this->ticketsModel->update_service($postData, $id);
                if ($success) {
                    set_alert('success', __('updated_successfully', __('service')));
                }
            }
            return response()->json(['success' => true]);
        }
    }

    public function delete_service($id)
    {
        if (!is_admin()) {
            access_denied('Ticket Services');
        }

        if (!$id) {
            return redirect()->route('admin.tickets.services');
        }

        $response = $this->ticketsModel->delete_service($id);

        if (is_array($response) && isset($response['referenced'])) {
            set_alert('warning', __('is_referenced', __('service_lowercase')));
        } elseif ($response == true) {
            set_alert('success', __('deleted', __('service')));
        } else {
            set_alert('warning', __('problem_deleting', __('service_lowercase')));
        }

        return redirect()->route('admin.tickets.services');
    }

    public function block_sender()
    {
        if (request()->post()) {
            $this->load->model('spam_filters_model');
            $sender = request()->post('sender');
            $success = $this->spam_filters_model->add(['type' => 'sender', 'value' => $sender], 'tickets');
            if ($success) {
                set_alert('success', __('sender_blocked_successfully'));
            }
        }
    }

    public function bulk_action()
    {
        hooks()->do_action('before_do_bulk_action_for_tickets');
        if (request()->post()) {
            $ids = request()->post('ids');
            $isAdmin = is_admin();

            if (!is_array($ids)) {
                return;
            }

            if (request()->post('merge_tickets')) {
                $primaryTicket = request()->post('primary_ticket');
                $status = request()->post('primary_ticket_status');

                if ($this->ticketsModel->is_merged($primaryTicket)) {
                    set_alert('warning', __('cannot_merge_into_merged_ticket'));
                    return;
                }

                $totalMerged = $this->ticketsModel->merge($primaryTicket, $status, $ids);
            } elseif (request()->post('mass_delete')) {
                $totalDeleted = 0;
                if ($isAdmin) {
                    foreach ($ids as $id) {
                        if ($this->ticketsModel->delete($id)) {
                            $totalDeleted++;
                        }
                    }
                }
            } else {
                $status = request()->post('status');
                $department = request()->post('department');
                $service = request()->post('service');
                $priority = request()->post('priority');
                $tags = request()->post('tags');

                foreach ($ids as $id) {
                    if ($status) {
                        $this->db->where('ticketid', $id);
                        $this->db->update(db_prefix() . 'tickets', [
                            'status' => $status,
                        ]);
                    }
                    if ($department) {
                        $this->db->where('ticketid', $id);
                        $this->db->update(db_prefix() . 'tickets', [
                            'department' => $department,
                        ]);
                    }
                    if ($priority) {
                        $this->db->where('ticketid', $id);
                        $this->db->update(db_prefix() . 'tickets', [
                            'priority' => $priority,
                        ]);
                    }

                    if ($service) {
                        $this->db->where('ticketid', $id);
                        $this->db->update(db_prefix() . 'tickets', [
                            'service' => $service,
                        ]);
                    }
                    if ($tags) {
                        handle_tags_save($tags, $id, 'ticket');
                    }
                }
            }

            if (request()->post('mass_delete')) {
                set_alert('success', __('total_tickets_deleted', $totalDeleted));
            } elseif (request()->post('merge_tickets') && $totalMerged > 0) {
                set_alert('success', __('tickets_merged'));
            }
        }
    }
}
