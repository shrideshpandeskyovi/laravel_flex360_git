<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\Utilities\Date;
use App\Services\Tasks\TasksKanban;
use App\Models\ProjectsModel;

class TasksController extends Controller
{
    protected $projectsModel;

    public function __construct(ProjectsModel $projectsModel)
    {
        parent::__construct();
        $this->projectsModel = $projectsModel;
    }

    public function index($id = '')
    {
        return $this->listTasks($id);
    }

    public function listTasks($id = '')
    {
        close_setup_menu();

        $data['custom_view'] = request()->get('custom_view', '');
        $data['taskid'] = $id;

        if (request()->get('kanban')) {
            $this->switchKanban(0, true);
        }

        $data['switch_kanban'] = false;
        $data['bodyclass'] = 'tasks-page';

        if (session('tasks_kanban_view') == 'true') {
            $data['switch_kanban'] = true;
            $data['bodyclass'] = 'tasks-page kan-ban-body';
        }

        $data['title'] = __('tasks');
        return view('admin.tasks.manage', $data);
    }

    public function table()
    {
        app()->get_table_data('tasks');
    }

    public function kanban()
    {
        return view('admin.tasks.kan_ban');
    }

    public function ajaxSearchAssignTaskToTimer()
    {
        if (request()->ajax()) {
            $q = request()->post('q');
            $q = trim($q);
            $tasks = \DB::table('tasks')
                ->select('name', 'id', \DB::raw(tasks_rel_name_select_query() . ' as subtext'))
                ->where('id', \DB::raw('(SELECT taskid FROM task_assigned WHERE staffid = ' . get_staff_user_id() . ')'))
                ->where('status', '!=', 5)
                ->where('billed', 0)
                ->where(function ($query) use ($q) {
                    $query->where('name', 'like', '%' . $q . '%')
                        ->orWhere(tasks_rel_name_select_query(), 'like', '%' . $q . '%');
                })
                ->get();

            return response()->json($tasks);
        }
    }

    public function tasksKanbanLoadMore()
    {
        $status = request()->get('status');
        $page = request()->get('page');

        $tasks = (new TasksKanban($status))
            ->search(request()->get('search'))
            ->sortBy(
                request()->get('sort_by'),
                request()->get('sort')
            )
            ->forProject(request()->get('project_id'))
            ->page($page)->get();

        foreach ($tasks as $task) {
            return view('admin.tasks._kan_ban_card', [
                'task' => $task,
                'status' => $status,
            ])->render();
        }
    }

    public function updateOrder()
    {
        $this->tasksModel->updateOrder(request()->post());
    }

    public function switchKanban($set = 0, $manual = false)
    {
        $set = $set == 1 ? 'false' : 'true';

        session(['tasks_kanban_view' => $set]);

        if (!$manual) {
            // clicked on VIEW KANBAN from projects area and will redirect again to the same view
            if (strpos(url()->previous(), 'project_id') !== false) {
                return redirect(admin_url('tasks'));
            } else {
                return redirect(url()->previous());
            }
        }
    }
    public function __construct(TasksModel $tasksModel, MilestonesModel $milestonesModel, ProjectsModel $projectsModel, StaffModel $staffModel)
    {
        parent::__construct();
        $this->tasksModel = $tasksModel;
        $this->milestonesModel = $milestonesModel;
        $this->projectsModel = $projectsModel;
        $this->staffModel = $staffModel;
    }

    public function getBillableTasksByProject($project_id)
    {
        if (request()->ajax() && (has_permission('invoices', '', 'edit') || has_permission('invoices', '', 'create'))) {
            $customer_id = get_client_id_by_project_id($project_id);
            return response()->json($this->tasksModel->getBillableTasks($customer_id, $project_id));
        }
    }

    public function getBillableTasksByCustomerId($customer_id)
    {
        if (request()->ajax() && (has_permission('invoices', '', 'edit') || has_permission('invoices', '', 'create'))) {
            return response()->json($this->tasksModel->getBillableTasks($customer_id));
        }
    }

    public function updateTaskDescription($id)
    {
        if (has_permission('tasks', '', 'edit')) {
            $data = hooks()->apply_filters('before_update_task', [
                'description' => html_purify(request()->post('description', false)),
            ], $id);

            $this->tasksModel->where('id', $id)->update($data);

            hooks()->do_action('after_update_task', $id);
        }
    }

