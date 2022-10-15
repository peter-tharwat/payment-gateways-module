<?php

namespace App\PaymentChannels\Drivers\Ngenius;

use App\Models\Order;
use App\Models\PaymentChannel;
use App\PaymentChannels\IChannel;
use Illuminate\Http\Request;

class Channel implements IChannel
{
    protected $currency;
    protected $order_session_key;
    protected $test_mode;
    protected $outlet_id;
    protected $api_key;

    public function __construct(PaymentChannel $paymentChannel)
    {
        $this->currency = currency();
        $this->order_session_key = 'ngenius.payments.order_id';

        $this->test_mode = env('NGENIUS_TEST_MODE'); // set your outlet reference/ID value here (example only)
        $this->outlet_id = env('NGENIUS_OUTLET_ID'); // set your outlet reference/ID value here (example only)
        $this->api_key = env('NGENIUS_API_KEY');
    }

    public function paymentRequest(Order $order)
    {
        $user = $order->user;

        $payment = new \StdClass();
        $payment->action = "SALE"; // Transaction mode ("AUTH" = authorize only, no automatic settle/capture, "SALE" = authorize + automatic settle/capture)

        $payment->amount = new \stdClass();
        $payment->amount->currencyCode = $this->currency; // Payment currency ('AED' only for now)
        $payment->amount->value = $order->total_amount; // Minor units (1000 = 10.00 AED)

        $payment->language = "en"; // Payment page language ('en' or 'ar' only)
        $payment->merchantOrderReference = time(); // Payment page language ('en' or 'ar' only)

        $payment->merchantAttributes = new \stdClass();
        $payment->merchantAttributes->redirectUrl = $this->makeCallbackUrl();

        $payment = json_encode($payment);

        $outletRef = $this->outlet_id;
        $txnServiceURL = $this->getUrl('gateway') . "/transactions/outlets/$outletRef/orders";             // set the transaction service URL (example only)
        $access_token = $this->getAccessToken();

        $paymentCreateHeaders = array("Authorization: Bearer " . $access_token, "Content-Type: application/vnd.ni-payment.v2+json", "Accept: application/vnd.ni-payment.v2+json");
        $paymentCreateResponse = $this->invokeCurlRequest("POST", $txnServiceURL, $paymentCreateHeaders, $payment);

        $paymentCreateResponse = json_decode($paymentCreateResponse);

        $paymentLink = $paymentCreateResponse->_links->payment->href;     // the link to the payment page for redirection (either full-page redirect or iframe)
        $paymentReference = $paymentCreateResponse->reference;              // the reference to the order, which you should store in your records for future interaction with this order

        session()->put($this->order_session_key, $order->id);

        return $paymentLink;
    }

    public function verify(Request $request)
    {
        $data = $request->all();

        try {
            $order_id = session()->get($this->order_session_key, null);
            session()->forget($this->order_session_key);

            $user = auth()->user();

            $order = Order::where('id', $order_id)
                ->where('user_id', $user->id)
                ->first();

            if (!empty($order)) {
                $orderStatus = Order::$fail;

                if (!empty($data['ref'])) {
                    $paymentCheckURL = self::getUrl('gateway') . "/transactions/outlets/{$this->outlet_id}/orders/{$data['ref']}";             // set the transaction service URL (example only)
                    $access_token = self::getAccessToken();

                    $headers = array("Authorization: Bearer " . $access_token);
                    $paymentStatusResponse = self::invokeCurlRequest("GET", $paymentCheckURL, $headers, null);
                    $paymentStatusResponse = json_decode($paymentStatusResponse);

                    if ($paymentStatusResponse->_embedded->payment[0]->state == "FAILED") {
                        $orderStatus = Order::$fail;
                    } else if ($paymentStatusResponse->_embedded->payment[0]->state == "CAPTURED") {
                        $orderStatus = Order::$paying;
                    }
                }

                $order->update([
                    'status' => $orderStatus,
                ]);

            }

            return $order;
        } catch (\Exception $e) {
            print('Error: ' . $e->getMessage());
        }
    }

    private function makeCallbackUrl()
    {
        return url("/payments/verify/Ngenius");
    }

    private function getUrl($key)
    {
        $mode = self::getMode();
        $url['sandbox']['identity'] = "https://api-gateway.sandbox.ngenius-payments.com/identity/auth/access-token";
        $url['sandbox']['gateway'] = "https://api-gateway.sandbox.ngenius-payments.com";
        // sandbox urls do not work as the identity is not retrived by sandbox identity

        $url['real']['identity'] = "https://identity-uat.ngenius-payments.com/auth/realms/ni/protocol/openid-connect/token";
        $url['real']['gateway'] = "https://api-gateway-uat.ngenius-payments.com";

        return $url[$mode][$key];
    }

    //sandbox or real
    private function getMode()
    {
        $sandbox = $this->test_mode; //check from db or env
        // sandbox urls do not work as the identity is not retrived by sandbox identity

        return $sandbox ? "sandbox" : "real";
    }

    private function getAccessToken()
    {
        $apikey = $this->api_key; // set your service account API key (example only)
        $idServiceURL = $this->getUrl('identity');                   // set the identity service URL (example only)

        $tokenHeaders = array("Authorization: Basic $apikey", "Content-Type: application/x-www-form-urlencoded");
        $tokenResponse = $this->invokeCurlRequest("POST", $idServiceURL, $tokenHeaders, http_build_query(array('grant_type' => 'client_credentials')));

        $tokenResponse = json_decode($tokenResponse);
        //dd($tokenResponse);
        $access_token = $tokenResponse->access_token;

        return $access_token;
    }

    private function invokeCurlRequest($type, $url, $headers, $post)
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if ($type == "POST") {

            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);

        }

        $server_output = curl_exec($ch);
        // print_r($server_output);
        // exit();
        curl_close($ch);

        return $server_output;
    }
}
