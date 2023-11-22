<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\View;
use App\Models\ProjectsModel;
use App\Models\CurrenciesModel;
use App\Models\ExpensesModel;
use App\Models\PaymentModesModel;
use Illuminate\Support\Facades\Lang;

class ProjectsController extends Controller
{
    protected $projectsModel;
    protected $currenciesModel;
    protected $expensesModel;
    protected $paymentModesModel;

    public function __construct()
    {
        parent::__construct();
        $this->projectsModel = new ProjectsModel();
        $this->currenciesModel = new CurrenciesModel();
        $this->expensesModel = new ExpensesModel();
        $this->paymentModesModel = new PaymentModesModel();
    }

    public function index()
    {
        close_setup_menu();
        $statuses = $this->projectsModel->getProjectStatuses();
        $title = Lang::get('projects');
        return View::make('admin.projects.manage', compact('statuses', 'title'));
    }

    public function table($clientid = '')
    {
        // Assuming you have a method in a trait or directly in the controller for table data
        return $this->getProjectTableData($clientid);
    }

    public function staffProjects()
    {
        // Assuming you have a method in a trait or directly in the controller for staff projects data
        return $this->getStaffProjectsTableData();
    }

    public function expenses($id)
    {
        $paymentModes = $this->paymentModesModel->get([], true);
        // Assuming you have a method in a trait or directly in the controller for project expenses data
        return $this->getProjectExpensesTableData($id, compact('paymentModes'));
    }

    // Add any additional methods as needed for table data retrieval
    public function addExpense()
    {
        if (Input::post()) {
            $expensesModel = new ExpensesModel();
            $id = $expensesModel->add(Input::post());
            if ($id) {
                Session::flash('alert', ['success', Lang::get('added_successfully', ['expense'])]);
                return response()->json([
                    'url' => admin_url('projects/view/' . Input::post('project_id') . '/?group=project_expenses'),
                    'expenseid' => $id,
                ]);
            }
            return response()->json([
                'url' => admin_url('projects/view/' . Input::post('project_id') . '/?group=project_expenses'),
            ]);
        }
    }

    public function project($id = '')
    {
        if (!$this->staffCan('edit', 'projects') && !$this->staffCan('create', 'projects')) {
            abort(403, 'Access Denied');
        }

        if (Input::post()) {
            $data = Input::post();
            $data['description'] = e(Input::post('description', false));
            if ($id == '') {
                if (!$this->staffCan('create', 'projects')) {
                    abort(403, 'Access Denied');
                }
                $id = $this->projectsModel->add($data);
                if ($id) {
                    Session::flash('alert', ['success', Lang::get('added_successfully', ['project'])]);
                    return Redirect::to(admin_url('projects/view/' . $id));
                }
            } else {
                if (!$this->staffCan('edit', 'projects')) {
                    abort(403, 'Access Denied');
                }
                $success = $this->projectsModel->update($data, $id);
                if ($success) {
                    Session::flash('alert', ['success', Lang::get('updated_successfully', ['project'])]);
                }
                return Redirect::to(admin_url('projects/view/' . $id));
            }
        }
        if ($id == '') {
            $title = Lang::get('add_new', ['project_lowercase']);
            $autoSelectBillingType = $this->projectsModel->getMostUsedBillingType();

            if (Input::get('via_estimate_id')) {
                $estimatesModel = new EstimatesModel();
                $estimate = $estimatesModel->get(Input::get('via_estimate_id'));
            }
        } else {
            $project = $this->projectsModel->get($id);
            $project->settings->available_features = unserialize($project->settings->available_features);

            $projectMembers = $this->projectsModel->getProjectMembers($id);
            $title = Lang::get('edit', ['project']);
        }

        if (Input::get('customer_id')) {
            $customerId = Input::get('customer_id');
        }

        $lastProjectSettings = $this->projectsModel->getLastProjectSettings();

        if (count($lastProjectSettings)) {
            $key = array_search('available_features', array_column($lastProjectSettings, 'name'));
            $lastProjectSettings[$key]['value'] = unserialize($lastProjectSettings[$key]['value']);
        }

        $settings = $this->projectsModel->getSettings();
        $statuses = $this->projectsModel->getProjectStatuses();
        $staff = $this->staffModel->get(['active' => 1, 'FLEXPERT' => 'Y']);

        $title = $title;
        return View::make('admin.projects.project', compact('title', 'autoSelectBillingType', 'estimate', 'project', 'projectMembers', 'customerId', 'lastProjectSettings', 'settings', 'statuses', 'staff'));
    }

    public function gantt()
    {
        $title = Lang::get('project_gantt');

        $selectedStatuses = [];
        $selectedMember = null;
        $statuses = $this->projectsModel->getProjectStatuses();

        $appliedStatuses = Input::get('status');
        $appliedMember = Input::get('member');

        $allStatusesIds = [];
        foreach ($statuses as $status) {
            if (
                !isset($status['filter_default'])
                || (isset($status['filter_default']) && $status['filter_default'])
                && !$appliedStatuses
            ) {
                $selectedStatuses[] = $status['id'];
            } elseif ($appliedStatuses) {
                if (in_array($status['id'], $appliedStatuses)) {
                    $selectedStatuses[] = $status['id'];
                }
            } else {
                // All statuses
                $allStatusesIds[] = $status['id'];
            }
        }

        if (count($selectedStatuses) == 0) {
            $selectedStatuses = $allStatusesIds;
        }

        $selectedMember = $appliedMember;
        $projectMembers = $this->projectsModel->getDistinctProjectsMembers();

        $ganttData = (new AllProjectsGantt([
            'status' => $selectedStatuses,
            'member' => $selectedMember,
        ]))->get();

        return View::make('admin.projects.gantt', compact('title', 'statuses', 'selectedStatuses', 'selectedMember', 'projectMembers', 'ganttData'));
    }
    
