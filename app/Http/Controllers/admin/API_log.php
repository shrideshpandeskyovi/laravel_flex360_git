<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\ApiLog;

class ApiLogsController extends Controller
{
    protected $apiLog;

    public function __construct(ApiLog $apiLog)
    {
        $this->apiLog = $apiLog; 
    }

    public function index()
    {
        if(request()->ajax()) {
            return datatables()->of($this->apiLog->all())->toJson();
        }
        
        return view('api_logs.index', [
            'title' => 'API Logs'
        ]);
    }

    public function test()
    {
        phpinfo();
    }

}