<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Mods extends AdminController
{
    public function __construct()
    {
        parent::__construct();

        /**
         * Modules are only accessible by administrators
         */
        if (!is_admin()) {
            redirect(admin_url());
        }
    }

    public function index()
    {
        $data['modules'] = $this->app_modules->get();
        $data['title']   = _l('modules');
        $this->load->view('admin/modules/list', $data);
    }

    public function activate($name)
    {
        $this->app_modules->activate($name);
        $this->redirectToModules();
    }

    public function deactivate($name)
    {
        $this->app_modules->deactivate($name);
        $this->redirectToModules();
    }

    public function uninstall($name)
    {
        $this->app_modules->uninstall($name);
        $this->redirectToModules();
    }

    public function upload()
    {
        $this->load->library('app_module_installer');
        $data = $this->app_module_installer->from_upload();

        if ($data['error']) {
            set_alert('danger', $data['error']);
        } else {
            set_alert('success', 'Module uploaded successfully');
        }

        $this->redirectToModules();
    }

    public function upgrade_database($name)
    {
        $result = $this->app_modules->upgrade_database($name);

        // Possible error
        if (is_string($result)) {
            set_alert('danger', $result);
        } else {
            set_alert('success', 'Database Upgraded Successfully');
        }

        $this->redirectToModules();
    }

    public function update_version($name)
    {
        if ($this->app_modules->new_version_available($name)) {
            $this->app_modules->update_to_new_version($name);
        }

        $this->redirectToModules();
    }

    private function redirectToModules()
    {
        redirect(admin_url('modules'));
    }
}