    public function view($id)
    {
        if ($this->staffCan('view', 'projects') || $this->projectsModel->isMember($id)) {
            close_setup_menu();
            $project = $this->projectsModel->get($id);

            if (!$project) {
                return abort(404, Lang::get('project_not_found'));
            }

            $project->settings->available_features = unserialize($project->settings->available_features);
            $data['statuses'] = $this->projectsModel->getProjectStatuses();

            $group = !Input::get('group') ? 'project_overview' : Input::get('group');

            // Unable to load the requested file: admin/projects/project_tasks#.php - FIX
            if (strpos($group, '#') !== false) {
                $group = str_replace('#', '', $group);
            }

            $data['tabs'] = get_project_tabs_admin();
            $data['tab'] = $this->app_tabs->filterTab($data['tabs'], $group);

            if (!$data['tab']) {
                return abort(404);
            }

            $data['payment_modes'] = DB::table('payment_modes')->get();

            $data['project'] = $project;
            $data['currency'] = $this->projectsModel->getCurrency($id);

            $data['project_total_logged_time'] = $this->projectsModel->totalLoggedTime($id);

            $data['staff'] = $this->staffModel->get(['active' => 1]);
            $percent = $this->projectsModel->calcProgress($id);
            $data['members'] = $this->projectsModel->getProjectMembers($id);
            foreach ($data['members'] as $key => $member) {
                $data['members'][$key]['total_logged_time'] = 0;
                $memberTimesheets = $this->tasksModel->getUniqueMemberLoggedTaskIds($member['staff_id'], ' AND task_id IN (SELECT id FROM ' . db_prefix() . 'tasks WHERE rel_type="project" AND rel_id="' . DB::table('projects')->where('id', $id)->value('id') . '")');

                foreach ($memberTimesheets as $memberTask) {
                    $data['members'][$key]['total_logged_time'] += $this->tasksModel->calcTaskTotalTime($memberTask->task_id, ' AND staff_id=' . $member['staff_id']);
                }
            }
            $data['bodyclass'] = '';

            $this->appScripts->add(
                'projects-js',
                asset($this->appScripts->coreFile('assets/js', 'projects.js')) . '?v=' . $this->appScripts->coreVersion(),
                'admin',
                ['app-js', 'jquery-comments-js', 'frappe-gantt-js', 'circle-progress-js']
            );

            if ($group == 'project_overview') {
                $data['project_total_days'] = round((strtotime($data['project']->deadline . ' 00:00') - strtotime($data['project']->start_date . ' 00:00')) / 3600 / 24);
                $data['project_days_left'] = $data['project_total_days'];
                $data['project_time_left_percent'] = 100;
                if ($data['project']->deadline) {
                    if (strtotime($data['project']->start_date . ' 00:00') < time() && strtotime($data['project']->deadline . ' 00:00') > time()) {
                        $data['project_days_left'] = round((strtotime($data['project']->deadline . ' 00:00') - time()) / 3600 / 24);
                        $data['project_time_left_percent'] = $data['project_days_left'] / $data['project_total_days'] * 100;
                        $data['project_time_left_percent'] = round($data['project_time_left_percent'], 2);
                    }
                    if (strtotime($data['project']->deadline . ' 00:00') < time()) {
                        $data['project_days_left'] = 0;
                        $data['project_time_left_percent'] = 0;
                    }
                }

                $__totalWhereTasks = 'rel_type = "project" AND rel_id=' . $id;
                if (!$this->staffCan('view', 'tasks')) {
                    $__totalWhereTasks .= ' AND ' . db_prefix() . 'tasks.id IN (SELECT taskid FROM ' . db_prefix() . 'task_assigned WHERE staffid = ' . get_staff_user_id() . ')';

                    if (get_option('show_all_tasks_for_project_member') == 1) {
                        $__totalWhereTasks .= ' AND (rel_type="project" AND rel_id IN (SELECT project_id FROM ' . db_prefix() . 'project_members WHERE staff_id=' . get_staff_user_id() . '))';
                    }
                }

                $__totalWhereTasks = hooks()->applyFilters('admin_total_project_tasks_where', $__totalWhereTasks, $id);

                $where = ($__totalWhereTasks == '' ? '' : $__totalWhereTasks . ' AND ') . 'status != ' . Tasks_model::STATUS_COMPLETE;

                $data['tasks_not_completed'] = totalRows(db_prefix() . 'tasks', $where);
                $totalTasks = totalRows(db_prefix() . 'tasks', $__totalWhereTasks);
                $data['total_tasks'] = $totalTasks;

                $where = ($__totalWhereTasks == '' ? '' : $__totalWhereTasks . ' AND ') . 'status = ' . Tasks_model::STATUS_COMPLETE . ' AND rel_type="project" AND rel_id="' . $id . '"';

                $data['tasks_completed'] = totalRows(db_prefix() . 'tasks', $where);

                $data['tasks_not_completed_progress'] = ($totalTasks > 0 ? number_format(($data['tasks_completed'] * 100) / $totalTasks, 2) : 0);
                $data['tasks_not_completed_progress'] = round($data['tasks_not_completed_progress'], 2);

                @$percentCircle = $percent / 100;
                $data['percent_circle'] = $percentCircle;

                $data['project_overview_chart'] = (new HoursOverviewChart(
                    $id,
                    (Input::get('overview_chart') ? Input::get('overview_chart') : 'this_week')
                ))->get();
            } elseif ($group == 'project_invoices') {
                $this->load->model('invoices_model');

                $data['invoiceid'] = '';
                $data['status'] = '';
                $data['custom_view'] = '';

                $data['invoices_years'] = DB::table('invoices')->distinct()->select(DB::raw('YEAR(invoice_date) as year'))->get();
                $data['invoices_sale_agents'] = DB::table('invoices')->distinct()->select('sale_agent')->get();
                $data['invoices_statuses'] = DB::table('invoices_statuses')->get();
            } elseif ($group == 'project_gantt') {
                $ganttType = (!Input::get('gantt_type') ? 'milestones' : Input::get('gantt_type'));
                $taskStatus = (!Input::get('gantt_task_status') ? null : Input::get('gantt_task_status'));
                $data['gantt_data'] = (new Gantt($id, $ganttType))->forTaskStatus($taskStatus)->get();
            } elseif ($group == 'project_milestones') {
                $data['bodyclass'] .= 'project-milestones ';
                $data['milestones_exclude_completed_tasks'] = Input::get('exclude_completed') && Input::get('exclude_completed') == 'yes' || !Input::get('exclude_completed');

                $data['total_milestones'] = totalRows(db_prefix() . 'milestones', ['project_id' => $id]);
                $data['milestones_found'] = $data['total_milestones'] > 0 || (!$data['total_milestones'] && totalRows(db_prefix() . 'tasks', ['rel_id' => $id, 'rel_type' => 'project', 'milestone' => 0]) > 0);
            } elseif ($group == 'project_files') {
                $data['files'] = $this->projectsModel->getFiles($id);
            } elseif ($group == 'project_expenses') {
                $data['taxes'] = DB::table('taxes')->get();
                $data['expense_categories'] = DB::table('expense_categories')->get();
                $data['currencies'] = (new CurrenciesModel())->get();
            } elseif ($group == 'project_activity') {
                $data['activity'] = $this->projectsModel->getActivity($id);
            } elseif ($group == 'project_notes') {
                $data['staff_notes'] = $this->projectsModel->getStaffNotes($id);
            } elseif ($group == 'project_contracts') {
                $this->load->model('contracts_model');
                $data['contract_types'] = DB::table('contract_types')->get();
                $data['years'] = DB::table('contracts')->distinct()->select(DB::raw('YEAR(contract_start_date) as year'))->get();
            } elseif ($group == 'project_estimates') {
                $this->load->model('estimates_model');
                $data['estimates_years'] = DB::table('estimates')->distinct()->select(DB::raw('YEAR(date) as year'))->get();
                $data['estimates_sale_agents'] = DB::table('estimates')->distinct()->select('sale_agent')->get();
                $data['estimate_statuses'] = DB::table('estimates_statuses')->get();
                $data['estimateid'] = '';
                $data['switch_pipeline'] = '';
            } elseif ($group == 'project_proposals') {
                $this->load->model('proposals_model');
                $data['proposal_statuses'] = DB::table('proposals_statuses')->get();
                $data['proposals_sale_agents'] = DB::table('proposals')->distinct()->select('sale_agent')->get();
                $data['years'] = DB::table('proposals')->distinct()->select(DB::raw('YEAR(date) as year'))->get();
                $data['proposal_id'] = '';
                $data['switch_pipeline'] = '';
            } elseif ($group == 'project_tickets') {
                $data['chosen_ticket_status'] = '';
                $this->load->model('tickets_model');
                $data['ticket_assignees'] = $this->tickets_model->get_tickets_assignes_disctinct();

                $this->load->model('departments_model');
                $data['staff_deparments_ids']          = $this->departments_model->get_staff_departments(get_staff_user_id(), true);
                $data['default_tickets_list_statuses'] = hooks()->apply_filters('default_tickets_list_statuses', [1, 2, 4]);
            } elseif ($group == 'project_timesheets') {
                // Tasks are used in the timesheet dropdown
                // Completed tasks are excluded from this list because you can't add timesheet on completed task.
                $data['tasks']                = $this->projects_model->get_tasks($id, 'status != ' . Tasks_model::STATUS_COMPLETE . ' AND billed=0');
                $data['timesheets_staff_ids'] = $this->projects_model->get_distinct_tasks_timesheets_staff($id);
            }

            // Discussions
            if ($this->input->get('discussion_id')) {
                $data['discussion_user_profile_image_url'] = staff_profile_image_url(get_staff_user_id());
                $data['discussion']                        = $this->projects_model->get_discussion($this->input->get('discussion_id'), $id);
                $data['current_user_is_admin']             = is_admin();
            }

            $data['percent'] = $percent;

            $this->app_scripts->add('circle-progress-js', 'assets/plugins/jquery-circle-progress/circle-progress.min.js');

            $other_projects       = [];
            $other_projects_where = 'id != ' . $id;

            $statuses = $this->projects_model->get_project_statuses();

            $other_projects_where .= ' AND (';
            foreach ($statuses as $status) {
                if (isset($status['filter_default']) && $status['filter_default']) {
                    $other_projects_where .= 'status = ' . $status['id'] . ' OR ';
                }
            }

            $other_projects_where = rtrim($other_projects_where, ' OR ');

            $other_projects_where .= ')';

            if (!staff_can('view', 'projects')) {
                $other_projects_where .= ' AND ' . db_prefix() . 'projects.id IN (SELECT project_id FROM ' . db_prefix() . 'project_members WHERE staff_id=' . get_staff_user_id() . ')';
            }

            $data['other_projects'] = $this->projects_model->get('', $other_projects_where);
            $data['title']          = $data['project']->name;
            $data['bodyclass'] .= 'project invoices-total-manual estimates-total-manual';
            $data['project_status'] = get_project_status_by_id($project->status);

            $this->load->view('admin/projects/view', $data);
        } else {
            access_denied('Project View');
        }
    }

