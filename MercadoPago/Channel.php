<?php

namespace App\PaymentChannels\Drivers\MercadoPago;

use App\Models\Order;
use App\Models\PaymentChannel;
use App\PaymentChannels\IChannel;
use Illuminate\Http\Request;
use MercadoPago\SDK as Mercado;
use MercadoPago\Preference as MercadoPreference;
use MercadoPago\Item as MercadoItem;
use MercadoPago\Payer as MercadoPagoPayer;

class Channel implements IChannel
{
    protected $currency;
    protected $public_key;
    protected $access_token;
    protected $client_id;
    protected $client_secret;
    protected $order_session_key;

    /**
     * Channel constructor.
     * @param PaymentChannel $paymentChannel
     */
    public function __construct(PaymentChannel $paymentChannel)
    {
        $this->currency = currency();

        $this->public_key = env('MERCADO_PAGO_PUBLIC_KEY');
        $this->access_token = env('MERCADO_PAGO_ACCESS_TOKEN');
        $this->client_id = env('MERCADO_CLIENT_ID');
        $this->client_secret = env('MERCADO_CLIENT_SECRET');

        $this->order_session_key = 'mercado.payments.order_id';
    }

    public function paymentRequest(Order $order)
    {
        $user = $order->user;

        Mercado::setAccessToken($this->access_token);

        $payer = new MercadoPagoPayer();
        $payer->name = $user->full_name;
        $payer->email = $user->email;
        $payer->phone = array(
            "area_code" => "",
            "number" => $user->mobile
        );

        $orderItems = $order->orderItems;

        $items = [];
        foreach ($orderItems as $orderItem) {
            $item = new MercadoItem();

            $item->id = $orderItem->id;
            $item->title = "item " . $orderItem->id;
            $item->quantity = 1;
            $item->unit_price = $orderItem->total_amount;
            $item->currency_id = $this->currency;

            $items[] = $item;
        }

        $preference = new MercadoPreference();
        $preference->items = $items;
        $preference->payer = $payer;
        $preference->back_urls = $this->makeCallbackUrl($order);
        $preference->auto_return = "approved";

        /*$preference->payment_methods = array(
            "excluded_payment_types" => array(
                array("id" => "credit_card")
            ),
            "installments" => 12
        );*/

        $preference->save();

        session()->put($this->order_session_key, $order->id);

//        return $preference->sandbox_init_point;
        $data = [
            'public_key' => $this->public_key,
            'preference_id' => $preference->id,
        ];

        return view('web.default.cart.channels.mercado', $data);
    }

    private function makeCallbackUrl($order)
    {
        return [
            'success' => url("/payments/verify/MercadoPago"),
            'failure' => url("/payments/verify/MercadoPago"),
            'pending' => url("/payments/verify/MercadoPago"),
        ];
    }

    public function verify(Request $request)
    {
        $data = $request->all();
        $status = $data['status']; // approved or pending

        $order_id = session()->get($this->order_session_key, null);
        session()->forget($this->order_session_key);

        $user = auth()->user();

        $order = Order::where('id', $order_id)
            ->where('user_id', $user->id)
            ->first();

        if (!empty($order)) {

            if ($status == 'approved') {
                $order->update([
                    'status' => Order::$paying,
                    'payment_data' => json_encode($data),
                ]);

                return $order;
            }


            $order->update([
                'status' => Order::$fail,
                'payment_data' => json_encode($data),
            ]);
        }

        return $order;
    }
}
