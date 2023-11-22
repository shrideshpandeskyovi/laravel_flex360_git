<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Todo as TodoModel;

class TodoController extends Controller
{
    protected $todoModel;

    public function __construct()
    {
        parent::__construct();
        $this->todoModel = new TodoModel();
    }

    public function index()
    {
        if (request()->ajax()) {
            return response()->json($this->todoModel->getTodoItems(request()->post('finished'), request()->post('todo_page')));
        }

        $data['bodyclass'] = 'main-todo-page';
        $data['total_pages_finished'] = ceil($this->todoModel->totalRows([
            'finished' => 1,
            'staffid'  => get_staff_user_id(),
        ]) / $this->todoModel->getTodosLimit());

        $data['total_pages_unfinished'] = ceil($this->todoModel->totalRows([
            'finished' => 0,
            'staffid'  => get_staff_user_id(),
        ]) / $this->todoModel->getTodosLimit());

        $data['title'] = __('my_todos');
        return view('admin.todos.all', $data);
    }

    public function todo()
    {
        if (request()->post()) {
            $data = request()->post();

            if (empty($data['todoid'])) {
                unset($data['todoid']);
                $id = $this->todoModel->add($data);
                if ($id) {
                    set_alert('success', __('added_successfully', __('todo')));
                }
            } else {
                $id = $data['todoid'];
                unset($data['todoid']);
                $success = $this->todoModel->update($id, $data);
                if ($success) {
                    set_alert('success', __('updated_successfully', __('todo')));
                }
            }

            return redirect()->back();
        }
    }

    public function get_by_id($id)
    {
        $todo = $this->todoModel->get($id);
        $todo->description = clear_textarea_breaks($todo->description);
        return response()->json($todo);
    }

    public function change_todo_status($id, $status)
    {
        $success = $this->todoModel->changeTodoStatus($id, $status);
        if ($success) {
            set_alert('success', __('todo_status_changed'));
        }
        return redirect()->back();
    }

    public function update_todo_items_order()
    {
        if (request()->post()) {
            $this->todoModel->updateTodoItemsOrder(request()->post());
        }
    }

    public function delete_todo_item($id)
    {
        if (request()->ajax()) {
            return response()->json([
                'success' => $this->todoModel->deleteTodoItem($id),
            ]);
        }
    }
}
