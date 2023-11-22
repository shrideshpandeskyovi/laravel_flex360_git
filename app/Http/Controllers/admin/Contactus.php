<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ContactUsModel;
use App\Models\StaffModel;

class ContactUsController extends Controller
{
    protected $contactUsModel;
    protected $staffModel;

    public function __construct(ContactUsModel $contactUsModel, StaffModel $staffModel)
    {
        $this->contactUsModel = $contactUsModel;
        $this->staffModel = $staffModel;
    }

    public function index()
    {
        return redirect()->route('admin.home');
    }

    public function add(Request $request)
    {
        $data['title'] = _l('Contact Us');
        $isReset = false;

        if ($request->isMethod('post')) {
            $postData = $request->input();
            $inserted = $this->contactUsModel->add($postData);

            if ($inserted) {
                session()->flash('alert-success', "Thank you for your interest. We will contact you soon.");
            } else {
                session()->flash('alert-warning', _l('something_went_wrong'));
            }

            return redirect()->route('contactus.add');
        }

        return view('admin.candidates.contactus', $data);
    }
}
