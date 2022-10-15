<?php

namespace App\PaymentChannels\Drivers\Authorizenet;

use App\Models\Order;
use App\Models\PaymentChannel;
use App\PaymentChannels\IChannel;
use Illuminate\Http\Request;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;

class Channel implements IChannel
{
    protected $currency;
    protected $order_session_key;
    protected $test_mode;
    protected $merchant_login_id;
    protected $merchant_transaction_key;

    public function __construct(PaymentChannel $paymentChannel)
    {
        $this->currency = currency();
        $this->order_session_key = 'authorizenet.payments.order_id';

        $this->test_mode = env('AUTHORIZENET_TEST_MODE');
        $this->merchant_login_id = env('AUTHORIZENET_MERCHANT_LOGIN_ID');
        $this->merchant_transaction_key = env('AUTHORIZENET_MERCHANT_TRANSACTION_KEY');
    }

    public function paymentRequest(Order $order)
    {
        session()->put($this->order_session_key, $order->id);

        $paymentChannel = PaymentChannel::where('class_name', PaymentChannel::$authorizenet)
            ->where('status', 'active')
            ->first();

        $data = [
            'order' => $order,
            'paymentChannel' => $paymentChannel
        ];

        return view('web.default.cart.channels.authorizenet', $data);
    }


    public function verify(Request $request)
    {
        $session_order_id = session()->get($this->order_session_key, null);
        session()->forget($this->order_session_key);
        $data = $request->all();

        $user = auth()->user();

        $order = Order::where('id', $session_order_id)
            ->where('user_id', $user->id)
            ->first();

        if (!empty($order)) {
            $invoiceNumber = time() . $order->id;
            $lastName = $user->full_name;
            $address = $user->address;
            $amount = $order->total_amount;
            $country = $user->getRegionByTypeId($user->country_id);
            $city = $user->getRegionByTypeId($user->city_id);
            $zip = '';

            /* Create a merchantAuthenticationType object with authentication details
                      retrieved from the constants file */
            $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
            $merchantAuthentication->setName($this->merchant_login_id);
            $merchantAuthentication->setTransactionKey($this->merchant_transaction_key);

            // Set the transaction's refId
            $refId = 'ref' . time();
            $cardNumber = preg_replace('/\s+/', '', $data['cardNumber']);

            // Create the payment data for a credit card
            $creditCard = new AnetAPI\CreditCardType();
            $creditCard->setCardNumber($cardNumber);
            $creditCard->setExpirationDate($data['expiration-year'] . "-" . $data['expiration-month']);
            $creditCard->setCardCode($data['cvv']);

            // Add the payment data to a paymentType object
            $paymentOne = new AnetAPI\PaymentType();
            $paymentOne->setCreditCard($creditCard);

            // Create order information
            $paymentOrder = new AnetAPI\OrderType();
            $paymentOrder->setInvoiceNumber($invoiceNumber);
//        $order->setDescription("Golf Shirts");

            // Set the customer's Bill To address
            $customerAddress = new AnetAPI\CustomerAddressType();
            $customerAddress->setFirstName("");
            $customerAddress->setLastName($lastName);
            $customerAddress->setAddress($address);
            $customerAddress->setCity($city);
            $customerAddress->setZip($zip);
            $customerAddress->setCountry($country);

            // Set the customer's identifying information
            $customerData = new AnetAPI\CustomerDataType();
            $customerData->setId($user->id);
            $customerData->setEmail($user->email);

            // Create a TransactionRequestType object and add the previous objects to it
            $transactionRequestType = new AnetAPI\TransactionRequestType();
            $transactionRequestType->setTransactionType("authCaptureTransaction");
            $transactionRequestType->setAmount($amount);
            $transactionRequestType->setPayment($paymentOne);
            $transactionRequestType->setOrder($paymentOrder);
            $transactionRequestType->setPayment($paymentOne);
            $transactionRequestType->setBillTo($customerAddress);
            $transactionRequestType->setCustomer($customerData);

            // Assemble the complete transaction request
            $requests = new AnetAPI\CreateTransactionRequest();
            $requests->setMerchantAuthentication($merchantAuthentication);
            $requests->setRefId($refId);
            $requests->setTransactionRequest($transactionRequestType);

            // Create the controller and get the response
            $controller = new AnetController\CreateTransactionController($requests);
            if ($this->test_mode) {
                $response = $controller->executeWithApiResponse(\net\authorize\api\constants\ANetEnvironment::SANDBOX);
            } else {
                $response = $controller->executeWithApiResponse(\net\authorize\api\constants\ANetEnvironment::PRODUCTION);
            }

            $orderStatus = Order::$fail;
            $payment_data = null;

            if (!empty($response)) {
                // Check to see if the API request was successfully received and acted upon
                if ($response->getMessages()->getResultCode() == "Ok") {
                    // Since the API request was successful, look for a transaction response
                    // and parse it to display the results of authorizing the card
                    $tresponse = $response->getTransactionResponse();

                    if ($tresponse != null && $tresponse->getMessages() != null) {
                        $payment_data = [
                            'transId' => $tresponse->getTransId(),
                            'authCode' => $tresponse->getAuthCode(),
                            'accountType' => $tresponse->getAccountType(),
                            'accountNumber' => $tresponse->getAccountNumber(),
                            'refId' => $response->getRefId(),
                        ];

                        $orderStatus = Order::$fail;
                    } else {
                        $message_text = 'There were some issue with the payment. Please try again later.';

                        if ($tresponse->getErrors() != null) {
                            $message_text = $tresponse->getErrors()[0]->getErrorText();
                        }

                        $payment_data = [
                            'message_text' => $message_text
                        ];
                    }
                } else {
                    $message_text = 'There were some issue with the payment. Please try again later.';

                    $tresponse = $response->getTransactionResponse();

                    if ($tresponse != null && $tresponse->getErrors() != null) {
                        $message_text = $tresponse->getErrors()[0]->getErrorText();
                    } else {
                        $message_text = $response->getMessages()->getMessage()[0]->getText();
                    }

                    $payment_data = [
                        'message_text' => $message_text
                    ];
                }
            } else {
                $payment_data = [
                    'message_text' => 'No response returned'
                ];
            }

            $order->update([
                'status' => $orderStatus,
                'payment_data' => !empty($payment_data) ? json_encode($payment_data) : null,
            ]);

            return $order;
        }

        abort(404);
    }
}
