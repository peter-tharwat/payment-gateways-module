<?php

namespace App\PaymentChannels\Drivers\Paymob;

use App\Models\Order;
use App\Models\PaymentChannel;
use App\PaymentChannels\IChannel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Mockery\Exception;


class Channel implements IChannel
{

    public $PAYMOB_API_KEY;
    public $PAYMOB_INTEGRATION_ID;
    public $PAYMOB_HMAC;
    public $PAYMOB_IFRAME_ID;

    public function __construct(PaymentChannel $paymentChannel)
    {
        $this->PAYMOB_API_KEY=env('PAYMOB_API_KEY','');
        $this->PAYMOB_INTEGRATION_ID=env('PAYMOB_INTEGRATION_ID','');
        $this->PAYMOB_HMAC=env('PAYMOB_HMAC','');
        $this->PAYMOB_IFRAME_ID=env('PAYMOB_IFRAME_ID','');
    }
  
    public function paymentRequest(Order $order)
    {
        
        $full_name = explode(" ",auth()->user()->full_name);
        $first_name = $full_name[0];
        $last_name = $full_name[1];
        

    
        $response = \Http::withHeaders(['content-type' => 'application/json'])
                    ->post('https://accept.paymobsolutions.com/api/auth/tokens', ["api_key" => $this->PAYMOB_API_KEY]);
        $json = $response->json();
       

        $response_final = \Http::withHeaders(['content-type' => 'application/json'])
                                ->post('https://accept.paymobsolutions.com/api/ecommerce/orders', [
                                    "auth_token" => $json['token'], 
                                    "delivery_needed" => "false",
                                    "amount_cents" => (float)$order->total_amount * 100, "items" => []
                                ]);

        $json_final = $response_final->json();
        
        $order->update(['reference_id'=> $json_final['id']]);;
        
        $response_final_final = \Http::withHeaders(['content-type' => 'application/json'])
                                ->post('https://accept.paymobsolutions.com/api/acceptance/payment_keys', 
                                    [
                                        "auth_token" => $json['token'], 
                                        "expiration" => 36000,
                                        "amount_cents" => $json_final['amount_cents'], 
                                        "order_id" => $json_final['id'], 
                                        "billing_data" => [
                                            "apartment" => "NA", 
                                            "email" => \Auth::user()->email, 
                                            "floor" => "NA", 
                                            "first_name" => $first_name, 
                                            "street" => "NA", 
                                            "building" => "NA", 
                                            "phone_number" => \Auth::user()->mobile, 
                                            "shipping_method" => "NA", 
                                            "postal_code" => "NA", 
                                            "city" => "NA", 
                                            "country" => "NA",
                                            "last_name" => $last_name, 
                                            "state" => "NA"
                                        ], 
                                        "currency" => "EGP",
                                        "integration_id" =>  env('PAYMOB_INTEGRATION_ID') 
                                    ]);

        $response_final_final_json = $response_final_final->json();
        
        header("Location: "."https://accept.paymobsolutions.com/api/acceptance/iframes/" . env("PAYMOB_IFRAME_ID") . "?payment_token=" . $response_final_final_json['token']);

        die();
        $res = [
            'status' => 200,
            /*'response'=>$response,*/
            'redirect' => "https://accept.paymobsolutions.com/api/acceptance/iframes/" . env("PAYMOB_IFRAME_ID") . "?payment_token=" . $response_final_final_json['token'], 'message' => 'جار تحويلك إلى صفحة الدفع'
        ];

        return dd($res);
    }

    private function makeCallbackUrl($order)
    {
        $callbackUrl = route('payment_verify', [
            'gateway' => 'paymob'
        ]);

        return $callbackUrl;
    }

    public function verify(Request $request)
    {
        
        $string = $request['amount_cents'] . $request['created_at'] . $request['currency'] . $request['error_occured'] . $request['has_parent_transaction'] . $request['id'] . $request['integration_id'] . $request['is_3d_secure'] . $request['is_auth'] . $request['is_capture'] . $request['is_refunded'] . $request['is_standalone_payment'] . $request['is_voided'] . $request['order'] . $request['owner'] . $request['pending'] . $request['source_data_pan'] . $request['source_data_sub_type'] . $request['source_data_type'] . $request['success'];
        if (hash_hmac('sha512', $string, env('PAYMOB_HMAC')))
        {
            $user = auth()->user();
 
            $order = Order::where('reference_id',  $request['order'])->where('user_id', $user->id)->firstOrFail();
            $order->update(['status' => Order::$paying]);
            /*if ($order->status === Order::$paid) {
                return $order;
            }*/
            return $order;
     
            
        }
        else
        {
            $user = auth()->user();
            $order = Order::where('reference_id',  $request['order'])->where('user_id', $user->id)->firstOrFail();
            $order->update(['status' => Order::$fail]);
            return $order;
            
        }
    }
}
