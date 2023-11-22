<?php

namespace App\Http\Controllers;

use App\Models\RolesModel;

class RolesController extends AdminController
{
    /**
     * List all staff roles.
     */
    public function index()
    {
        if (!has_permission('roles', '', 'view')) {
            access_denied('roles');
        }

        if (request()->ajax()) {
            return $this->app->getTableData('roles');
        }

        $data['title'] = trans('all_roles');
        return view('admin.roles.manage', $data);
    }

    /**
     * Add new role or edit existing one.
     *
     * @param string $id
     */
    public function role($id = '')
    {
        if (!has_permission('roles', '', 'view')) {
            access_denied('roles');
        }

        if (request()->post()) {
            if ($id == '') {
                if (!has_permission('roles', '', 'create')) {
                    access_denied('roles');
                }

                $id = app(RolesModel::class)->add(request()->input());
                if ($id) {
                    set_alert('success', _l('added_successfully', _l('role')));
                    return redirect(admin_url('roles/role/' . $id));
                }
            } else {
                if (!has_permission('roles', '', 'edit')) {
                    access_denied('roles');
                }

                $success = app(RolesModel::class)->update(request()->input(), $id);
                if ($success) {
                    set_alert('success', _l('updated_successfully', _l('role')));
                }

                return redirect(admin_url('roles/role/' . $id));
            }
        }

        if ($id == '') {
            $title = _l('add_new', _l('role_lowercase'));
        } else {
            $data['roleStaff'] = app(RolesModel::class)->getRoleStaff($id);
            $role = app(RolesModel::class)->get($id);
            $data['role'] = $role;
            $title = _l('edit', _l('role_lowercase')) . ' ' . $role->name;
        }

        $data['title'] = $title;
        return view('admin.roles.role', $data);
    }

    /**
     * Delete role from the database.
     *
     * @param string $id
     */
    public function delete($id)
    {
        if (!has_permission('roles', '', 'delete')) {
            access_denied('roles');
        }

        if (!$id) {
            return redirect(admin_url('roles'));
        }

        $response = app(RolesModel::class)->delete($id);

        if (is_array($response) && isset($response['referenced'])) {
            set_alert('warning', _l('is_referenced', _l('role_lowercase')));
        } elseif ($response == true) {
            set_alert('success', _l('deleted', _l('role')));
        } else {
            set_alert('warning', _l('problem_deleting', _l('role_lowercase')));
        }

        return redirect(admin_url('roles'));
    }
}
