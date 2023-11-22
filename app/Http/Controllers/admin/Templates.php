<?php

namespace App\Http\Controllers;

use App\Models\Template;
use Illuminate\Http\Request;

class TemplatesController extends AdminController
{
    /**
     * Initialize Templates controller
     */
    public function __construct()
    {
        parent::__construct();
        $this->middleware('admin');
        $this->templateModel = new Template();
    }

    /**
     * Get the template modal content
     *
     * @return \Illuminate\View\View
     */
    public function modal()
    {
        $data['rel_type'] = request()->post('rel_type');
        $data['rel_id'] = request()->post('rel_id');

        if (request()->post('slug') == 'new') {
            $data['title'] = _l('add_template');
        } elseif (request()->post('slug') == 'edit') {
            $data['title'] = _l('edit_template');
            $data['id'] = request()->post('id');
            $this->authorize($data['id']);
            $data['template'] = $this->templateModel->find($data['id']);
        }

        return view('admin.includes.modals.template', $data);
    }

    /**
     * Get template(s) data
     *
     * @param  int|null $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index($id = null)
    {
        $data['rel_type'] = request()->post('rel_type');
        $data['rel_id'] = request()->post('rel_id');

        $where = ['type' => $data['rel_type']];
        $data['templates'] = $this->templateModel->get($id, $where);

        if (is_numeric($id)) {
            $template = $this->templateModel->find($id);

            return response()->json([
                'data' => $template,
            ]);
        }

        return view('admin.includes.templates', $data);
    }

    /**
     * Manage template
     *
     * @param  int|null $id
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function template($id = null)
    {
        $content = request()->post('content', false);

        $content = html_purify($content);

        $data['name'] = request()->post('name');
        $data['content'] = $content;
        $data['addedfrom'] = get_staff_user_id();
        $data['type'] = request()->post('rel_type');

        // so when modal is submitted, it returns to the proposal/contract that was being edited.
        $relId = request()->post('rel_id');

        if (is_numeric($id)) {
            $this->authorize($id);
            $success = $this->templateModel->update($id, $data);
            $message = _l('template_updated');
        } else {
            $success = $this->templateModel->create($data);
            $message = _l('template_added');
        }

        if ($success) {
            set_alert('success', $message);
        }

        return redirect(
            $data['type'] == 'contracts' ?
            admin_url('contracts/contract/' . $relId) :
            admin_url('proposals/list_proposals/' . $relId)
        );
    }

    /**
     * Delete template by given id
     *
     * @param  int $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function delete($id)
    {
        $this->authorize($id);

        $this->templateModel->destroy($id);

        return response()->json([
            'success' => true,
        ]);
    }

    /**
     * Authorize the template for update/delete
     *
     * @param  int $id
     *
     * @return void
     */
    protected function authorize($id)
    {
        $template = $this->templateModel->find($id);

        if ($template->addedfrom != get_staff_user_id() && !is_admin()) {
            ajax_access_denied();
        }
    }
}
