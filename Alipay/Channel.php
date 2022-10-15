<?php

namespace App\PaymentChannels\Drivers\Alipay;

use App\Models\Order;
use App\Models\PaymentChannel;
use App\PaymentChannels\IChannel;
use Illuminate\Http\Request;
use Omnipay\Omnipay;

class Channel implements IChannel
{
    protected $currency;
    protected $test_mode;
    protected $partner;
    protected $key;
    protected $privateKey;

    /**
     * Channel constructor.
     * @param PaymentChannel $paymentChannel
     */
    public function __construct(PaymentChannel $paymentChannel)
    {
        $this->currency = currency();

        $this->test_mode = env('ALIPAY_TEST_MODE');
        $this->partner = env('ALIPAY_PARTNER');
        $this->key = env('ALIPAY_KEY');
        $this->privateKey = env('ALIPAY_PRIVATE_KEY');
    }

    protected function makeGateway($order)
    {
        $gateway = Omnipay::create('GlobalAlipay_Web');

        $gateway->setPartner($this->partner);
        $gateway->setKey($this->key); //for sign_type=MD5
        $gateway->setPrivateKey($this->privateKey); //for sign_type=RSA
        $gateway->setReturnUrl($this->makeCallbackUrl($order, 'return'));
        $gateway->setNotifyUrl($this->makeCallbackUrl($order, 'notify'));
        $gateway->setEnvironment($this->test_mode ? 'sandbox' : ''); //for Sandbox Test (Web/Wap)

        return $gateway;
    }

    /**
     * @throws \Exception
     */
    public function paymentRequest(Order $order)
    {
        // Send purchase request
        try {
            $gateway = $this->makeGateway($order);
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

        return [
            "out_trade_no" => $order->id,
            "subject" => 'Pay Cart Items',
            "total_fee" => $order->total_amount,
            "currency" => $this->currency,
        ];
    }

    private function makeCallbackUrl($order, $status)
    {
        return url("/payments/verify/Alipay?status=$status&order_id=$order->id");
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
                $gateway = $this->makeGateway($order);

                $reqData = $this->createPaymentData($order);
                $reqData['request_params'] = array_merge($_GET, $_POST);

                $response = $gateway->completePurchase($reqData)->send();

            } catch (\Exception $exception) {
                //dd($exception);
                throw new \Exception($exception->getMessage(), $exception->getCode());
            }

            if ($response->isPaid()) {
                $orderStatus = Order::$paying;
            }

            $order->update([
                'status' => $orderStatus
            ]);
        }

        return $order;
    }
}