    public function detailedOverview()
    {
        $overview = [];

        $has_permission_create = has_permission('tasks', '', 'create');
        $has_permission_view = has_permission('tasks', '', 'view');

        $staff_id = '';
        if (!$has_permission_view) {
            $staff_id = get_staff_user_id();
        } elseif (request()->post('member')) {
            $staff_id = request()->post('member');
        }

        $month = (request()->post('month') ? request()->post('month') : date('m'));
        if (request()->post() && request()->post('month') == '') {
            $month = '';
        }

        $status = request()->post('status');

        $fetch_month_from = 'startdate';

        $year = (request()->post('year') ? request()->post('year') : date('Y'));
        $project_id = request()->get('project_id');

        for ($m = 1; $m <= 12; $m++) {
            if ($month != '' && $month != $m) {
                continue;
            }

            // Task rel_name
            $sqlTasksSelect = '*,' . tasks_rel_name_select_query() . ' as rel_name';

            // Task logged time
            $selectLoggedTime = get_sql_calc_task_logged_time('tmp-task-id');
            // Replace tmp-task-id to be the same like tasks.id
            $selectLoggedTime = str_replace('tmp-task-id', 'tasks.id', $selectLoggedTime);

            if (is_numeric($staff_id)) {
                $selectLoggedTime .= ' AND staff_id=' . $this->db->escape_str($staff_id);
                $sqlTasksSelect .= ',(' . $selectLoggedTime . ')';
            } else {
                $sqlTasksSelect .= ',(' . $selectLoggedTime . ')';
            }

            $sqlTasksSelect .= ' as total_logged_time';

            // Task checklist items
            $sqlTasksSelect .= ',' . get_sql_select_task_total_checklist_items();

            if (is_numeric($staff_id)) {
                $sqlTasksSelect .= ',(SELECT COUNT(id) FROM task_checklist_items WHERE taskid=tasks.id AND finished=1 AND finished_from=' . $staff_id . ') as total_finished_checklist_items';
            } else {
                $sqlTasksSelect .= ',' . get_sql_select_task_total_finished_checklist_items();
            }

            // Task total comment and total files
            $selectTotalComments = ',(SELECT COUNT(id) FROM task_comments WHERE taskid=tasks.id';
            $selectTotalFiles = ',(SELECT COUNT(id) FROM files WHERE rel_id=tasks.id AND rel_type="task"';

            if (is_numeric($staff_id)) {
                $sqlTasksSelect .= $selectTotalComments . ' AND staffid=' . $staff_id . ') as total_comments_staff';
                $sqlTasksSelect .= $selectTotalFiles . ' AND staffid=' . $staff_id . ') as total_files_staff';
            }

            $sqlTasksSelect .= $selectTotalComments . ') as total_comments';
            $sqlTasksSelect .= $selectTotalFiles . ') as total_files';

            // Task assignees
            $sqlTasksSelect .= ',' . get_sql_select_task_asignees_full_names() . ' as assignees' . ',' . get_sql_select_task_assignees_ids() . ' as assignees_ids';

            $tasks = $this->tasksModel->select($sqlTasksSelect)
                ->whereMonth($fetch_month_from, $m)
                ->whereYear($fetch_month_from, $year);

            if ($project_id && $project_id != '') {
                $tasks->where('rel_id', $project_id)->where('rel_type', 'project');
            }

            if (!$has_permission_view) {
                $sqlWhereStaff = '(id IN (SELECT taskid FROM task_assigned WHERE staffid=' . $staff_id . ')';

                if ($has_permission_create) {
                    $sqlWhereStaff .= ' OR addedfrom=' . get_staff_user_id();
                }

                $sqlWhereStaff .= ')';
                $tasks->whereRaw($sqlWhereStaff);
            } elseif ($has_permission_view) {
                if (is_numeric($staff_id)) {
                    $tasks->where('(id IN (SELECT taskid FROM task_assigned WHERE staffid=' . $staff_id . '))');
                }
            }

            if ($status) {
                $tasks->where('status', $status);
            }

            $tasks->orderBy($fetch_month_from, 'ASC');
            $overview[$m] = $tasks->get()->toArray();
        }

        unset($overview[0]);

        $overview = [
            'staff_id' => $staff_id,
            'detailed' => $overview,
        ];

        $data['members'] = $this->staffModel->get();
        $data['overview'] = $overview['detailed'];
        $data['years'] = $this->tasksModel->getDistinctTasksYears((request()->post('month_from') ? request()->post('month_from') : 'startdate'));
        $data['staff_id'] = $overview['staff_id'];
        $data['title'] = _l('detailed_overview');
        return view('admin.tasks.detailed_overview', $data);
    }

    public function initRelationTasks($rel_id, $rel_type)
    {
        if (request()->ajax()) {
            return app()->get_table_data('tasks_relations', [
                'rel_id' => $rel_id,
                'rel_type' => $rel_type,
            ]);
        }
    }

