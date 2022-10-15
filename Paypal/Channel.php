<?php

namespace App\PaymentChannels\Drivers\Paypal;

use App\Models\Order;
use App\Models\PaymentChannel;
use App\PaymentChannels\IChannel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Mockery\Exception;
use PayPal\Api\Amount;
use PayPal\Api\Details;
use PayPal\Api\Item;
use PayPal\Api\ItemList;
use PayPal\Api\Payer;
use PayPal\Api\Payment;
use PayPal\Api\PaymentExecution;
use PayPal\Api\RedirectUrls;
use PayPal\Api\Transaction;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Exception\PayPalConnectionException;
use PayPal\Rest\ApiContext;

class Channel implements IChannel
{
    private $_api_context;
    protected $currency;
    /**
     * Channel constructor.
     * @param PaymentChannel $paymentChannel
     */
    public function __construct(PaymentChannel $paymentChannel)
    {
        $this->currency = currency();

        $paypal_conf = \Config::get('paypal');

        $this->_api_context = new ApiContext(new OAuthTokenCredential(
                $paypal_conf['client_id'],
                $paypal_conf['secret'])
        );
        $this->_api_context->setConfig($paypal_conf['settings']);
    }

    public function paymentRequest(Order $order)
    {
        $payer = new Payer();
        $payer->setPaymentMethod("paypal");

        $items = [];
        if ($order->type !== Order::$charge) {
            foreach ($order->orderItems as $orderItem) {
                $item = new Item();
                $name = 'meeting';

                if ($orderItem->webinar_id) {
                    $name = $orderItem->webinar->title;
                }

                $items[] = $item->setName($name)
                    ->setCurrency($this->currency)
                    ->setQuantity(1)
                    ->setSku($orderItem->id) // Similar to `item_number` in Classic API
                    ->setPrice($orderItem->total_amount - $orderItem->tax_price);

            }
        } else {
            $item = new Item();
            $item->setName('charge')
                ->setCurrency($this->currency)
                ->setQuantity(1)
                ->setPrice($order->total_amount);
        }

        $itemList = new ItemList();
        $itemList->setItems($items);

        $details = new Details();
        $details->setShipping(0)
            ->setTax($order->tax)
            ->setSubtotal($order->total_amount - $order->tax);

        $amount = new Amount();
        $amount->setCurrency($this->currency)
            ->setTotal($order->total_amount)
            ->setDetails($details);

        $transaction = new Transaction();
        $transaction->setAmount($amount)
            ->setItemList($itemList)
            ->setDescription("Payment description")
            ->setInvoiceNumber(uniqid());

        $redirectUrls = new RedirectUrls();
        $redirectUrls->setReturnUrl($this->makeCallbackUrl($order))
            ->setCancelUrl($this->makeCallbackUrl($order));

        $payment = new Payment();
        $payment->setIntent("sale")
            ->setPayer($payer)
            ->setRedirectUrls($redirectUrls)
            ->setTransactions(array($transaction));

        try {
            $payment->create($this->_api_context);
        } catch (PayPalConnectionException $exception) {
            throw new \Exception($exception->getMessage(), $exception->getCode());
        }

        $paymentId = $payment->getId();
        $order->reference_id = $paymentId;
        $order->save();

        foreach ($payment->getLinks() as $link) {
            if ($link->getRel() == 'approval_url') {
                $redirect_url = $link->getHref();
                break;
            }
        }

        if (isset($redirect_url)) {
            /** redirect to paypal **/
            return $redirect_url;
        }
        return redirect('cart')->with('msg', 'fail');
    }

    private function makeCallbackUrl(Order $order)
    {
        $callbackUrl = route('payment_verify', [
            'gateway' => 'Paypal'
        ]);

        return $callbackUrl;
    }

    public function verify(Request $request)
    {
        $user = auth()->user();

        $payment_id = $request->paymentId;
        if (!$payment_id) {
            return;
        }

        $order = Order::where('reference_id', $payment_id)->where('user_id', $user->id)->first();

        if ($order->status === Order::$paid) {
            return $order;
        }

        $order->update(['status' => Order::$fail]);

        if (empty($request->PayerID) || empty($request->token)) {
            return $order;
        }

        $payment = Payment::get($payment_id, $this->_api_context);
        $execution = new PaymentExecution();
        $execution->setPayerId($request->PayerID);
        try {
            $result = $payment->execute($execution, $this->_api_context);

        } catch (PayPalConnectionException $exception) {
            throw new \Exception($exception->getMessage(), $exception->getCode());
        }

        if ($result->getState() == 'approved') {
            $order->update(['status' => Order::$paying]);
        }

        return $order;
    }
}
