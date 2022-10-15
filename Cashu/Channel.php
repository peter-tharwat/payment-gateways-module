<?php

namespace App\PaymentChannels\Drivers\Cashu;

use App\Models\Order;
use App\Models\PaymentChannel;
use App\PaymentChannels\IChannel;
use Illuminate\Http\Request;
use CashUAony\Phpanonymous\CashU;

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

    public function paymentRequest(Order $order)
    {
        $generalSettings = getGeneralSettings();
        $user = $order->user;

        $data = [
            'amount' => $order->total_amount,
            'currency' => $this->currency,
            'display_text' => $generalSettings['site_name'].' payment',
            'lang' => 'ar', // language arabic or english ( ar , en ) for lowercase
            'item1' => 'order '.$order->id,
            'item2' => '',
            'item3' => '',
            'item4' => '',
            'item5' => '',
            'service_name' => 'PaymentPhpAnonymous', // service name with setup on your account
        ];

        return CashU::Go($data);
    }

    private function makeCallbackUrl()
    {

    }

    public function verify(Request $request)
    {

        if (!empty($order)) {
            $order->update([
                'status' => Order::$fail
            ]);
        }

        return null;
    }
}
