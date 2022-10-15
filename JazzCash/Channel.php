<?php

namespace App\PaymentChannels\Drivers\JazzCash;

use App\Models\Order;
use App\Models\PaymentChannel;
use App\PaymentChannels\IChannel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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
        // Send purchase request
        try {

            $data = \AKCybex\JazzCash\Facades\JazzCash::request()
                ->setAmount($order->total_amount)
                ->toArray();

        } catch (\Exception $exception) {
            dd($exception);
            throw new \Exception($exception->getMessage(), $exception->getCode());
        }

        $data['ppmpf_1'] = $order->id;
        $data['ppmpf_2'] = $order->user_id;

        return view('web.default.cart.channels.jazzCash', ['data' => $data]);
    }

    private function makeCallbackUrl($order, $status)
    {
        return url("/payments/verify/JazzCash?status=$status&order_id=$order->id");
    }

    public function verify(Request $request)
    {
        try {

            $orderId = $request->get('ppmpf_1');
            $buyerId = $request->get('ppmpf_2');

            $order = Order::where('id', $orderId)
                ->where('user_id', $buyerId)
                ->first();

            if (!empty($order)) {
                $orderStatus = Order::$fail;

                Auth::loginUsingId($buyerId);

                $jazzcash = \AKCybex\JazzCash\Facades\JazzCash::response();

                if ($jazzcash->code() == 000) {
                    $orderStatus = Order::$paying;
                }

                $order->update([
                    'status' => $orderStatus,
                ]);
            }

            return $order;

        } catch (\Exception $exception) {
            //dd($exception);
            throw new \Exception($exception->getMessage(), $exception->getCode());
        }
    }
}
