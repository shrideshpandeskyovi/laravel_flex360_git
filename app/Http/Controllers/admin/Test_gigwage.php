<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class TestGigwageController extends AppController
{
    public $apiSecret;
    public $apiKey;

    public function __construct()
    {
        parent::__construct();
        $this->apiSecret = config('gigwage.api_secret'); // Make sure to define this in your config files
        $this->apiKey = config('gigwage.api_key'); // Make sure to define this in your config files
    }

    public function sendPayment($contractorId = 7614)
    {
        $nonce = time() . '@' . random_strings(10);
        $payload = [
            'payment' => [
                'contractor_id' => $contractorId,
                'line_items' => [[
                    'amount' => "1.00",
                    'reason' => 'bonus',
                    'reimbursement' => false
                ]],
                'nonce' => $nonce,
            ],
        ];

        $response = Http::withHeaders($this->getGigwageHeaders())
            ->post('https://sandbox.gigwage.com/api/v1/payments', ['payload' => $payload]);

        return response()->json($response->json());
    }

    public function listSentPayments()
    {
        $timestamp = round(microtime(true) * 1000);
        $method = 'GET';
        $endpoint = '/api/v1/payments';
        $payload = json_encode([]);
        $data = $timestamp . $method . $endpoint;
        $signature = hash_hmac('sha256', $data, $this->apiSecret);

        $response = Http::withHeaders($this->getGigwageHeaders($timestamp, $method, $endpoint, $signature))
            ->get('https://sandbox.gigwage.com/api/v1/payments');

        return response()->json($response->json());
    }

    public function showPayment($paymentId = '')
    {
        if (empty($paymentId)) {
            return 'Please enter payment id';
        }

        $timestamp = round(microtime(true) * 1000);
        $method = 'GET';
        $endpoint = '/api/v1/payments/' . $paymentId;
        $payload = json_encode([]);
        $data = $timestamp . $method . $endpoint;
        $signature = hash_hmac('sha256', $data, $this->apiSecret);

        $response = Http::withHeaders($this->getGigwageHeaders($timestamp, $method, $endpoint, $signature))
            ->get('https://sandbox.gigwage.com/api/v1/payments/' . $paymentId);

        return response()->json($response->json());
    }

    public function delete($contractorId)
    {
        if (empty($contractorId)) {
            return 'Please enter contractor id';
        }

        $response = Http::withHeaders($this->getGigwageHeaders())
            ->delete("https://sandbox.gigwage.com/api/v1/contractors/{$contractorId}");

        return response()->json($response->json());
    }

    private function getGigwageHeaders($timestamp = null, $method = null, $endpoint = null, $signature = null)
    {
        $timestamp = $timestamp ?? round(microtime(true) * 1000);
        $method = $method ?? 'GET';
        $endpoint = $endpoint ?? '/api/v1/payments';
        $data = $timestamp . $method . $endpoint;
        $signature = $signature ?? hash_hmac('sha256', $data, $this->apiSecret);

        return [
            'Content-Type' => 'application/json',
            'X-Gw-Api-Key' => $this->apiKey,
            'X-Gw-Timestamp' => $timestamp,
            'X-Gw-Method' => $method,
            'X-Gw-Endpoint' => $endpoint,
            'X-Gw-Signature' => $signature,
        ];
    }
}
