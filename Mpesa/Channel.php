<?php

namespace App\PaymentChannels\Drivers\Mpesa;

use App\Models\Order;
use App\Models\PaymentChannel;
use App\PaymentChannels\IChannel;
use Gathuku\Mpesa\Facades\Mpesa;
use Illuminate\Http\Request;

class Channel implements IChannel
{
    protected $currency;

    /**
     * Channel constructor.
     * @param PaymentChannel $paymentChannel
     */
    public function __construct(PaymentChannel $paymentChannel)
    {
        $this->currency = currency();
    }

    /**
     * @throws \Exception
     */
    public function paymentRequest(Order $order)
    {
        // TODO:: document => https://beyode.co.ke/mpesa/
        // TODO:: Not Work

        // Send purchase request
        try {
            $user = $order->user;

            $expressResponse = Mpesa::express(
                $order->total_amount,
                '2547112855',
                $order->id,
                'Payment for order ' . $order->id
            );

        } catch (\Exception $exception) {
            dd($exception);
            throw new \Exception($exception->getMessage(), $exception->getCode());
        }
dd($expressResponse);
        return $response['url'];
    }

    private function makeCallbackUrl($order, $status)
    {
        return url("/payments/verify/Mpesa?status=$status&order_id=$order->id");
    }

    public function verify(Request $request)
    {
        try {


        } catch (\Exception $exception) {
            //dd($exception);
            throw new \Exception($exception->getMessage(), $exception->getCode());
        }
    }
}
