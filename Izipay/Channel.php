<?php

namespace App\PaymentChannels\Drivers\Izipay;

use App\Models\Order;
use App\Models\PaymentChannel;
use App\PaymentChannels\IChannel;
use Illuminate\Http\Request;
use Lyra\Client as LyraClient;

class Channel implements IChannel
{
    protected $currency;
    protected $client;
    protected $publicKey;

    /**
     * Channel constructor.
     * @param PaymentChannel $paymentChannel
     */
    public function __construct(PaymentChannel $paymentChannel)
    {
        $this->currency = currency();

        $username = env('IZIPAY_USERNAME');
        $password = env('IZIPAY_PASSWORD');
        $endpoint = env('IZIPAY_ENDPOINT');
        $clientEndpoint = url('');
        $this->publicKey = env('IZIPAY_PUBLIC_KEY');
        $SHA256Key = env('IZIPAY_SHA256_KEY');

        $client = new LyraClient();
        $client->setUsername($username);
        $client->setPassword($password);
        $client->setEndpoint($endpoint);
        $client->setClientEndpoint($clientEndpoint);
        $client->setPublicKey($this->publicKey);
        $client->setSHA256Key($SHA256Key);

        $this->client = $client;
    }

    /**
     * @throws \Exception
     */
    public function paymentRequest(Order $order)
    {
        // Send purchase request
        try {
            $store = [
                "amount" => (int)($order->total_amount * 100), // https://docs.lyra.com/en/rest/V4.0/api/playground/Charge/CreatePayment#amount
                "currency" => $this->currency, // worked by this currency => PEN
                "orderId" => $order->id,
                'name' => $order->user->full_name,
                "customer" => [
                    "email" => $order->user->email
                ]
            ];

            $response = $this->client->post("V4/Charge/CreatePayment", $store);

        } catch (\Exception $exception) {
            dd($exception);
            throw new \Exception($exception->getMessage(), $exception->getCode());
        }

        if ($response['status'] != 'SUCCESS') {
            throw new \Exception(trans('cart.gateway_error'));
        }

        $formToken = $response["answer"]["formToken"];

        $data = [
            'publicKey' => $this->publicKey,
            'formToken' => $formToken,
            'successUrl' => $this->makeCallbackUrl($order, 'success'),
        ];

        return view('web.default.cart.channels.izipay', $data);
    }

    private function makeCallbackUrl($order, $status)
    {
        return url("/payments/verify/Izipay?status=$status&order_id=$order->id");
    }

    public function verify(Request $request)
    {
        try {
            if (!$this->client->checkHash()) {
                //something wrong, probably a fraud ....
                throw new \Exception('invalid signature');
            }

            $rawAnswer = $this->client->getParsedFormAnswer();
            $formAnswer = $rawAnswer['kr-answer'];

            /* Retrieve the transaction id from the IPN data */
            $transaction = $formAnswer['transactions'][0];

            /* get some parameters from the answer */
            $orderStatus = $formAnswer['orderStatus'];
            $orderId = $formAnswer['orderDetails']['orderId'];
            $transactionUuid = $transaction['uuid'];

            $user = auth()->user();

            $order = Order::where('id', $orderId)
                ->where('user_id', $user->id)
                ->first();

            if (!empty($order)) {
                $orderStatus = Order::$fail;

                if ($orderStatus == 'success') {
                    $orderStatus = Order::$paying;
                }

                $order->update([
                    'status' => $orderStatus
                ]);
            }

            return $order;

        } catch (\Exception $exception) {
            //dd($exception);
            throw new \Exception($exception->getMessage(), $exception->getCode());
        }
    }
}
