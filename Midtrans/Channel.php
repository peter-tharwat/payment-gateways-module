<?php

namespace App\PaymentChannels\Drivers\Midtrans;

use App\Models\Order;
use App\Models\PaymentChannel;
use App\PaymentChannels\IChannel;
use Illuminate\Http\Request;
use Midtrans\Config as MidtransConfig;

class Channel implements IChannel
{

    /**
     * Channel constructor.
     * @param PaymentChannel $paymentChannel
     */
    public function __construct(PaymentChannel $paymentChannel)
    {
        MidtransConfig::$serverKey = env('MIDTRANS_SERVER_KEY');
        MidtransConfig::$clientKey = env('MIDTRANS_CLIENT_KEY');
        MidtransConfig::$isProduction = (env('APP_ENV') == 'production');
        MidtransConfig::$isSanitized = true;
        MidtransConfig::$is3ds = true;
    }

    public function paymentRequest(Order $order)
    {
        $user = $order->user;
        $price = $order->total_amount;
        $generalSettings = getGeneralSettings();


        $transaction_details = array(
            'order_id' => $order->id,
            'gross_amount' => (int)$price,
        );

        $customer_details = array(
            'first_name' => $user->full_name,
            'email' => $user->email ?? $generalSettings['site_email'],
            'phone' => $user->mobile,
        );

        $params = array(
            'transaction_details' => $transaction_details,
            'customer_details' => $customer_details,
        );

        $paymentUrl = \Midtrans\Snap::createTransaction($params)->redirect_url;

        return $paymentUrl;
    }

    private function makeCallbackUrl($order, $status)
    {
        // success => /payments/verify/Midtrans?status=success
        // cancel => /payments/verify/Midtrans?status=cancel
        // error => /payments/verify/Midtrans?status=error
    }

    public function verify(Request $request)
    {
dd($request->all());
        if (!empty($order)) {
            $order->update(['status' => Order::$fail]);
        }

        return null;
    }
}
