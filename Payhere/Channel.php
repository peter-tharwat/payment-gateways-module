<?php

namespace App\PaymentChannels\Drivers\Payhere;

use App\Models\Order;
use App\Models\PaymentChannel;
use App\PaymentChannels\IChannel;
use Illuminate\Http\Request;
use Instamojo\Instamojo;

class Channel implements IChannel
{
    protected $currency;
    protected $order_session_key;
    protected $merchant_id;
    protected $api_secret;

    public function __construct(PaymentChannel $paymentChannel)
    {
        $this->currency = currency();
        $this->order_session_key = 'payhere.payments.order_id';

        $this->merchant_id = env('PAYHERE_MERCHANT_ID');
        $this->api_secret = env('PAYHERE_SECRET');
    }

    public function paymentRequest(Order $order)
    {
        $user = $order->user;

        $data = [
            'action_url' => $this->action_url(),
            'merchant_id' => $this->merchant_id,
            'return_url' => $this->makeCallbackUrl('return'),
            'cancel_url' => $this->makeCallbackUrl('cancel'),
            'notify_url' => $this->makeCallbackUrl('notify'),
            'order_id' => $order->id,
            'currency' => $this->currency,
            'amount' => $order->total_amount,
            'first_name' => $user->full_name,
            'last_name' => '',
            'email' => $user->email,
            'phone' => $user->mobile,
            'address' => $user->address,
            'city' => $user->getRegionByTypeId($user->city_id),
        ];

        session()->put($this->order_session_key, $order->id);

        return view('web.default.cart.channels.payhere_checkout_form', $data);
    }

    private function action_url()
    {
        return env('PAYHERE_TEST_MODE') ? 'https://sandbox.payhere.lk/pay/checkout' : 'https://www.payhere.lk/pay/checkout';
    }

    private function makeCallbackUrl($status)
    {
        return url("/payments/verify/Payhere?status=$status");
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

                if (!empty($data['status']) and $data['status'] == 'notify') {
                    $merchant_id = $_POST['merchant_id'];
                    $order_id = $_POST['order_id'];
                    $payhere_amount = $_POST['payhere_amount'];
                    $payhere_currency = $_POST['payhere_currency'];
                    $status_code = $_POST['status_code'];
                    $md5sig = $_POST['md5sig'];

                    $local_md5sig = strtoupper(md5($merchant_id . $order_id . $payhere_amount . $payhere_currency . $status_code . strtoupper(md5($this->api_secret))));

                    if (($local_md5sig === $md5sig) and ($status_code == 2)) {
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
}
