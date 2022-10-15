<?php

namespace App\PaymentChannels\Drivers\Paylink;

use App\Models\Order;
use App\Models\PaymentChannel;
use App\PaymentChannels\IChannel;
use Illuminate\Http\Request;
use Paylink\Client as PaylinkClient;

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

        $testMode = env('PAYLINK_TEST_MODE');
        $vendorId = env('PAYLINK_VENDOR_ID');
        $vendorSecret = env('PAYLINK_VENDOR_SECRET');

        $client = new PaylinkClient();
        $client->setVendorId($vendorId);
        $client->setVendorSecret($vendorSecret);
        $client->setPersistToken(true);
        $client->setEnvironment($testMode ? 'testing' : 'prod');

        $this->client = $client;
    }

    /**
     * @throws \Exception
     */
    public function paymentRequest(Order $order)
    {
        // Send purchase request
        try {
            $user = $order->user;

            $products = [];
            foreach ($order->orderItems as $orderItem) {
                $products[] = [
                    'description' => 'Cart Item ' . $orderItem->id,
                    'imageSrc' => '',
                    'price' => $orderItem->amount,
                    'qty' => 1,
                    'title' => 'Order ' . $orderItem->id,
                ];
            }

            $data = [
                'amount' => $order->total_amount,
                'callBackUrl' => $this->makeCallbackUrl($order, 'back'),
                'clientEmail' => $user->email,
                'clientMobile' => $user->mobile,
                'clientName' => $user->full_name,
                'note' => 'This invoice is for client Cart.',
                'orderNumber' => $order->id,
                'products' => $products,
            ];

            $response = $this->client->createInvoice($data);

        } catch (\Exception $exception) {
            dd($exception);
            throw new \Exception($exception->getMessage(), $exception->getCode());
        }

        return $response['url'];
    }

    private function makeCallbackUrl($order, $status)
    {
        return url("/payments/verify/Paylink?status=$status&order_id=$order->id");
    }

    public function verify(Request $request)
    {
        try {

            $response = $this->client->getInvoice($transactionNo);

            // TODO:: Check the invoice status from the response => $response['orderStatus']

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
