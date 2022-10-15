<?php

namespace App\PaymentChannels\Drivers\KlarnaCheckout;

use App\Models\Order;
use App\Models\PaymentChannel;
use App\PaymentChannels\IChannel;
use Illuminate\Http\Request;
use Omnipay\Omnipay;

class Channel implements IChannel
{
    protected $currency;
    protected $test_mode;
    protected $username;
    protected $secret;
    protected $api_version;

    /**
     * Channel constructor.
     * @param PaymentChannel $paymentChannel
     */
    public function __construct(PaymentChannel $paymentChannel)
    {
        $this->currency = currency();
        $this->test_mode = env('KLARNA_CHECKOUT_TEST_MODE');
        $this->username = env('KLARNA_CHECKOUT_USERNAME');
        $this->secret = env('KLARNA_CHECKOUT_SECRET');
        $this->api_version = env('KLARNA_CHECKOUT_API_VERSION');
    }

    protected function makeGateway()
    {
        $gateway = Omnipay::create('\MyOnlineStore\Omnipay\KlarnaCheckout\Gateway');

        $gateway->initialize([
            'username' => $this->username,
            'secret' => $this->secret,
            'api_region' => $this->api_version,
            'testMode' => $this->test_mode // Optional, default: true
        ]);

        return $gateway;
    }

    public function paymentRequest(Order $order)
    {
        $generalSettings = getGeneralSettings();
        $user = $order->user;

        $gateway = $this->makeGateway();


        $data = [
            'amount' => $order->total_amount,
            'tax_amount' => 0,
            'currency' => $this->currency,
            'locale' => 'SE',
            'purchase_country' => 'SE',

            'notify_url' => $this->makeCallbackUrl($order, 'notify'), // https://developers.klarna.com/api/#checkout-api__ordermerchant_urls__validation
            'return_url' => $this->makeCallbackUrl($order, 'return'), // https://developers.klarna.com/api/#checkout-api__ordermerchant_urls__checkout
            'terms_url' => $this->makeCallbackUrl($order, 'terms'), // https://developers.klarna.com/api/#checkout-api__ordermerchant_urls__terms
            'validation_url' => $this->makeCallbackUrl($order, 'validation'), // https://developers.klarna.com/api/#checkout-api__ordermerchant_urls__validation

        ];

        $items = [];

        foreach ($order->orderItems as $orderItem) {

            $items[] = [
                'type' => 'virtual',
                'name' => 'Cart item:' . $orderItem->id,
                'quantity' => 1,
                'tax_rate' => $orderItem->tax,
                'price' => $orderItem->amount,
                'unit_price' => $orderItem->amount,
                'total_tax_amount' => $orderItem->tax_price,
            ];

        }

        $data['items'] = $items;

        // Send purchase request
        try {

            $response = $gateway->authorize($data)->send()->getData();

        } catch (\Exception $exception) {
            dd($exception);
        }

        if (!empty($response)) {
            return $response;
        }
    }

    private function makeCallbackUrl($order, $status)
    {
        return url("/payments/verify/KlarnaCheckout?status=$status&order_id=$order->id");
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
        $gateway = $this->makeGateway();

        $response = $gateway->fetchTransaction([
            'transactionReference' => 'a5bec272-d68d-4df9-9fdd-8e35e51f92ab'
        ])->send();

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
