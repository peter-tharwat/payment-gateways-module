<?php

namespace App\PaymentChannels\Drivers\Instamojo;

use App\Models\Order;
use App\Models\PaymentChannel;
use App\PaymentChannels\IChannel;
use Illuminate\Http\Request;
use Instamojo\Instamojo;

class Channel implements IChannel
{
    protected $currency;
    protected $order_session_key;
    protected $api;

    public function __construct(PaymentChannel $paymentChannel)
    {
        $this->currency = currency();
        $this->order_session_key = 'instamojo.payments.order_id';

        $test_mode = env('INSTAMOJO_TEST_MODE') ?? false;
        $client_id = env('INSTAMOJO_CLIENT_ID');
        $client_secret = env('INSTAMOJO_CLIENT_SECRET');
        $authType = env('INSTAMOJO_AUTH_TYPE');
        $username = env('INSTAMOJO_USERNAME');
        $password = env('INSTAMOJO_PASSWORD');

        $this->api = Instamojo::init($authType, [
            "client_id" => $client_id,
            "client_secret" => $client_secret,
            "username" => $username, /** In case of user based authentication**/
            "password" => $password/** In case of user based authentication**/
        ], $test_mode);
    }

    public function paymentRequest(Order $order)
    {
        $user = $order->user;

        try {
            $response = $this->api->createPaymentRequest([
                "purpose" => '',
                "amount" => $order->total_amount,
                "send_email" => false,
                "email" => $user->email,
                "phone" => $user->mobile,
                "redirect_url" => $this->makeCallbackUrl()
            ]);

            session()->put($this->order_session_key, $order->id);

            return $response['longurl'];
        } catch (\Exception $e) {
            print('Error: ' . $e->getMessage());
        }
    }

    private function makeCallbackUrl()
    {
        return url("/payments/verify/Instamojo");
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
                $response = $this->api->getPaymentRequestDetails($data['payment_request_id'] ?? null);

                $orderStatus = Order::$paying;

                if (!isset($response['payments'][0]['status']) or ($response['payments'][0]['status'] != 'Credit')) {
                    $orderStatus = Order::$fail;
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