    public function markAs(Request $request)
    {
        $success = false;
        $message = '';

        if ($request->ajax()) {
            if (staff_can('create', 'projects') || staff_can('edit', 'projects')) {
                $status = get_project_status_by_id($request->post('status_id'));

                $message = _l('project_marked_as_failed', $status['name']);
                $success = $this->projectsModel->mark_as($request->post());

                if ($success) {
                    $message = _l('project_marked_as_success', $status['name']);
                }
            }
        }

        return response()->json([
            'success' => $success,
            'message' => $message,
        ]);
    }

    public function file($id, $project_id)
    {
        $data['discussion_user_profile_image_url'] = staff_profile_image_url(get_staff_user_id());
        $data['current_user_is_admin']             = is_admin();

        $data['file'] = $this->projectsModel->get_file($id, $project_id);

        if (!$data['file']) {
            abort(404);
        }

        return view('admin.projects._file', $data);
    }

    public function updateFileData(Request $request)
    {
        if ($request->isMethod('post')) {
            $this->projectsModel->update_file_data($request->post());
        }
    }

    public function addExternalFile(Request $request)
    {
        if ($request->isMethod('post')) {
            $data = [
                'project_id'          => $request->post('project_id'),
                'files'               => $request->post('files'),
                'external'            => $request->post('external'),
                'visible_to_customer' => ($request->post('visible_to_customer') == 'true' ? 1 : 0),
                'staffid'             => get_staff_user_id(),
            ];

            $this->projectsModel->add_external_file($data);
        }
    }

