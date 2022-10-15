<?php

namespace App\PaymentChannels\Drivers\Sslcommerz;

use App\Models\Order;
use App\Models\PaymentChannel;
use App\PaymentChannels\IChannel;
use Illuminate\Http\Request;

class Channel implements IChannel
{
    protected $currency;
    protected $order_session_key;

    public function __construct(PaymentChannel $paymentChannel)
    {
        $this->currency = "BDT";//currency();
        $this->order_session_key = 'sslcommerz.payments.order_id';

    }

    public function paymentRequest(Order $order)
    {
        $user = $order->user;

        $postData = [];

        $postData['total_amount'] = $order->total_amount; # You cant not pay less than 10
        $postData['currency'] = "BDT";
        $postData['tran_id'] = substr(md5($order->id), 0, 10); // tran_id must be unique

        $postData['value_a'] = $postData['tran_id'];
        $postData['value_b'] = $order->id;
        $postData['value_c'] = $order->user_id;

        # CUSTOMER INFORMATION
        $postData['cus_name'] = $user->full_name;
        $postData['cus_add1'] = $user->address;
        $postData['cus_city'] = $user->getRegionByTypeId($user->city_id);
        $postData['cus_postcode'] = 123;
        $postData['cus_country'] = $user->getRegionByTypeId($user->country_id);
        $postData['cus_phone'] = $user->mobile;
        $postData['cus_email'] = $user->email;

        $postData['success_url'] = url("/payments/verify/Sslcommerz?status=success");
        $postData['fail_url'] = url("/payments/verify/Sslcommerz?status=fail");
        $postData['cancel_url'] = url("/payments/verify/Sslcommerz?status=cancel");

        session()->put($this->order_session_key, $order->id);

        $sslc = new SSLCommerz();

        # initiate(Transaction Data , false: Redirect to SSLCOMMERZ gateway/ true: Show all the Payement gateway here )
        $payment_options = $sslc->initiate($postData, false);
        if (!is_array($payment_options)) {
            print_r($payment_options);
            $payment_options = array();
        }
    }

    public function verify(Request $request)
    {
        $status = $request->get('status');
        $orderId = session()->get($this->order_session_key, null);
        session()->forget($this->order_session_key);

        $user = auth()->user();

        $order = Order::where('id', $orderId)
            ->where('user_id', $user->id)
            ->with('user')
            ->first();

        if (!empty($order)) {
            $orderStatus = Order::$fail;

            if ($status == 'success') {
                $orderStatus = Order::$paying;
            }

            $order->update([
                'status' => $orderStatus,
            ]);
        }

        return $order;
    }
}
