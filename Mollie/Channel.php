<?php

namespace App\PaymentChannels\Drivers\Mollie;

use App\Models\Order;
use App\Models\PaymentChannel;
use App\PaymentChannels\IChannel;
use Illuminate\Http\Request;
use Omnipay\Omnipay;

class Channel implements IChannel
{
    protected $currency;
    protected $api_key;

    /**
     * Channel constructor.
     * @param PaymentChannel $paymentChannel
     */
    public function __construct(PaymentChannel $paymentChannel)
    {
        $this->currency = currency();

        $this->api_key = env('MOLLIE_API_KEY');
    }

    protected function makeGateway()
    {
        $gateway = Omnipay::create('Mollie');

        $gateway->setApiKey($this->api_key);

        return $gateway;
    }

    /**
     * @throws \Exception
     */
    public function paymentRequest(Order $order)
    {
        // Send purchase request
        try {
            $gateway = $this->makeGateway();
            $response = $gateway->purchase($this->createPaymentData($order))->send();

        } catch (\Exception $exception) {
//            dd($exception);
            throw new \Exception($exception->getMessage(), $exception->getCode());
        }

        if ($response->isRedirect()) {
            return $response->redirect();
        }
    }

    private function createPaymentData($order)
    {
        $generalSettings = getGeneralSettings();
        $user = $order->user;

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

        return [
            "amount" => $order->total_amount,
            "currency" => $this->currency,
            'orderNumber'  => $order->id,
            "description" => "Pay Cart Items",
            'paymentMethod' => 'klarnapaylater',
            "returnUrl" => $this->makeCallbackUrl($order, 'return'),
            'card' => $card
        ];
    }

    private function makeCallbackUrl($order, $status)
    {
        return url("/payments/verify/Mollie?status=$status&order_id=$order->id");
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
        try {
            $gateway = $this->makeGateway();
            $response = $gateway->purchase($this->createPaymentData($order))->send();

        } catch (\Exception $exception) {
            //dd($exception);
            throw new \Exception($exception->getMessage(), $exception->getCode());
        }

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