    public function downloadAllFiles($id)
    {
        if ($this->projectsModel->is_member($id) || staff_can('view', 'projects')) {
            $files = $this->projectsModel->get_files($id);

            if (count($files) == 0) {
                session()->flash('warning', _l('no_files_found'));
                return redirect()->route('admin.projects.view', ['id' => $id, 'group' => 'project_files']);
            }

            $path = get_upload_path_by_type('project') . $id;
            $zip = new \ZipArchive();
            $zipFileName = slug_it(get_project_name_by_id($id)) . '-files.zip';
            $zip->open($zipFileName, \ZipArchive::CREATE);

            foreach ($files as $file) {
                $filePath = $path . '/' . $file['file_name'];
                $originalFileName = $file['original_file_name'] ?: basename($filePath);
                $zip->addFile($filePath, $originalFileName);
            }

            $zip->close();

            return response()->download($zipFileName)->deleteFileAfterSend(true);
        }
    }
    public function exportProjectData($id)
    {
        if (staff_can('create', 'projects')) {
            app_pdf('project-data', LIBSPATH . 'pdf/Project_data_pdf', $id);
        }
    }

    public function updateTaskMilestone(Request $request)
    {
        if ($request->isMethod('post')) {
            $this->projectsModel->update_task_milestone($request->post());
        }
    }

    public function updateMilestonesOrder(Request $request)
    {
        if ($post_data = $request->post()) {
            $this->projectsModel->update_milestones_order($post_data);
        }
    }

    public function pinAction($project_id)
    {
        $this->projectsModel->pin_action($project_id);
        return redirect()->back();
    }

    public function addEditMembers($project_id)
    {
        if (staff_can('edit', 'projects')) {
            $this->projectsModel->add_edit_members($request->post(), $project_id);
            return redirect()->back();
        }
    }

    public function discussions($project_id)
    {
        if ($this->projectsModel->is_member($project_id) || staff_can('view', 'projects')) {
            if (request()->ajax()) {
                $this->app->get_table_data('project_discussions', [
                    'project_id' => $project_id,
                ]);
            }
        }
    }

    public function discussion($id = '')
    {
        if (request()->isMethod('post')) {
            $message = '';
            $success = false;

            if (!request()->post('id')) {
                $id = $this->projectsModel->add_discussion(request()->post());
                if ($id) {
                    $success = true;
                    $message = _l('added_successfully', _l('project_discussion'));
                }
                return response()->json([
                    'success' => $success,
                    'message' => $message,
                ]);
            } else {
                $data = request()->post();
                $id   = $data['id'];
                unset($data['id']);
                $success = $this->projectsModel->edit_discussion($data, $id);
                if ($success) {
                    $message = _l('updated_successfully', _l('project_discussion'));
                }
                return response()->json([
                    'success' => $success,
                    'message' => $message,
                ]);
            }
        }
    }