    public function task($id = '')
    {
        if (!has_permission('tasks', '', 'edit') && !has_permission('tasks', '', 'create')) {
            ajax_access_denied();
        }

        $data = [];
        if (request()->get('milestone_id')) {
            $milestone = $this->milestonesModel->where('id', request()->get('milestone_id'))->first();
            if ($milestone) {
                $data['_milestone_selected_data'] = [
                    'id' => $milestone->id,
                    'due_date' => _d($milestone->due_date),
                ];
            }
        }
        if (request()->get('start_date')) {
            $data['start_date'] = request()->get('start_date');
        }
        if (request()->post()) {
            $data = request()->post();
            $data['description'] = html_purify(request()->post('description', false));
            if ($id == '') {
                if (!has_permission('tasks', '', 'create')) {
                    return response()->json([
                        'success' => false,
                        'message' => _l('access_denied'),
                    ], 400);
                }
                $task = $this->tasksModel->create($data);
                $_id = false;
                $success = false;
                $message = '';
                if ($task) {
                    $success = true;
                    $_id = $task->id;
                    $message = _l('added_successfully', _l('task'));
                    $uploadedFiles = handle_task_attachments_array($task->id);
                    if ($uploadedFiles && is_array($uploadedFiles)) {
                        foreach ($uploadedFiles as $file) {
                            $this->misc_model->add_attachment_to_database($task->id, 'task', [$file]);
                        }
                    }
                }
                return response()->json([
                    'success' => $success,
                    'id' => $_id,
                    'message' => $message,
                ]);
            } else {
                if (!has_permission('tasks', '', 'edit')) {
                    return response()->json([
                        'success' => false,
                        'message' => _l('access_denied'),
                    ], 400);
                }
                $success = $this->tasksModel->where('id', $id)->update($data);
                $message = '';
                if ($success) {
                    $message = _l('updated_successfully', _l('task'));
                }
                return response()->json([
                    'success' => $success,
                    'message' => $message,
                    'id' => $id,
                ]);
            }
        }

        $data['milestones'] = [];
        $data['checklistTemplates'] = $this->tasksModel->getChecklistTemplates();
        if ($id == '') {
            $title = _l('add_new', _l('task_lowercase'));
        } else {
            $data['task'] = $this->tasksModel->find($id);
            if ($data['task']->rel_type == 'project') {
                $data['milestones'] = $this->projectsModel->getMilestones($data['task']->rel_id);
            }
            $title = _l('edit', _l('task_lowercase')) . ' ' . $data['task']->name;
        }

        $data['project_end_date_attrs'] = [];
        if (request()->get('rel_type') == 'project' && request()->get('rel_id') || ($id !== '' && $data['task']->rel_type == 'project')) {
            $project = $this->projectsModel->find($id === '' ? request()->get('rel_id') : $data['task']->rel_id);

            if ($project->deadline) {
                $data['project_end_date_attrs'] = [
                    'data-date-end-date' => $project->deadline,
                ];
            }
        }
        $data['members'] = $this->staffModel->get();
        $data['id'] = $id;
        $data['title'] = $title;
        return view('admin.tasks.task', $data);
    }
    public function __construct(TasksModel $tasksModel, MiscModel $miscModel, StaffModel $staffModel)
    {
        parent::__construct();
        $this->tasksModel = $tasksModel;
        $this->miscModel = $miscModel;
        $this->staffModel = $staffModel;
    }

    public function copy()
    {
        if (has_permission('tasks', '', 'create')) {
            $new_task_id = $this->tasksModel->copy(request()->post());
            $response = [
                'new_task_id' => '',
                'alert_type' => 'warning',
                'message' => _l('failed_to_copy_task'),
                'success' => false,
            ];
            if ($new_task_id) {
                $response['message'] = _l('task_copied_successfully');
                $response['new_task_id'] = $new_task_id;
                $response['success'] = true;
                $response['alert_type'] = 'success';
            }
            return response()->json($response);
        }
    }

    public function getBillableTaskData($task_id)
    {
        $task = $this->tasksModel->getBillableTaskData($task_id);
        $task->description = seconds_to_time_format($task->total_seconds) . ' ' . _l('hours');
        return response()->json($task);
    }

