<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class YardstikController extends Controller
{
    public function getCandidate()
    {
        $apiUrl = 'https://api.yardstik-staging.com/candidates';
        $apiKey = '23bbb807d621c38d430c6f7b4435e88a80075500';

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Account ' . $apiKey,
        ])->get($apiUrl);

        if ($response->failed()) {
            echo "HTTP Error #" . $response->status();
        } else {
            $resp['success'] = $response->json();
        }

        echo '<pre>';
        print_r($resp);
    }

    public function createInvite()
    {
        $apiUrl = 'https://api.yardstik-staging.com/candidates';
        $apiKey = '23bbb807d621c38d430c6f7b4435e88a80075500';

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Account ' . $apiKey,
        ])->get($apiUrl);

        if ($response->failed()) {
            echo "HTTP Error #" . $response->status();
        } else {
            $resp['success'] = $response->json();
        }

        echo '<pre>';
        print_r($resp);
    }

    public function getInviteById()
    {
        $apiUrl = 'https://api.yardstik-staging.com/invitations/32ad87b0-d5ed-4715-ad9b-6420d8a1b634';
        $apiKey = '23bbb807d621c38d430c6f7b4435e88a80075500';

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Account ' . $apiKey,
        ])->get($apiUrl);

        if ($response->failed()) {
            echo "HTTP Error #" . $response->status();
        } else {
            $resp = $response->json();

            var_dump($resp);
            die;
        }
    }

    public function test()
    {
        // Assuming Gigwage is a service and create_subscription is a method of that service.
        $gigwageData = app('gigwage')->createSubscription();

        die;
    }
}
