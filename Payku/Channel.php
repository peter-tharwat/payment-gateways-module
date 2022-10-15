<?php

namespace App\PaymentChannels\Drivers\Payku;

use App\Models\Order;
use App\Models\PaymentChannel;
use App\PaymentChannels\IChannel;
use Illuminate\Http\Request;
use Instamojo\Instamojo;
use SebaCarrasco93\LaravelPayku\Facades\LaravelPayku;
use SebaCarrasco93\LaravelPayku\Models\PaykuTransaction;

class Channel implements IChannel
{
    protected $currency;
    protected $order_session_key;

    public function __construct(PaymentChannel $paymentChannel)
    {
        $this->currency = currency();
        $this->order_session_key = 'payku.payments.order_id';

    }

    public function paymentRequest(Order $order)
    {
        $user = $order->user;
        session()->put($this->order_session_key, $order->id);

        $data = [
            'order' => rand(0000000, 11111111) . date('is'),
            'subject' => 'Order Payment',
            'amount' => $order->total_amount,
            'email' => $user->email
        ];

        return LaravelPayku::create($data['order'], $data['subject'], $data['amount'], $data['email']);
    }

    private function makeCallbackUrl($status)
    {
        return url("/payments/verify/Payku?status=$status");
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

                if (!empty($data['transaction_id'])) {
                    $paykuTransaction = PaykuTransaction::find($data['transaction_id']);

                    if (!empty($paykuTransaction) and $paykuTransaction->status == 'success') {
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
