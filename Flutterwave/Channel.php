<?php

namespace App\PaymentChannels\Drivers\Flutterwave;

use App\Models\Order;
use App\Models\PaymentChannel;
use App\PaymentChannels\IChannel;
use Illuminate\Http\Request;
use KingFlamez\Rave\Facades\Rave as FlutterWave;

class Channel implements IChannel
{

    /**
     * Channel constructor.
     * @param PaymentChannel $paymentChannel
     */
    public function __construct(PaymentChannel $paymentChannel)
    {

    }

    public function paymentRequest(Order $order)
    {
        $user = $order->user;
        $price = $order->total_amount;
        $generalSettings = getGeneralSettings();
        $currency = currency();

        $reference = FlutterWave::generateReference();

        $data = [
            'payment_options' => 'card,banktransfer',
            'amount' => $price,
            'email' => $user->email ?? $generalSettings['site_email'],
            'tx_ref' => $reference,
            'currency' => $currency,
            'redirect_url' => $this->makeCallbackUrl($order),
            'customer' => [
                'email' => $user->email ?? $generalSettings['site_email'],
                "phonenumber" => $user->mobile,
                "name" => $user->full_name
            ],

            "customizations" => [
                "title" => $generalSettings['site_name'] . ' payment',
                "description" => $generalSettings['site_name'] . ' payment'
            ]
        ];

        $payment = FlutterWave::initializePayment($data);

        if ($payment['status'] !== 'success') {
            // notify something went wrong
            return;
        }

        return $payment['data']['link'];
    }

    private function makeCallbackUrl($order)
    {
        $callbackUrl = route('payment_verify', [
            'gateway' => 'Flutterwave',
            'order_id' => $order->id
        ]);

        return $callbackUrl;
    }

    public function verify(Request $request)
    {
        $order_id = $request->get('order_id');
        $user = auth()->user();

        $order = Order::where('id', $order_id)
            ->where('user_id', $user->id)
            ->with('user')
            ->first();

        if (!empty($order)) {
            if ($request->get('status') == 'cancelled') {
                if (!empty($order)) {
                    $order->update(['status' => Order::$fail]);
                }

                $toastData = [
                    'title' => trans('cart.fail_purchase'),
                    'msg' => 'You canceled payment request',
                    'status' => 'error'
                ];

                return back()->with(['toast' => $toastData])->withInput();
            } else {
                $transactionID = FlutterWave::getTransactionIDFromCallback();
                $data = FlutterWave::verifyTransaction($transactionID);

                if ($data and $data['data'] and $data['data']['status'] == 'successful') {
                    $order->update([
                        'status' => Order::$paying
                    ]);

                    return $order;
                }
            }
        }


        if (!empty($order)) {
            $order->update(['status' => Order::$fail]);
        }

        return $order;
    }
}
