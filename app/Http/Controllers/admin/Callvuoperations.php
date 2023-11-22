<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Exception;

class CallvuOperationsController extends Controller
{
    public function __construct()
    {
        // Constructor logic here
    }

    public function demo()
    {
        return "demo";
    }

    public function getResponseFromCallVu()
    {
        try {
            // Get Call Vu Data
            $callVuPayload = [
                'Flex360ID' => "35"
            ];

            // Assuming you have a CallVuService class to handle the interaction with CallVu
            $callVuService = app(\App\Services\CallVuService::class);
            $callVuSuccess = $callVuService->submitPayloadToCallVu($callVuPayload, 'CALL_VU_GET_USER_DETAIL');
            
            // Use dd() or return response()->json() for better formatting in Laravel
            dd($callVuSuccess);

        } catch (Exception $ex) {
            dd($ex);
        }
    }
}