    public function getTaskData($taskid, $return = false)
    {
        $tasks_where = [];

        if (!has_permission('tasks', '', 'view')) {
            $tasks_where = get_tasks_where_string(false);
        }

        $task = $this->tasksModel->find($taskid, $tasks_where);

        if (!$task) {
            abort(404, 'Task not found');
        }

        $data['checklistTemplates'] = $this->tasksModel->getChecklistTemplates();
        $data['task'] = $task;
        $data['id'] = $task->id;
        $data['staff'] = $this->staffModel->get('', ['active' => 1]);
        $data['reminders'] = $this->tasksModel->getReminders($taskid);

        $data['task_staff_members'] = $this->tasksModel->getStaffMembersThatCanAccessTask($taskid);
        $data['staff_reminders'] = $data['task_staff_members'];

        $data['hide_completed_items'] = get_staff_meta(get_staff_user_id(), 'task-hide-completed-items-' . $taskid);

        $data['project_deadline'] = null;
        if ($task->rel_type == 'project') {
            $data['project_deadline'] = get_project_deadline($task->rel_id);
        }

        if ($return == false) {
            return view('admin.tasks.view_task_template', $data);
        } else {
            return view('admin.tasks.view_task_template', $data)->render();
        }
    }

    public function addReminder($task_id)
    {
        $message = '';
        $alert_type = 'warning';
        if (request()->post()) {
            $success = $this->miscModel->addReminder(request()->post(), $task_id);
            if ($success) {
                $alert_type = 'success';
                $message = _l('reminder_added_successfully');
            }
        }
        return response()->json([
            'taskHtml' => $this->getTaskData($task_id, true),
            'alert_type' => $alert_type,
            'message' => $message,
        ]);
    }

    public function editReminder($id)
    {
        $reminder = $this->miscModel->getReminders($id);
        if ($reminder && ($reminder->creator == get_staff_user_id() || is_admin()) && $reminder->isnotified == 0) {
            $success = $this->miscModel->editReminder(request()->post(), $id);
            return response()->json([
                'taskHtml' => $this->getTaskData($reminder->rel_id, true),
                'alert_type' => 'success',
                'message' => ($success ? _l('updated_successfully', _l('reminder')) : ''),
            ]);
        }
    }

    public function deleteReminder($rel_id, $id)
    {
        $success = $this->miscModel->deleteReminder($id);
        $alert_type = 'warning';
        $message = _l('reminder_failed_to_delete');
        if ($success) {
            $alert_type = 'success';
            $message = _l('reminder_deleted');
        }
        return response()->json([
            'taskHtml' => $this->getTaskData($rel_id, true),
            'alert_type' => $alert_type,
            'message' => $message,
        ]);
    }
    public function __construct(TasksModel $tasksModel, MiscModel $miscModel, StaffModel $staffModel)
    {
        parent::__construct();
        $this->tasksModel = $tasksModel;
        $this->miscModel = $miscModel;
        $this->staffModel = $staffModel;
    }

    public function getStaffStartedTimers($return = false)
    {
        $data['startedTimers'] = $this->miscModel->getStaffStartedTimers();
        $_data['html'] = view('admin.tasks.started_timers', $data)->render();
        $_data['total_timers'] = count($data['startedTimers']);

        $timers = json_encode($_data);
        if ($return) {
            return $timers;
        }

        echo $timers;
    }

    public function saveChecklistItemTemplate()
    {
        if (has_permission('checklist_templates', '', 'create')) {
            $id = $this->tasksModel->addChecklistTemplate(request()->post('description'));
            echo json_encode(['id' => $id]);
        }
    }

    public function removeChecklistItemTemplate($id)
    {
        if (has_permission('checklist_templates', '', 'delete')) {
            $success = $this->tasksModel->removeChecklistItemTemplate($id);
            echo json_encode(['success' => $success]);
        }
    }

    public function initChecklistItems()
    {
        if (request()->ajax()) {
            if (request()->post()) {
                $postData = request()->post();
                $data['task_id'] = $postData['taskid'];
                $data['checklists'] = $this->tasksModel->getChecklistItems($postData['taskid']);
                $data['task_staff_members'] = $this->tasksModel->getStaffMembersThatCanAccessTask($data['task_id']);
                $data['current_user_is_creator'] = $this->tasksModel->isTaskCreator(get_staff_user_id(), $data['task_id']);
                $data['hide_completed_items'] = get_staff_meta(get_staff_user_id(), 'task-hide-completed-items-' . $data['task_id']);

                return view('admin.tasks.checklist_items_template', $data);
            }
        }
    }

    public function taskTrackingStats($task_id)
    {
        $data['stats'] = json_encode($this->tasksModel->taskTrackingStats($task_id));
        return view('admin.tasks.tracking_stats', $data);
    }

    public function checkboxAction($listid, $value)
    {
        $this->db->where('id', $listid);
        $this->db->update(db_prefix() . 'task_checklist_items', [
            'finished' => $value,
        ]);

        if ($this->db->affected_rows() > 0) {
            if ($value == 1) {
                $this->db->where('id', $listid);
                $this->db->update(db_prefix() . 'task_checklist_items', [
                    'finished_from' => get_staff_user_id(),
                ]);
                hooks()->do_action('task_checklist_item_finished', $listid);
            }
        }
    }

