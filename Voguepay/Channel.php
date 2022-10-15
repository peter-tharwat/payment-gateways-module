<?php

namespace App\PaymentChannels\Drivers\Voguepay;

use App\Models\Order;
use App\Models\PaymentChannel;
use App\PaymentChannels\IChannel;
use GuzzleHttp\Client;
use Illuminate\Http\Request;

class Channel implements IChannel
{
    protected $currency;
    protected $test_mode;
    protected $voguepay_merchant_id;

    /**
     * Channel constructor.
     * @param PaymentChannel $paymentChannel
     */
    public function __construct(PaymentChannel $paymentChannel)
    {
        $this->currency = currency();
        $this->order_session_key = 'voguepay.payments.order_id';

        $this->test_mode = env('VOGUEPAY_TEST_MODE');
        $this->voguepay_merchant_id = env('VOGUEPAY_MERCHANT_ID');
    }

    public function paymentRequest(Order $order)
    {
        $user = $order->user;

        session()->put($this->order_session_key, $order->id);

        $data = [
            'closedUrl' => $this->makeCallbackUrl('closed'),
            'successUrl' => $this->makeCallbackUrl('success'),
            'failedUrl' => $this->makeCallbackUrl('failed'),
            'test_mode' => $this->test_mode,
            'voguepay_merchant_id' => $this->voguepay_merchant_id,
            'total_amount' => $order->total_amount,
            'currency' => $this->currency,
            'userData' => [
                'name' => $user->full_name,
                'address' => $user->address,
                'city' => $user->getRegionByTypeId($user->city_id),
                'postcode' => 123,
                'phone' => $user->mobile,
                'email' => $user->email,
            ]
        ];

        return view('web.default.cart.channels.voguepay', $data);
    }

    private function makeCallbackUrl($status)
    {
        return url("/payments/verify/Voguepay?status=$status");
    }

    public function verify(Request $request)
    {
        $data = $request->all();
        $status = $data['status'];
        $transaction_id = $data['transaction_id'];

        $order_id = session()->get($this->order_session_key, null);
        session()->forget($this->order_session_key);

        $user = auth()->user();

        $order = Order::where('id', $order_id)
            ->where('user_id', $user->id)
            ->first();

        if (!empty($order)) {
            $orderStatus = Order::$fail;

            if ($status == 'success') {
                if ($this->test_mode) {
                    $url = '//voguepay.com/?v_transaction_id=' . $transaction_id . '&type=json&demo=true';
                } else {
                    $url = '//voguepay.com/?v_transaction_id=' . $transaction_id . '&type=json';
                }

                $client = new Client();
                $response = $client->request('GET', $url);
                $obj = json_decode($response->getBody());

                if ($obj->response_message == 'Approved') {
                    $orderStatus = Order::$paying;
                }
            }

            $order->update([
                'status' => $orderStatus,
                'payment_data' => null,
            ]);
        }

        return $order;
    }
}