    public function getDiscussionComments($id, $type)
    {
        return response()->json($this->projectsModel->get_discussion_comments($id, $type));
    }

    public function addDiscussionComment($discussion_id, $type)
    {
        return response()->json($this->projectsModel->add_discussion_comment(
            request()->post(null, false),
            $discussion_id,
            $type
        ));
    }

    public function updateDiscussionComment()
    {
        return response()->json($this->projectsModel->update_discussion_comment(request()->post(null, false)));
    }

    public function deleteDiscussionComment($id)
    {
        return response()->json($this->projectsModel->delete_discussion_comment($id));
    }

    public function deleteDiscussion($id)
    {
        $success = false;
        if (staff_can('delete', 'projects')) {
            $success = $this->projectsModel->delete_discussion($id);
        }
        $alert_type = 'warning';
        $message    = _l('project_discussion_failed_to_delete');
        if ($success) {
            $alert_type = 'success';
            $message    = _l('project_discussion_deleted');
        }
        return response()->json([
            'alert_type' => $alert_type,
            'message'    => $message,
        ]);
    }

    public function changeMilestoneColor()
    {
        if (request()->isMethod('post')) {
            $this->projectsModel->update_milestone_color(request()->post());
        }
    }

    public function uploadFile($project_id)
    {
        handle_project_file_uploads($project_id);
    }

    public function changeFileVisibility($id, $visible)
    {
        if (request()->ajax()) {
            $this->projectsModel->change_file_visibility($id, $visible);
        }
    }

    public function changeActivityVisibility($id, $visible)
    {
        if (staff_can('create', 'projects')) {
            if (request()->ajax()) {
                $this->projectsModel->change_activity_visibility($id, $visible);
            }
        }
    }

    public function removeFile($project_id, $id)
    {
        $this->projectsModel->remove_file($id);
        return redirect()->route('admin.projects.view', ['id' => $project_id, 'group' => 'project_files']);
    }
    
    public function milestonesKanban()
    {
        $data['milestonesExcludeCompletedTasks'] = request()->get('exclude_completed_tasks') && request()->get('exclude_completed_tasks') == 'yes';

        $data['project_id'] = request()->get('project_id');
        $data['milestones'] = [];

        $data['milestones'][] = [
            'name'              => _l('milestones_uncategorized'),
            'id'                => 0,
            'total_logged_time' => $this->projectsModel->calc_milestone_logged_time($data['project_id'], 0),
            'color'             => null,
        ];

        $_milestones = $this->projectsModel->get_milestones($data['project_id']);

        foreach ($_milestones as $m) {
            $data['milestones'][] = $m;
        }

        return view('admin.projects.milestones_kan_ban', $data);
    }

    public function milestonesKanbanLoadMore()
    {
        $milestonesExcludeCompletedTasks = request()->get('exclude_completed_tasks') && request()->get('exclude_completed_tasks') == 'yes';

        $status     = request()->get('status');
        $page       = request()->get('page');
        $project_id = request()->get('project_id');
        $where      = [];
        if ($milestonesExcludeCompletedTasks) {
            $where['status !='] = TasksModel::STATUS_COMPLETE;
        }
        $tasks = $this->projectsModel->do_milestones_kanban_query($status, $project_id, $page, $where);
        foreach ($tasks as $task) {
            return view('admin.projects._milestone_kanban_card', ['task' => $task, 'milestone' => $status]);
        }
    }

    public function milestones($project_id)
    {
        if ($this->projectsModel->is_member($project_id) || staff_can('view', 'projects')) {
            if (request()->ajax()) {
                $this->app->get_table_data('milestones', [
                    'project_id' => $project_id,
                ]);
            }
        }
    }

    public function milestone($id = '')
    {
        if (request()->post()) {
            $message = '';
            $success = false;
            if (!request()->post('id')) {
                if (!staff_can('create_milestones', 'projects')) {
                    access_denied();
                }

                $id = $this->projectsModel->add_milestone(request()->post());
                if ($id) {
                    set_alert('success', _l('added_successfully', _l('project_milestone')));
                }
            } else {
                if (!staff_can('edit_milestones', 'projects')) {
                    access_denied();
                }

                $data = request()->post();
                $id   = $data['id'];
                unset($data['id']);
                $success = $this->projectsModel->update_milestone($data, $id);
                if ($success) {
                    set_alert('success', _l('updated_successfully', _l('project_milestone')));
                }
            }
        }

        return redirect()->route('admin.projects.view', ['id' => request()->post('project_id'), 'group' => 'project_milestones']);
    }

    public function deleteMilestone($project_id, $id)
    {
        if (staff_can('delete_milestones', 'projects')) {
            if ($this->projectsModel->delete_milestone($id)) {
                set_alert('deleted', 'project_milestone');
            }
        }
        return redirect()->route('admin.projects.view', ['id' => $project_id, 'group' => 'project_milestones']);
    }

    public function bulkActionFiles()
    {
        hooks()->do_action('before_do_bulk_action_for_project_files');
        $totalDeleted       = 0;
        $hasPermissionDelete = staff_can('delete', 'projects');
        // Bulk action for projects currently only has delete button
        if (request()->post()) {
            $fVisibility = request()->post('visible_to_customer') == 'true' ? 1 : 0;
            $ids         = request()->post('ids');
            if (is_array($ids)) {
                foreach ($ids as $id) {
                    if ($hasPermissionDelete && request()->post('mass_delete') && $this->projectsModel->remove_file($id)) {
                        $totalDeleted++;
                    } else {
                        $this->projectsModel->change_file_visibility($id, $fVisibility);
                    }
                }
            }
        }
        if (request()->post('mass_delete')) {
            set_alert('success', _l('total_files_deleted', $totalDeleted));
        }
    }

