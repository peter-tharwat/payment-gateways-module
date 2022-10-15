<?php

namespace App\PaymentChannels\Drivers\Payfort;

use App\Models\Order;
use App\Models\PaymentChannel;
use App\PaymentChannels\IChannel;
use Illuminate\Http\Request;

class Channel implements IChannel
{

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

        $requestParams = array(
            'command' => env('PAYFORT_COMMAND'),
            'access_code' => env('PAYFORT_ACCESS_CODE'),
            'merchant_identifier' => env('PAYFORT_MERCHANT_IDENTIFIER'),
            'merchant_reference' => env('PAYFORT_MERCHANT_REFERENCE'),
            'amount' => $price,
            'currency' => $currency,
            'language' => 'en',
            'customer_email' => $user->email ?? $generalSettings['site_email'],
            'signature' => env('PAYFORT_SIGNATURE'),
            'order_description' => $generalSettings['site_name'] . ' payment',
        );

        $redirectUrl = 'https://sbcheckout.payfort.com/FortAPI/paymentPage';
        echo "<html xmlns='https://www.w3.org/1999/xhtml'>\n<head></head>\n<body>\n";
        echo "<form action='$redirectUrl' method='post' name='frm'>\n";
        foreach ($requestParams as $a => $b) {
            echo "\t<input type='hidden' name='" . htmlentities($a) . "' value='" . htmlentities($b) . "'>\n";
        }
        echo "\t<script type='text/javascript'>\n";
        echo "\t\tdocument.frm.submit();\n";
        echo "\t</script>\n";
        echo "</form>\n</body>\n</html>";
    }

    private function makeCallbackUrl($order, $status)
    {

    }

    public function verify(Request $request)
    {
        dd(2);
        $order = null;

        if (!empty($order)) {
            $order->update(['status' => Order::$fail]);
        }

        return $order;
    }
}
