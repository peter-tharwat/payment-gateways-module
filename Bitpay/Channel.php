<?php

namespace App\PaymentChannels\Drivers\Bitpay;

use App\Models\Order;
use App\Models\PaymentChannel;
use App\PaymentChannels\IChannel;
use Illuminate\Http\Request;
use Vrajroham\LaravelBitpay\LaravelBitpay;

class Channel implements IChannel
{
    protected $api_key;
    protected $api_secret;

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


        $invoice = LaravelBitpay::Invoice();

        $invoice->setItemDesc($generalSettings['site_name'] . ' payment');
        $invoice->setItemCode($order->id);
        $invoice->setPrice($price);
        $invoice->setOrderId($order->id);

        // Create Buyer Instance
        $buyer = LaravelBitpay::Buyer();
        $buyer->setName($user->full_name);
        $buyer->setEmail($user->email ?? $generalSettings['site_email']);
        $buyer->setAddress1('no Address');
        $buyer->setNotify(true);

        $invoice->setBuyer($buyer);

        // Set currency
        $invoice->setCurrency($currency);

        $invoice->setRedirectURL($this->makeCallbackUrl());

        // Create invoice on bitpay server.
        $invoice = LaravelBitpay::createInvoice($invoice);

        $invoiceId = $invoice->getId();

        $order->update([
            'reference_id' => $invoiceId
        ]);

        return $invoice->getUrl();
    }

    private function makeCallbackUrl()
    {
        $callbackUrl = route('payment_verify', [
            'gateway' => 'Bitpay'
        ]);

        return $callbackUrl;
    }

    public function verify(Request $request)
    {
        $data = $request->all();
        $order_id = $data['order_id'];

        $user = auth()->user();

        $order = Order::where('id', $order_id)
            ->where('user_id', $user->id)
            ->with('user')
            ->first();


        if (!empty($order)) {
            $order->update(['status' => Order::$fail]);
        }

        return $order;
    }
}