    public function timesheets($project_id)
    {
        if ($this->projectsModel->is_member($project_id) || staff_can('view', 'projects')) {
            if (request()->ajax()) {
                $this->app->get_table_data('timesheets', [
                    'project_id' => $project_id,
                ]);
            }
        }
    }

    public function timesheet()
    {
        if (request()->post()) {
            if (
                request()->post('timer_id') &&
                !(staff_can('edit_timesheet', 'tasks') || (staff_can('edit_own_timesheet', 'tasks') && total_rows(db_prefix() . 'taskstimers', ['staff_id' => get_staff_user_id(), 'id' => request()->post('timer_id')]) > 0))
            ) {
                return response()->json([
                    'success' => false,
                    'message' => _l('access_denied'),
                ]);
            }
            $message = '';
            $success = false;
            $success = $this->tasks_model->timesheet(request()->post());
            if ($success === true) {
                $langKey = request()->post('timer_id') ? 'updated_successfully' : 'added_successfully';
                $message = _l($langKey, _l('project_timesheet'));
            } elseif (is_array($success) && isset($success['end_time_smaller'])) {
                $message = _l('failed_to_add_project_timesheet_end_time_smaller');
            } else {
                $message = _l('project_timesheet_not_updated');
            }
            return response()->json([
                'success' => $success,
                'message' => $message,
            ]);
        }
    }

    public function timesheetTaskAssignees($task_id, $project_id, $staff_id = 'undefined')
    {
        $assignees             = $this->tasks_model->get_task_assignees($task_id);
        $data                  = '';
        $hasPermissionEdit     = staff_can('edit', 'projects');
        $hasPermissionCreate   = staff_can('edit', 'projects');
        // The second condition if staff member edit their own timesheet
        if ($staff_id == 'undefined' || $staff_id != 'undefined' && (!$hasPermissionEdit || !$hasPermissionCreate)) {
            $staff_id     = get_staff_user_id();
            $currentUser = true;
        }
        foreach ($assignees as $staff) {
            $selected = '';
            // Maybe is admin and not a project member
            if ($staff['assigneeid'] == $staff_id && $this->projectsModel->is_member($project_id, $staff_id)) {
                $selected = ' selected';
            }
            if ((!$hasPermissionEdit || !$hasPermissionCreate) && isset($currentUser)) {
                if ($staff['assigneeid'] != $staff_id) {
                    continue;
                }
            }
            $data .= '<option value="' . $staff['assigneeid'] . '"' . $selected . '>' . get_staff_full_name($staff['assigneeid']) . '</option>';
        }
        return $data;
    }
    public function removeTeamMember($project_id, $staff_id)
    {
        if (staff_can('edit', 'projects')) {
            if ($this->projectsModel->remove_team_member($project_id, $staff_id)) {
                set_alert('success', _l('project_member_removed'));
            }
        }

        return redirect()->route('admin.projects.view', ['id' => $project_id]);
    }

    public function saveNote($project_id)
    {
        if (request()->post()) {
            $success = $this->projectsModel->save_note(request()->post(null, false), $project_id);
            if ($success) {
                set_alert('success', _l('updated_successfully', _l('project_note')));
            }
            return redirect()->route('admin.projects.view', ['id' => $project_id, 'group' => 'project_notes']);
        }
    }

    public function delete($project_id)
    {
        if (staff_can('delete', 'projects')) {
            $project = $this->projectsModel->get($project_id);
            $success = $this->projectsModel->delete($project_id);
            if ($success) {
                set_alert('success', _l('deleted', _l('project')));
                if (strpos(request()->server('HTTP_REFERER'), 'clients/') !== false) {
                    return redirect()->to(request()->server('HTTP_REFERER'));
                } else {
                    return redirect()->route('admin.projects.index');
                }
            } else {
                set_alert('warning', _l('problem_deleting', _l('project_lowercase')));
                return redirect()->route('admin.projects.view', ['id' => $project_id]);
            }
        }
    }

    public function copy($project_id)
    {
        if (staff_can('create', 'projects')) {
            $id = $this->projectsModel->copy($project_id, request()->post());
            if ($id) {
                set_alert('success', _l('project_copied_successfully'));
                return redirect()->route('admin.projects.view', ['id' => $id]);
            } else {
                set_alert('danger', _l('failed_to_copy_project'));
                return redirect()->route('admin.projects.view', ['id' => $project_id]);
            }
        }
    }

    public function massStopTimers($project_id, $billable = 'false')
    {
        if (staff_can('create', 'invoices')) {
            $where = [
                'billed'       => 0,
                'startdate <=' => now(),
            ];
            if ($billable == 'true') {
                $where['billable'] = true;
            }
            $tasks = $this->projectsModel->get_tasks($project_id, $where);
            $total_timers_stopped = 0;
            foreach ($tasks as $task) {
                $this->db->where('task_id', $task['id']);
                $this->db->where('end_time IS NULL');
                $this->db->update(db_prefix() . 'taskstimers', [
                    'end_time' => time(),
                ]);
                $total_timers_stopped += $this->db->affected_rows();
            }
            $message = _l('project_tasks_total_timers_stopped', $total_timers_stopped);
            $type    = 'success';
            if ($total_timers_stopped == 0) {
                $type = 'warning';
            }
            return response()->json([
                'type'    => $type,
                'message' => $message,
            ]);
        }
    }

