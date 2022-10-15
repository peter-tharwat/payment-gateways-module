<?php

namespace App\PaymentChannels\Drivers\Braintree;

use App\Models\Order;
use App\Models\PaymentChannel;
use App\PaymentChannels\IChannel;
use Illuminate\Http\Request;
use Omnipay\Omnipay;

class Channel implements IChannel
{
    protected $currency;
    protected $test_mode;
    protected $merchant_id;
    protected $public_key;
    protected $private_key;

    /**
     * Channel constructor.
     * @param PaymentChannel $paymentChannel
     */
    public function __construct(PaymentChannel $paymentChannel)
    {
        $this->currency = currency();

        $this->test_mode = env('BRAINTREE_TEST_MODE');
        $this->merchant_id = env('BRAINTREE_MERCHANT_ID');
        $this->public_key = env('BRAINTREE_PUBLIC_KEY');
        $this->private_key = env('BRAINTREE_PRIVATE_KEY');
    }

    protected function makeGateway()
    {
        $gateway = Omnipay::create('Braintree');

        $gateway->setMerchantId($this->merchant_id);
        $gateway->setPublicKey($this->public_key);
        $gateway->setPrivateKey($this->private_key);
        $gateway->setTestMode($this->test_mode);

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

            $reqData = $this->createPaymentData($order);
            $reqData['token'] = $gateway->clientToken()->send()->getToken();

            $response = $gateway->purchase($reqData)->send();

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
            'transactionId' => $order->id,
            'amount' => $order->total_amount,
            'currency' => $this->currency,
            'testMode' => $this->test_mode,
            'returnUrl' => $this->makeCallbackUrl($order, 'success'),
            'cancelUrl' => $this->makeCallbackUrl($order, 'cancel'),
            'notifyUrl' => $this->makeCallbackUrl($order, 'notify'),
            'card' => $card,
        ];
    }

    private function makeCallbackUrl($order, $status)
    {
        return url("/payments/verify/Braintree?status=$status&order_id=$order->id");
    }

    public function verify(Request $request)
    {
        $data = $request->all();
        $order_id = $data['order_id'];

        $user = auth()->user();

        $order = Order::where('id', $order_id)
            ->where('user_id', $user->id)
            ->first();

        if (!empty($order)) {
            $orderStatus = Order::$fail;

            // Setup payment gateway
            try {
                $gateway = $this->makeGateway();

                $reqData = $this->createPaymentData($order);
                $reqData['token'] = $gateway->clientToken()->send()->getToken();

                $response = $gateway->purchase($reqData)->send();

            } catch (\Exception $exception) {
                //dd($exception);
                throw new \Exception($exception->getMessage(), $exception->getCode());
            }

            if ($response->isSuccessful()) {
                $orderStatus = Order::$paying;
            }

            $order->update([
                'status' => $orderStatus
            ]);
        }

        return $order;
    }
}