    public function addChecklistItem()
    {
        if (request()->ajax()) {
            if (request()->post()) {
                echo json_encode([
                    'success' => $this->tasksModel->addChecklistItem(request()->post()),
                ]);
            }
        }
    }

    public function updateChecklistOrder()
    {
        if (request()->ajax()) {
            if (request()->post()) {
                $this->tasksModel->updateChecklistOrder(request()->post());
            }
        }
    }

    public function deleteChecklistItem($id)
    {
        $list = $this->tasksModel->getChecklistItem($id);
        if (has_permission('tasks', '', 'delete') || $list->addedfrom == get_staff_user_id()) {
            if (request()->ajax()) {
                echo json_encode([
                    'success' => $this->tasksModel->deleteChecklistItem($id),
                ]);
            }
        }
    }

    public function updateChecklistItem()
    {
        if (request()->ajax()) {
            if (request()->post()) {
                $desc = request()->post('description');
                $desc = trim($desc);
                $this->tasksModel->updateChecklistItem(request()->post('listid'), $desc);
                echo json_encode(['can_be_template' => (total_rows(db_prefix() . 'tasks_checklist_templates', ['description' => $desc]) == 0)]);
            }
        }
    }

    public function makePublic($task_id)
    {
        if (!has_permission('tasks', '', 'edit')) {
            json_encode([
                'success' => false,
            ]);
            die;
        }
        echo json_encode([
            'success' => $this->tasksModel->makePublic($task_id),
            'taskHtml' => $this->getTaskData($task_id, true),
        ]);
    }

    public function addExternalAttachment()
    {
        if (request()->post()) {
            $this->tasksModel->addAttachmentToDatabase(
                request()->post('task_id'),
                request()->post('files'),
                request()->post('external')
            );
        }
    }

