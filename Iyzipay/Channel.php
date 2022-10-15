<?php

namespace App\PaymentChannels\Drivers\Iyzipay;

use App\Models\Order;
use App\Models\PaymentChannel;
use App\PaymentChannels\IChannel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Iyzipay\Model\Buyer;
use Iyzipay\Model\Locale;
use Iyzipay\Model\PaymentGroup;
use Iyzipay\Model\PayWithIyzicoInitialize;
use Iyzipay\Request\CreatePayWithIyzicoInitializeRequest;
use Iyzipay\Model\Address;
use Iyzipay\Model\BasketItem;
use Iyzipay\Model\BasketItemType;
use Iyzipay\Options;

class Channel implements IChannel
{
    protected $currency;
    protected $api_key;
    protected $api_secret;
    protected $IOptions;
    protected $locale;
    protected $order_session_key;

    /**
     * Channel constructor.
     * @param PaymentChannel $paymentChannel
     */
    public function __construct(PaymentChannel $paymentChannel)
    {
        $this->currency = currency(); // \Iyzipay\Model\Currency;
        $this->order_session_key = 'iyzipay.payments.order_id';

        $this->locale = Locale::EN;
        $this->api_key = env('IYZIPAY_API_KEY');
        $this->api_secret = env('IYZIPAY_API_SECRET');

        $this->IOptions = new Options();
        $this->IOptions->setApiKey($this->api_key);
        $this->IOptions->setSecretKey($this->api_secret);
        $this->IOptions->setBaseUrl(env('IYZIPAY_BASE_URL'));
    }

    public function paymentRequest(Order $order)
    {
        $generalSettings = getGeneralSettings();
        $user = $order->user;


        $IForm = new CreatePayWithIyzicoInitializeRequest();
        $IForm->setLocale($this->locale);
        $IForm->setConversationId($order->id);
        $IForm->setPrice($order->total_amount);
        $IForm->setPaidPrice($order->total_amount);
        $IForm->setCurrency($this->currency);
        $IForm->setBasketId($user->id);
        $IForm->setPaymentGroup(PaymentGroup::SUBSCRIPTION);
        $IForm->setCallbackUrl($this->makeCallbackUrl($order->id));
        $IForm->setEnabledInstallments(array(2, 3, 6, 9));


        $IBuyer = new Buyer();
        $IBuyer->setId($user->id);
        $IBuyer->setName($user->full_name);
        $IBuyer->setSurname('buyer');
        $IBuyer->setGsmNumber($user->mobile);
        $IBuyer->setEmail($user->email ?? $generalSettings['site_email']);
        $IBuyer->setIdentityNumber("51117");
        $IBuyer->setLastLoginDate(date("Y-m-d H:i:s"));
        $IBuyer->setRegistrationDate(date("Y-m-d H:i:s"));
        $IBuyer->setRegistrationAddress($user->address ?? 'no address');
        $IBuyer->setIp($_SERVER["REMOTE_ADDR"]);
        $IBuyer->setCity($user->address ?? 'no address');
        $IBuyer->setCountry($user->address ?? 'no address');
        $IBuyer->setZipCode(123);
        $IForm->setBuyer($IBuyer);

        $IShipping = new Address();
        $IShipping->setContactName($user->full_name);
        $IShipping->setCity($user->address ?? 'no address');
        $IShipping->setCountry($user->address ?? 'no address');
        $IShipping->setAddress($user->address ?? 'no address');
        $IShipping->setZipCode(123);
        $IForm->setShippingAddress($IShipping);

        $IBilling = new Address();
        $IBilling->setContactName($user->full_name);
        $IBilling->setCity($user->address ?? 'no address');
        $IBilling->setCountry($user->address ?? 'no address');
        $IBilling->setAddress($user->address ?? 'no address');
        $IBilling->setZipCode(123);
        $IForm->setBillingAddress($IBilling);

        $FBasketItems = new BasketItem();
        $FBasketItems->setId($order->id);
        $FBasketItems->setName($generalSettings['site_name'] . ' payment');
        $FBasketItems->setCategory1($generalSettings['site_name'] . ' payment category');
        $FBasketItems->setItemType(BasketItemType::VIRTUAL);
        $FBasketItems->setPrice($order->total_amount);

        $IForm->setBasketItems([$FBasketItems]);

        session()->put($this->order_session_key, $order->id);

        $payWithIyzicoInitialize = PayWithIyzicoInitialize::create($IForm, $this->IOptions);

        if ($payWithIyzicoInitialize->getStatus() == 'success') {
            return Redirect::away($payWithIyzicoInitialize->getPayWithIyzicoPageUrl()); // return for redirection
        }

        $toastData = [
            'title' => trans('cart.fail_purchase'),
            'msg' => $payWithIyzicoInitialize->getErrorMessage(),
            'status' => 'error'
        ];

        return redirect()->back()->with(['toast' => $toastData])->withInput();
    }

    private function makeCallbackUrl($order_id)
    {
        $callbackUrl = route('payment_verify_post', [
            'gateway' => 'Iyzipay',
            'order_id' => $order_id,
        ]);

        return $callbackUrl;
    }

    public function verify(Request $request)
    {
        session()->forget($this->order_session_key);

        $token = $request->get('token');
        $orderId = $request->get('order_id');

        $order = null;


        if (!empty($token)) {

            $request2 = new \Iyzipay\Request\RetrievePayWithIyzicoRequest();
            $request2->setLocale($this->locale);
            $request2->setConversationId($orderId);
            $request2->setToken($token);

            $checkoutForm = \Iyzipay\Model\PayWithIyzico::retrieve($request2, $this->IOptions);

            $buyerId = $checkoutForm->getBasketId();

            $order = Order::where('id', $orderId)
                ->where('user_id', $buyerId)
                ->first();

            if (!empty($order)) {
                $orderStatus = Order::$fail;

                Auth::loginUsingId($buyerId);

                $status = $checkoutForm->getStatus();

                if ($status == 'success') {
                    $orderStatus = Order::$paying;
                }

                $order->update([
                    'status' => $orderStatus,
                ]);
            }
        }

        return $order;
    }

    public function getErrorToast()
    {
        $toastData = [
            'title' => trans('cart.fail_purchase'),
            'msg' => trans('cart.gateway_error'),
            'status' => 'error'
        ];
        return back()->with(['toast' => $toastData])->withInput();
    }
}