    public function getPreInvoiceProjectInfo($project_id)
    {
        if (staff_can('create', 'invoices')) {
            $data['billable_tasks'] = $this->projectsModel->get_tasks($project_id, [
                'billable'     => 1,
                'billed'       => 0,
                'startdate <=' => now(),
            ]);

            $data['not_billable_tasks'] = $this->projectsModel->get_tasks($project_id, [
                'billable'    => 1,
                'billed'      => 0,
                'startdate >' => now(),
            ]);

            $data['project_id']   = $project_id;
            $data['billing_type'] = get_project_billing_type($project_id);

            $data['expenses'] = $this->expensesModel->where('invoiceid', null)->where('project_id', $project_id)->where('billable', 1)->get();

            return view('admin.projects.project_pre_invoice_settings', $data);
        }
    }

    public function getInvoiceProjectData()
    {
        if (staff_can('create', 'invoices')) {
            $type       = request()->post('type');
            $project_id = request()->post('project_id');
            // Check for all cases
            if ($type == '') {
                $type == 'single_line';
            }

            $data['payment_modes'] = $this->payment_modes_model->where('expenses_only', '!=', 1)->get();
            $data['taxes']         = $this->taxes_model->get();
            $data['currencies']    = $this->currencies_model->get();
            $data['base_currency'] = $this->currencies_model->get_base_currency();

            $data['ajaxItems'] = false;
            if (total_rows(db_prefix() . 'items') <= ajax_on_total_items()) {
                $data['items'] = $this->invoice_items_model->get_grouped();
            } else {
                $data['items']     = [];
                $data['ajaxItems'] = true;
            }

            $data['items_groups'] = $this->invoice_items_model->get_groups();
            $data['staff']        = $this->staff_model->where('active', 1)->get();
            $project              = $this->projects_model->get($project_id);
            $data['project']      = $project;
            $items                = [];

            $project    = $this->projects_model->get($project_id);
            $item['id'] = 0;

            $default_tax     = unserialize(get_option('default_tax'));
            $item['taxname'] = $default_tax;

            $tasks = request()->post('tasks');
            if ($tasks) {
                $item['long_description'] = '';
                $item['qty']              = 0;
                $item['task_id']          = [];
                if ($type == 'single_line') {
                    $item['description'] = $project->name;
                    foreach ($tasks as $task_id) {
                        $task = $this->tasks_model->get($task_id);
                        $sec  = $this->tasks_model->calc_task_total_time($task_id);
                        $item['long_description'] .= $task->name . ' - ' . seconds_to_time_format(task_timer_round($sec)) . ' ' . _l('hours') . "\r\n";
                        $item['task_id'][] = $task_id;
                        if ($project->billing_type == 2) {
                            if ($sec < 60) {
                                $sec = 0;
                            }
                            $item['qty'] += sec2qty(task_timer_round($sec));
                        }
                    }
                    if ($project->billing_type == 1) {
                        $item['qty']  = 1;
                        $item['rate'] = $project->project_cost;
                    } elseif ($project->billing_type == 2) {
                        $item['rate'] = $project->project_rate_per_hour;
                    }
                    $item['unit'] = '';
                    $items[]      = $item;
                } elseif ($type == 'task_per_item') {
                    foreach ($tasks as $task_id) {
                        $task                     = $this->tasks_model->get($task_id);
                        $sec                      = $this->tasks_model->calc_task_total_time($task_id);
                        $item['description']      = $project->name . ' - ' . $task->name;
                        $item['qty']              = floatVal(sec2qty(task_timer_round($sec)));
                        $item['long_description'] = seconds_to_time_format(task_timer_round($sec)) . ' ' . _l('hours');
                        if ($project->billing_type == 2) {
                            $item['rate'] = $project->project_rate_per_hour;
                        } elseif ($project->billing_type == 3) {
                            $item['rate'] = $task->hourly_rate;
                        }
                        $item['task_id'] = $task_id;
                        $item['unit']    = '';
                        $items[]         = $item;
                    }
                } elseif ($type == 'timesheets_individualy') {
                    $timesheets     = $this->projects_model->get_timesheets($project_id, $tasks);
                    $added_task_ids = [];
                    foreach ($timesheets as $timesheet) {
                        if ($timesheet['task_data']->billed == 0 && $timesheet['task_data']->billable == 1) {
                            $item['description'] = $project->name . ' - ' . $timesheet['task_data']->name;
                            if (!in_array($timesheet['task_id'], $added_task_ids)) {
                                $item['task_id'] = $timesheet['task_id'];
                            }

                            array_push($added_task_ids, $timesheet['task_id']);

                            $item['qty']              = floatVal(sec2qty(task_timer_round($timesheet['total_spent'])));
                            $item['long_description'] = _l('project_invoice_timesheet_start_time', _dt($timesheet['start_time'], true)) . "\r\n" . _l('project_invoice_timesheet_end_time', _dt($timesheet['end_time'], true)) . "\r\n" . _l('project_invoice_timesheet_total_logged_time', seconds_to_time_format(task_timer_round($timesheet['total_spent']))) . ' ' . _l('hours');

                            if (request()->post('timesheets_include_notes') && $timesheet['note']) {
                                $item['long_description'] .= "\r\n\r\n" . _l('note') . ': ' . $timesheet['note'];
                            }

                            if ($project->billing_type == 2) {
                                $item['rate'] = $project->project_rate_per_hour;
                            } elseif ($project->billing_type == 3) {
                                $item['rate'] = $timesheet['task_data']->hourly_rate;
                            }
                            $item['unit'] = '';
                            $items[]      = $item;
                        }
                    }
                }
            }
            if ($project->billing_type != 1) {
                $data['hours_quantity'] = true;
            }
            if (request()->post('expenses')) {
                if (isset($data['hours_quantity'])) {
                    unset($data['hours_quantity']);
                }
                if (count($tasks) > 0) {
                    $data['qty_hrs_quantity'] = true;
                }
                $expenses       = request()->post('expenses');
                $addExpenseNote = request()->post('expenses_add_note');
                $addExpenseName = request()->post('expenses_add_name');

                if (!$addExpenseNote) {
                    $addExpenseNote = [];
                }

                if (!$addExpenseName) {
                    $addExpenseName = [];
                }

                foreach ($expenses as $expense_id) {
                    // reset item array
                    $item                     = [];
                    $item['id']               = 0;
                    $expense                  = $this->expenses_model->get($expense_id);
                    $item['expense_id']       = $expense->expenseid;
                    $item['description']      = _l('item_as_expense') . ' ' . $expense->name;
                    $item['long_description'] = $expense->description;

                    if (in_array($expense_id, $addExpenseNote) && !empty($expense->note)) {
                        $item['long_description'] .= PHP_EOL . $expense->note;
                    }

                    if (in_array($expense_id, $addExpenseName) && !empty($expense->expense_name)) {
                        $item['long_description'] .= PHP_EOL . $expense->expense_name;
                    }

                    $item['qty'] = 1;

                    $item['taxname'] = [];
                    if ($expense->tax != 0) {
                        array_push($item['taxname'], $expense->tax_name . '|' . $expense->taxrate);
                    }
                    if ($expense->tax2 != 0) {
                        array_push($item['taxname'], $expense->tax_name2 . '|' . $expense->taxrate2);
                    }
                    $item['rate']  = $expense->amount;
                    $item['order'] = 1;
                    $item['unit']  = '';
                    $items[]       = $item;
                }
            }
            $data['customer_id']          = $project->clientid;
            $data['invoice_from_project'] = true;
            $data['add_items']            = $items;
            return view('admin.projects.invoice_project', $data);
        }
    }
    public function getRelProjectData($id, $task_id = '')
    {
        if (request()->ajax()) {
            $selected_milestone = '';
            $assigned = '';

            if ($task_id != '' && $task_id != 'undefined') {
                $task = $this->tasksModel->get($task_id);
                $selected_milestone = $task->milestone;
                $assigned = array_map(function ($member) {
                    return $member['assigneeid'];
                }, $this->tasksModel->getTaskAssignees($task_id));
            }

            $allow_to_view_tasks = 0;
            $project_settings = $this->projectsModel->where('project_id', $id)->where('name', 'view_tasks')->first();

            if ($project_settings) {
                $allow_to_view_tasks = $project_settings->value;
            }

            $deadline = get_project_deadline($id);

            return response()->json([
                'deadline' => $deadline,
                'deadline_formatted' => $deadline ? _d($deadline) : null,
                'allow_to_view_tasks' => $allow_to_view_tasks,
                'billing_type' => get_project_billing_type($id),
                'milestones' => render_select('milestone', $this->projectsModel->getMilestones($id), ['id', 'name'], 'task_milestone', $selected_milestone),
                'assignees' => render_select('assignees[]', $this->projectsModel->getProjectMembers($id, true), ['staff_id', ['firstname', 'lastname']], 'task_single_assignees', $assigned, ['multiple' => true], [], '', '', false),
            ]);
        }
    }

    public function invoiceProject($project_id)
    {
        if (staff_can('create', 'invoices')) {
            $data = request()->all();
            $data['project_id'] = $project_id;

            $invoice_id = $this->invoicesModel->add($data);

            if ($invoice_id) {
                $this->projectsModel->logActivity($project_id, 'project_activity_invoiced_project', format_invoice_number($invoice_id));
                set_alert('success', _l('project_invoiced_successfully'));
            }

            return redirect()->route('admin.projects.view', ['id' => $project_id, 'group' => 'project_invoices']);
        }
    }

    public function viewProjectAsClient($id, $clientid)
    {
        if (is_admin()) {
            login_as_client($clientid);
            return redirect()->to(site_url('clients/project/' . $id));
        }
    }

    public function getStaffNamesForMentions($projectId)
    {
        if (request()->ajax()) {
            $projectId = $this->db->escape_str($projectId);

            $members = $this->projectsModel->getProjectMembers($projectId);

            $members = array_map(function ($member) {
                $staff = $this->staffModel->get($member['staff_id']);

                $_member['id'] = $member['staff_id'];
                $_member['name'] = $staff->firstname . ' ' . $staff->lastname;

                return $_member;
            }, $members);

            return response()->json($members);
        }
    }
}
