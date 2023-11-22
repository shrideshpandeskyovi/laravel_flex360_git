<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Staff; // Make sure to replace 'Staff' with the actual model name and namespace

class FlexpertController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth'); // Add any middleware if needed
    }

    public function index()
    {
        return view('admin.candidates.flexpert');
    }
}