    public function addTaskComment()
    {
        $data = request()->all();
        $data['content'] = html_purify(request()->post('content', false));
        if (request()->post('no_editor')) {
            $data['content'] = nl2br(request()->post('content'));
        }
        $comment_id = false;
        if (
            $data['content'] != ''
            || (isset($_FILES['file']['name']) && is_array($_FILES['file']['name']) && count($_FILES['file']['name']) > 0)
        ) {
            $comment_id = $this->tasksModel->addTaskComment($data);
            if ($comment_id) {
                $commentAttachments = handleTaskAttachmentsArray($data['taskid'], 'file');
                if ($commentAttachments && is_array($commentAttachments)) {
                    foreach ($commentAttachments as $file) {
                        $file['task_comment_id'] = $comment_id;
                        $this->miscModel->addAttachmentToDatabase($data['taskid'], 'task', [$file]);
                    }

                    if (count($commentAttachments) > 0) {
                        $this->db->query('UPDATE ' . db_prefix() . "task_comments SET content = CONCAT(content, '[task_attachment]')
                            WHERE id = " . $this->db->escape_str($comment_id));
                    }
                }
            }
        }
        echo json_encode([
            'success' => $comment_id ? true : false,
            'taskHtml' => $this->getTaskData($data['taskid'], true),
        ]);
    }

    public function downloadFiles($task_id, $comment_id = null)
    {
        $taskWhere = 'external IS NULL';

        if ($comment_id) {
            $taskWhere .= ' AND task_comment_id=' . $this->db->escape_str($comment_id);
        }

        if (!has_permission('tasks', '', 'view')) {
            $taskWhere .= ' AND ' . get_tasks_where_string(false);
        }

        $files = $this->tasksModel->getTaskAttachments($task_id, $taskWhere);

        if (count($files) == 0) {
            redirect($_SERVER['HTTP_REFERER']);
        }

        $path = get_upload_path_by_type('task') . $task_id;

        $this->load->library('zip');

        foreach ($files as $file) {
            $this->zip->read_file($path . '/' . $file['file_name']);
        }

        $this->zip->download('files.zip');
        $this->zip->clear_data();
    }

    public function addTaskFollowers()
    {
        $task = $this->tasksModel->get(request()->post('taskid'));

        if (staff_can('edit', 'tasks') ||
                ($task->current_user_is_creator && staff_can('create', 'tasks'))) {
            echo json_encode([
                'success' => $this->tasksModel->addTaskFollowers(request()->post()),
                'taskHtml' => $this->getTaskData(request()->post('taskid'), true),
            ]);
        }
    }

    public function addTaskAssignees()
    {
        $task = $this->tasksModel->get(request()->post('taskid'));

        if (staff_can('edit', 'tasks') ||
                ($task->current_user_is_creator && staff_can('create', 'tasks'))) {
            echo json_encode([
                'success' => $this->tasksModel->addTaskAssignees(request()->post()),
                'taskHtml' => $this->getTaskData(request()->post('taskid'), true),
            ]);
        }
    }

    public function editComment()
    {
        if (request()->post()) {
            $data = request()->post();
            $data['content'] = html_purify(request()->post('content', false));
            if (request()->post('no_editor')) {
                $data['content'] = nl2br(clear_textarea_breaks(request()->post('content')));
            }
            $success = $this->tasksModel->editComment($data);
            $message = '';
            if ($success) {
                $message = _l('task_comment_updated');
            }
            echo json_encode([
                'success' => $success,
                'message' => $message,
                'taskHtml' => $this->getTaskData($data['task_id'], true),
            ]);
        }
    }

    public function removeComment($id)
    {
        echo json_encode([
            'success' => $this->tasksModel->removeComment($id),
        ]);
    }

    public function removeAssignee($id, $taskid)
    {
        $task = $this->tasksModel->get($taskid);

        if (staff_can('edit', 'tasks') ||
                ($task->current_user_is_creator && staff_can('create', 'tasks'))) {
            $success = $this->tasksModel->removeAssignee($id, $taskid);
            $message = '';
            if ($success) {
                $message = _l('task_assignee_removed');
            }
            echo json_encode([
                'success' => $success,
                'message' => $message,
                'taskHtml' => $this->getTaskData($taskid, true),
            ]);
        }
    }

    public function removeFollower($id, $taskid)
    {
        $task = $this->tasksModel->get($taskid);

        if (staff_can('edit', 'tasks') ||
                ($task->current_user_is_creator && staff_can('create', 'tasks'))) {
            $success = $this->tasksModel->removeFollower($id, $taskid);
            $message = '';
            if ($success) {
                $message = _l('task_follower_removed');
            }
            echo json_encode([
                'success' => $success,
                'message' => $message,
                'taskHtml' => $this->getTaskData($taskid, true),
            ]);
        }
    }

    public function unmarkComplete($id)
    {
        if (
            $this->tasksModel->isTaskAssignee(get_staff_user_id(), $id)
            || $this->tasksModel->isTaskCreator(get_staff_user_id(), $id)
            || has_permission('tasks', '', 'edit')
        ) {
            $success = $this->tasksModel->unmarkComplete($id);

            // Don't do this query if the action is not performed via task single
            $taskHtml = request()->get('single_task') === 'true' ? $this->getTaskData($id, true) : '';

            $message = '';
            if ($success) {
                $message = _l('task_unmarked_as_complete');
            }
            echo json_encode([
                'success' => $success,
                'message' => $message,
                'taskHtml' => $taskHtml,
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => '',
                'taskHtml' => '',
            ]);
        }
    }

    public function markAs($status, $id)
    {
        if (
            $this->tasksModel->isTaskAssignee(get_staff_user_id(), $id)
            || $this->tasksModel->isTaskCreator(get_staff_user_id(), $id)
            || has_permission('tasks', '', 'edit')
        ) {
            $success = $this->tasksModel->markAs($status, $id);

            // Don't do this query if the action is not performed via task single
            $taskHtml = request()->get('single_task') === 'true' ? $this->getTaskData($id, true) : '';

            $message = '';

            if ($success) {
                $message = _l('task_marked_as_success', format_task_status($status, true, true));
            }

            echo json_encode([
                'success' => $success,
                'message' => $message,
                'taskHtml' => $taskHtml,
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => '',
                'taskHtml' => '',
            ]);
        }
    }
    public function changePriority($priority_id, $id)
{
    if (has_permission('tasks', '', 'edit')) {
        $data = hooks()->apply_filters('before_update_task', ['priority' => $priority_id], $id);

        Task::where('id', $id)->update($data);

        $success = Task::where('id', $id)->count() > 0;

        hooks()->do_action('after_update_task', $id);

        $taskHtml = request()->input('single_task') === 'true' ? $this->getTaskData($id, true) : '';
        echo json_encode([
            'success' => $success,
            'taskHtml' => $taskHtml,
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'taskHtml' => '',
        ]);
    }
}

public function changeMilestone($milestone_id, $id)
{
    if (has_permission('tasks', '', 'edit')) {
        Task::where('id', $id)->update(['milestone' => $milestone_id]);

        $success = Task::where('id', $id)->count() > 0;

        // Don't do this query if the action is not performed via task single
        $taskHtml = request()->input('single_task') === 'true' ? $this->getTaskData($id, true) : '';
        echo json_encode([
            'success' => $success,
            'taskHtml' => $taskHtml,
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'taskHtml' => '',
        ]);
    }
}

public function taskSingleInlineUpdate($task_id)
{
    if (has_permission('tasks', '', 'edit')) {
        $postData = request()->post();
        foreach ($postData as $key => $val) {
            $data = hooks()->apply_filters('before_update_task', [
                $key => to_sql_date($val),
            ], $task_id);

            Task::where('id', $task_id)->update($data);

            hooks()->do_action('after_update_task', $task_id);
        }
    }
}

public function deleteTask($id)
{
    if (!has_permission('tasks', '', 'delete')) {
        // Handle access denied or redirect as needed
    }
    $success = Task::where('id', $id)->delete();
    $message = _l('problem_deleting', _l('task_lowercase'));
    if ($success) {
        $message = _l('deleted', _l('task'));
        set_alert('success', $message);
    } else {
        set_alert('warning', $message);
    }

    // Handle redirects based on HTTP_REFERER
}

public function removeTaskAttachment($id)
{
    if (request()->ajax()) {
        echo json_encode(Task::removeTaskAttachment($id));
    }
}

public function uploadFile()
{
    if (request()->post()) {
        $taskId = request()->post('taskid');
        $files = handleTaskAttachmentsArray($taskId, 'file');
        $success = false;

        if ($files) {
            $i = 0;
            $len = count($files);
            foreach ($files as $file) {
                $success = Task::addAttachmentToDatabase($taskId, [$file], false, ($i == $len - 1 ? true : false));
                $i++;
            }
        }

        echo json_encode([
            'success' => $success,
            'taskHtml' => $this->getTaskData($taskId, true),
        ]);
    }
}

public function timerTracking()
{
    $taskId = request()->post('task_id');
    $adminStop = request()->get('admin_stop') && is_admin() ? true : false;

    if ($adminStop) {
        session()->flash('task_single_timesheets_open', true);
    }

    echo json_encode([
        'success' => Task::timerTracking(
            $taskId,
            request()->post('timer_id'),
            nl2br(request()->post('note')),
            $adminStop
        ),
        'taskHtml' => request()->get('single_task') === 'true' ? $this->getTaskData($taskId, true) : '',
        'timers' => $this->getStaffStartedTimers(true),
    ]);
}

public function deleteUserUnfinishedTimesheet($id)
{
    TaskTimer::where('id', $id)
        ->where('end_time', null)
        ->where('staff_id', get_staff_user_id())
        ->delete();

    echo json_encode(['timers' => $this->getStaffStartedTimers(true)]);
}

public function deleteTimesheet($id)
{
    if (has_permission('delete_timesheet', 'tasks') || (has_permission('delete_own_timesheet', 'tasks') && TaskTimer::where('staff_id', get_staff_user_id())->where('id', $id)->count() > 0)) {
        $alertType = 'warning';
        $success = Task::deleteTimesheet($id);
        if ($success) {
            session()->flash('task_single_timesheets_open', true);
            $message = _l('deleted', _l('project_timesheet'));
            set_alert('success', $message);
        }
        if (!request()->ajax()) {
            redirect()->back();
        }
    }
}

public function updateTimesheet()
{
    if (request()->ajax()) {
        if (has_permission('edit_timesheet', 'tasks') || (has_permission('edit_own_timesheet', 'tasks') && TaskTimer::where('staff_id', get_staff_user_id())->where('id', request()->post('timer_id'))->count() > 0)) {
            $success = Task::timesheet(request()->post());
            if ($success === true) {
                session()->flash('task_single_timesheets_open', true);
                $message = _l('updated_successfully', _l('project_timesheet'));
            } else {
                $message = _l('failed_to_update_timesheet');
            }

            echo json_encode([
                'success' => $success,
                'message' => $message,
            ]);
            die;
        }

        echo json_encode([
            'success' => false,
            'message' => _l('access_denied'),
        ]);
        die;
    }
}

public function logTime()
{
    $success = Task::timesheet(request()->post());
    if ($success === true) {
        session()->flash('task_single_timesheets_open', true);
        $message = _l('added_successfully', _l('project_timesheet'));
    } elseif (is_array($success) && isset($success['end_time_smaller'])) {
        $message = _l('failed_to_add_project_timesheet_end_time_smaller');
    } else {
        $message = _l('project_timesheet_not_updated');
    }

    echo json_encode([
        'success' => $success,
        'message' => $message,
    ]);
    die;
}

public function updateTags()
{
    if (has_permission('tasks', '', 'create') || has_permission('tasks', '', 'edit')) {
        $id = request()->post('task_id');

        $data = hooks()->apply_filters('before_update_task', [
            'tags' => request()->post('tags'),
        ], $id);

        handleTagsSave($data['tags'], $id, 'task');

        hooks()->do_action('after_update_task', $id);
    }
}

public function bulkAction()
{
    hooks()->do_action('before_do_bulk_action_for_tasks');
    $totalDeleted = 0;
    if (request()->post()) {
        $status = request()->post('status');
        $ids = request()->post('ids');
        $tags = request()->post('tags');
        $assignees = request()->post('assignees');
        $milestone = request()->post('milestone');
        $priority = request()->post('priority');
        $billable = request()->post('billable');
        $isAdmin = is_admin();
        if (is_array($ids)) {
            foreach ($ids as $id) {
                if (request()->post('mass_delete')) {
                    if (has_permission('tasks', '', 'delete')) {
                        if (Task::where('id', $id)->delete()) {
                            $totalDeleted++;
                        }
                    }
                } else {
                    if ($status) {
                        if (
                            Task::isTaskCreator(get_staff_user_id(), $id)
                            || $isAdmin
                            || Task::isTaskAssignee(get_staff_user_id(), $id)
                        ) {
                            Task::markAs($status, $id);
                        }
                    }
                    if ($priority || $milestone || ($billable === 'billable' || $billable === 'not_billable')) {
                        $update = [];

                        if ($priority) {
                            $update['priority'] = $priority;
                        }

                        if ($milestone) {
                            $update['milestone'] = $milestone;
                        }

                        if ($billable) {
                            $update['billable'] = $billable === 'billable' ? 1 : 0;
                        }

                        Task::where('id', $id)->update($update);
                    }
                    if ($tags) {
                        handleTagsSave($tags, $id, 'task');
                    }
                    if ($assignees) {
                        $notifiedUsers = [];
                        foreach ($assignees as $userId) {
                            if (!Task::isTaskAssignee($userId, $id)) {
                                $task = Task::select('rel_type', 'rel_id')->where('id', $id)->first();
                                if ($task->rel_type == 'project') {
                                    // User is we are trying to assign the task is not project member
                                    if (ProjectMember::where('project_id', $task->rel_id)->where('staff_id', $userId)->count() == 0) {
                                        ProjectMember::insert(['project_id' => $task->rel_id, 'staff_id' => $userId]);
                                    }
                                }
                                TaskAssigned::insert([
                                    'staffid' => $userId,
                                    'taskid' => $id,
                                    'assigned_from' => get_staff_user_id(),
                                ]);
                                if ($userId != get_staff_user_id()) {
                                    $notificationData = [
                                        'description' => 'not_task_assigned_to_you',
                                        'touserid' => $userId,
                                        'link' => '#taskid=' . $id,
                                    ];

                                    $notificationData['additional_data'] = serialize([
                                        getTaskSubjectById($id),
                                    ]);
                                    if (addNotification($notificationData)) {
                                        array_push($notifiedUsers, $userId);
                                    }
                                }
                            }
                        }
                        pusherTriggerNotification($notifiedUsers);
                    }
                }
            }
        }
        if (request()->post('mass_delete')) {
            set_alert('success', _l('total_tasks_deleted', $totalDeleted));
        }
    }
}

public function ganttDateUpdate($task_id)
{
    if (has_permission('edit', 'tasks')) {
        $postData = request()->post();
        foreach ($postData as $key => $val) {
            Task::where('id', $task_id)->update([$key => $val]);
        }
    }
}

public function getTaskById($id)
{
    if (request()->ajax()) {
        $tasksWhere = [];
        if (!staff_can('view', 'tasks')) {
            $tasksWhere = getTasksWhereString(false);
        }
        $task = Task::where('id', $id)->where($tasksWhere)->first();
        if (!$task) {
            header('HTTP/1.0 404 Not Found');
            echo 'Task not found';
            die();
        }
        echo json_encode($task);
    }
}

public function getStaffNamesForMentions($taskId)
{
    if (request()->ajax()) {
        $taskId = Task::escape($taskId);

        $members = Task::getStaffMembersThatCanAccessTask($taskId);
        $members = array_map(function ($member) {
            $_member['id'] = $member['staffid'];
            $_member['name'] = $member['firstname'] . ' ' . $member['lastname'];

            return $_member;
        }, $members);

        echo json_encode($members);
    }
}

public function saveChecklistAssignedStaff()
{
    if (request()->post() && request()->ajax()) {
        $payload = request()->post();
        $item = Task::getChecklistItem($payload['checklistId']);
        if ($item->addedfrom == get_staff_user_id()
            || is_admin() ||
            Task::isTaskCreator(get_staff_user_id(), $payload['taskId'])) {
            Task::updateChecklistAssignedStaff($payload);
            die;
        }

        ajaxAccessDenied();
    }
}
}
