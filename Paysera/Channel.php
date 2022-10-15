<?php

namespace App\PaymentChannels\Drivers\Paysera;

use App\Models\Order;
use App\Models\PaymentChannel;
use App\PaymentChannels\IChannel;
use Illuminate\Http\Request;
use Omnipay\Omnipay;

class Channel implements IChannel
{
    protected $currency;
    protected $api_key;
    protected $api_secret;
    protected $test_mode;

    /**
     * Channel constructor.
     * @param PaymentChannel $paymentChannel
     */
    public function __construct(PaymentChannel $paymentChannel)
    {
        $this->currency = currency();
        $this->api_key = env('PAYSERA_KEY');
        $this->api_secret = env('PAYSERA_SECRET');
        $this->test_mode = env('PAYSERA_TEST_MODE');
    }

    public function paymentRequest(Order $order)
    {
        $generalSettings = getGeneralSettings();
        $user = $order->user;

        $gateway = Omnipay::create('Paysera');
        $gateway->setProjectId($this->api_key);
        $gateway->setPassword($this->api_secret);


        // Example card (actually customer) data
        $card = [
            'email' => $user->email ?? $generalSettings['site_email'],
            'billingFirstName' => $user->full_name,
            'billingLastName' => '',
            'billingPhone' => $user->mobile,
            'billingCompany' => $generalSettings['site_name'],
            'billingAddress1' => '',
            'billingCity' => '',
            'billingPostcode' => '',
            'billingCountry' => '',
        ];

        // Send purchase request
        $response = $gateway->purchase(
            [
                'language' => 'ENG',
                'transactionId' => $order->id,
                'paymentMethod' => 'hanzaee',
                'amount' => $order->total_amount,
                'currency' => $this->currency,
                'testMode' => $this->test_mode,
                'returnUrl' => $this->makeCallbackUrl($order, 'success'),
                'cancelUrl' => $this->makeCallbackUrl($order, 'cancel'),
                'notifyUrl' => $this->makeCallbackUrl($order, 'notify'),
                'card' => $card,
            ]
        )->send();

        if ($response->isRedirect()) {
            return $response->redirect();
        }
    }

    private function makeCallbackUrl($order, $status)
    {
        return url("/payments/verify/Robokassa?status=$status&order_id=$order->id");
    }

    public function verify(Request $request)
    {
        $data = $request->all();
        $order_id = $data['order_id'];

        $user = auth()->user();

        $order = Order::where('id', $order_id)
            ->where('user_id', $user->id)
            ->first();

        // Setup payment gateway
        $gateway = Omnipay::create('Paysera');
        $gateway->setProjectId($this->api_key);
        $gateway->setPassword($this->api_secret);

        // Accept the notification
        $response = $gateway->acceptNotification()->send();

        if ($response->isSuccessful() and !empty($order)) {
            // Mark the order as paid

            $order->update([
                'status' => Order::$paying
            ]);

            return $order;
        }

        if (!empty($order)) {
            $order->update([
                'status' => Order::$fail
            ]);
        }

        return $order;
    }
}
