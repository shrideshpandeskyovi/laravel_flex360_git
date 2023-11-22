<?php

defined('BASEPATH') or exit('No direct script access allowed');

class MiscAdditional extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        // $this->load->model('announcements_model');
    }

    /* List all announcements */
    public function index()
    {
        $data['title'] = _l('misc');
        $this->load->view('admin/miscadditional/view', $data);
    }
}
