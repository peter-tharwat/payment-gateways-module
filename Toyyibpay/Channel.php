<?php

namespace App\PaymentChannels\Drivers\Toyyibpay;

use App\Models\Order;
use App\Models\PaymentChannel;
use App\PaymentChannels\IChannel;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Instamojo\Instamojo;

class Channel implements IChannel
{
    protected $currency;
    protected $order_session_key;
    protected $test_mode;
    protected $toyyibpay_key;
    protected $toyyibpay_category;

    public function __construct(PaymentChannel $paymentChannel)
    {
        $this->currency = currency();
        $this->order_session_key = 'toyyibpay.payments.order_id';

        $this->test_mode = env('TOYYIBPAY_TEST_MODE');
        $this->toyyibpay_key = env('TOYYIBPAY_KEY');
        $this->toyyibpay_category = env('TOYYIBPAY_CATEGORY');
    }

    public function paymentRequest(Order $order)
    {
        $user = $order->user;

        $data = [
            'userSecretKey' => $this->toyyibpay_key,
            'categoryCode' => $this->toyyibpay_category,
            'billName' => 'Ecommerce Cart Payment',
            'billDescription' => 'Payment Using ToyyibPay',
            'billPriceSetting' => 1,
            'billPayorInfo' => 1,
            'billAmount' => $order->total_amount,
            'billReturnUrl' => $this->makeCallbackUrl('return'),
            'billCallbackUrl' => $this->makeCallbackUrl('return'),
            'billExternalReferenceNo' => $order->id,
            'billTo' => $user->full_name,
            'billEmail' => $user->email,
            'billPhone' => $user->mobile,
            'billSplitPayment' => 0,
            'billSplitPaymentArgs' => '',
            'billPaymentChannel' => 2,
            'billContentEmail' => 'Thank you for purchasing our product!',
            'billChargeToCustomer' => 2
        ];

        session()->put($this->order_session_key, $order->id);

        if ($this->test_mode) {
            $site_url = 'https://dev.toyyibpay.com/';
        } else {
            $site_url = 'https://toyyibpay.com/';
        }

        $url = $site_url . 'index.php/api/createBill';

        $client = new Client();
        $response = $client->request('POST', $url, $data);
        $obj = json_decode($response->getBody());

        $billcode = $obj[0]['BillCode'];

        return $site_url . $billcode;
    }

    private function makeCallbackUrl($status)
    {
        return url("/payments/verify/Payhere?status=$status");
    }

    public function verify(Request $request)
    {
        try {
            $order_id = session()->get($this->order_session_key, null);
            session()->forget($this->order_session_key);

            $user = auth()->user();

            $order = Order::where('id', $order_id)
                ->where('user_id', $user->id)
                ->first();

            if (!empty($order)) {
                $orderStatus = Order::$fail;

                if ($request->get('status_id') == 1) {
                    $orderStatus = Order::$paying;
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
}
