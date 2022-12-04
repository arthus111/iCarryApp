<?php
\Firebase\JWT\JWT::$leeway = 100;
use App\Exceptions\ShopifyProductCreatorException;
use App\Lib\AuthRedirection;
use App\Lib\EnsureBilling;
use App\Lib\ProductCreator;
use App\Models\Session;
use App\Models\Credential;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;

use Shopify\Auth\OAuth;
use Shopify\Auth\Session as AuthSession;
use Shopify\Clients\HttpHeaders;
use Shopify\Clients\Rest;
use Shopify\Context;
use Shopify\Exception\InvalidWebhookException;

use Shopify\Webhooks\Registry;
use Shopify\Webhooks\Topics;

use Shopify\Rest\Admin2022_10\Order;
use Shopify\Rest\Admin2022_10\Product;
use Shopify\Rest\Admin2022_10\CarrierService;
use Shopify\Rest\Admin2022_10\Webhook;
use Shopify\Rest\Admin2022_10\FulfillmentOrder;
use Shopify\Rest\Admin2022_10\AccessScope;

use Osiset\BasicShopifyAPI\BasicShopifyAPI;
use Osiset\BasicShopifyAPI\Options;
use Osiset\BasicShopifyAPI\Session as APISession;
use Illuminate\Support\Facades\Auth;

use Shopify\Utils;
/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/
Route::fallback(function (Request $request) {
    if (Context::$IS_EMBEDDED_APP &&  $request->query("embedded", false) === "1") {
        if (env('APP_ENV') === 'production') {
            return file_get_contents(public_path('index.html'));
        } else {
            return file_get_contents(base_path('frontend/index.html'));
        }
    } else {
        return redirect(Utils::getEmbeddedAppUrl($request->query("host", null)) . "/" . $request->path());
    }
})->middleware('shopify.installed');

Route::get('/api/auth', function (Request $request) {
    $shop = Utils::sanitizeShopDomain($request->query('shop'));

    // Delete any previously created OAuth sessions that were not completed (don't have an access token)
    Session::where('shop', $shop)->where('access_token', null)->delete();

    return AuthRedirection::redirect($request);
});

Route::get('/api/auth/callback', function (Request $request) {
    $session = OAuth::callback(
        $request->cookie(),
        $request->query(),
        ['App\Lib\CookieHandler', 'saveShopifyCookie'],
    );

    $host = $request->query('host');
    $shop = Utils::sanitizeShopDomain($request->query('shop'));

    $response = Registry::register('/api/webhooks', Topics::APP_UNINSTALLED, $shop, $session->getAccessToken());
    if ($response->isSuccess()) {
        Log::debug("Registered APP_UNINSTALLED webhook for shop $shop");
    } else {
        Log::error(
            "Failed to register APP_UNINSTALLED webhook for shop $shop with response body: " .
                print_r($response->getBody(), true)
            );
    }

    $redirectUrl = Utils::getEmbeddedAppUrl($host);
    if (Config::get('shopify.billing.required')) {
        list($hasPayment, $confirmationUrl) = EnsureBilling::check($session, Config::get('shopify.billing'));

        if (!$hasPayment) {
            $redirectUrl = $confirmationUrl;
        }
    }

    return redirect($redirectUrl);
});

Route::post('/api/webhooks', function (Request $request) {
    try {
        $topic = $request->header(HttpHeaders::X_SHOPIFY_TOPIC, '');

        $response = Registry::process($request->header(), $request->getContent());
        if (!$response->isSuccess()) {
            Log::error("Failed to process '$topic' webhook: {$response->getErrorMessage()}");
            return response()->json(['message' => "Failed to process '$topic' webhook"], 500);
        }
    } catch (InvalidWebhookException $e) {
        Log::error("Got invalid webhook request for topic '$topic': {$e->getMessage()}");
        return response()->json(['message' => "Got invalid webhook request for topic '$topic'"], 401);
    } catch (\Exception $e) {
        Log::error("Got an exception when handling '$topic' webhook: {$e->getMessage()}");
        return response()->json(['message' => "Got an exception when handling '$topic' webhook"], 500);
    }
})->name('webhooks');

Route::post('/api/shipping/getrate', function (Request $request) {

    $filename = time();

    $input = file_get_contents('php://input');

    // parse the request
    $rates = json_decode($input, true);

    // total up the cart quantities for simple rate calculations
    $codAmount = 0;
    $quantity = 0;
    $weight = 0;
    //////file_put_contents($filename."-000getrate", $input);
    foreach($rates['rate']['items'] as $item) {
        $quantity += $item['quantity'];
        $codAmount += $item['quantity'] * $item['price'];
        $weight += $item['grams']/1000;
    }

    ////file_put_contents($filename."-000CodAmount", $codAmount);
    $shop = $request->header('X-Shopify-Shop-Domain');
    $credential = Credential::where('shop',$shop)->first();
    if(empty($credential))
        return false;
    $token = Http::post('https://test.icarry.com/api-frontend/Authenticate/GetTokenForCustomerApi', [
        'Email' => $credential->email,
        'Password' => $credential->password
    ])->object();

    $current_site= "https://".$shop."/";
    //$current_site="https://icarryapp3.myshopify.com/";
    if(!(($token->api_plugin_type=="Shopify") && ($token->site_url==$current_site)))
        return false;
    $carrier_rates = Http::withHeaders([
        'Authorization' => 'Bearer ' . $token->token,
    ])->post('https://test.icarry.com/api-frontend/SmartwareShipment/EstimateRatesByCOD', [
        'incluedShippingCost' => true,
        'CODAmount' => $codAmount,
        'COdCurrency' => $rates['rate']['currency'],
        'DropOffLocation' => $rates['rate']['destination']['address1'].', '.$rates['rate']['destination']['city'].' '.$rates['rate']['destination']['province'].', '.$rates['rate']['destination']['country'],
        'ToLongitude' => $rates['rate']['destination']['longitude'],
        'ToLatitude' => $rates['rate']['destination']['latitude'],
        'ActualWeight' => $weight,
        'Dimensions' => [
            "Length"=> 1,
            "Width"=> 1,
            "Height"=> 1,
            "Unit"=> "cm"
        ],
        'PackageType'=> 'Parcel',
        'DropAddress'=> [
        //   'CountryCode'=> 'LB',
        //   'City'=> 'Beirut'
          'CountryCode'=> $rates['rate']['destination']['country'],
          'City'=> $rates['rate']['destination']['city']
        ],
        'IsVendor'=> true
    ])->object();
    // //file_put_contents($filename."-000carrier_rates", json_encode($carrier_rates));

    $data = array();
    foreach($carrier_rates as $rate) {
        $arr = array(
            'service_name'=> $rate->Name,
            'service_code'=> empty($rate->MethodName)? "None":$rate->MethodName,
            'description' => "test",
            'total_price'=> $rate->Rate,
            'currency'=> $rates['rate']['currency'], // this from shopify store.
            'min_delivery_date'=> date('Y-m-d H:i:s O', strtotime('+1 days')),
            'max_delivery_date'=> date('Y-m-d H:i:s O', strtotime('+2 days'))
        );
        // $filename=time();
        // //file_put_contents($filename."-000", "abc");
        array_push($data, $arr);
    };
    ////file_put_contents($filename."-000getResult", json_encode($data));

    $res['rates'] = $data;
    header('Content-Type: application/json');

    echo json_encode($res);
    //return response()->json($res);

})->name('shipping.getrate');

Route::post('/api/shipping/create_order', function (Request $request) {

    $shop = $request->header('X-Shopify-Shop-Domain');
    $access_token = Session::where('shop', $shop)->first()->access_token;
    // Create options for the API
    $options = new Options();
    // $options->setType(true); // Makes it private
    $options->setVersion('2022-10');
    $options->setApiKey(env('SHOPIFY_API_KEY'));
    $options->setApiPassword(env('SHOPIFY_API_SECRET'));
    // Create the client and session
    $api = new BasicShopifyAPI($options);
    $api->setSession(new APISession($shop, $access_token));

    $filename = time();
    $input = file_get_contents('php://input');
    $fulfillment = json_decode($input, true);

    //file_put_contents($filename.'-0input',$input);
    if(!empty($fulfillment['tracking_number']))
        return false;

    // Now run your requests...
    $result = $api->rest('GET', '/admin/orders/'.$fulfillment['order_id'].'.json');
    $order = $result['body']['order'];
    //file_put_contents($filename."-111order",json_encode($order));
    $codAmount = 0;
    $quantity = 0;
    $weight = 0;
    foreach($fulfillment['line_items'] as $item) {
        $quantity = $item['quantity'];
        $price = $item['price'];
        $codAmount += $quantity * $price;
        $weight += $item['grams']/1000;
    }

    ////file_put_contents($filename.'-110',gettype($order['payment_gateway_names']));
    $temp = serialize($order['payment_gateway_names']);

    $codString = "Cash on Delivery (COD)";
    ////file_put_contents($filename.'-codAmuont', $codAmount);
    if(strpos($temp, $codString)!==false)
    $codAmount = $order['total_price'];
    else
    $codAmount=0;
    ////file_put_contents($filename.'-codAmuont2', $codAmount);

    $location = $api->rest('GET', '/admin/locations/'.$fulfillment['location_id'].'.json');
    $location_address = $location['body']['location']['name'];
    ////file_put_contents($filename.'-1location0', $location_address);

    $shop = $request->header('X-Shopify-Shop-Domain');
    $credential = Credential::where('shop',$shop)->first();

    if(empty($credential))
        return false;
    $token = Http::post('https://test.icarry.com/api-frontend/Authenticate/GetTokenForCustomerApi', [
        'Email' => $credential->email,
        'Password' => $credential->password
    ])->object();
    $current_site= "https://".$shop."/";
    //$current_site="https://icarryapp3.myshopify.com/";
    if(!(($token->api_plugin_type=="Shopify") && ($token->site_url==$current_site)))
        return false;

        $input_data=array(
            "ProcessOrder"=> false,
            "pickupLocation"=> $location_address,
            "dropOffAddress"=> [
                "FirstName"=> $order['shipping_address']['first_name'],
                "LastName"=> $order['shipping_address']['last_name'],
                "Email"=> $order['customer']['email'],
                "PhoneNumber"=> empty( $order['shipping_address']['phone'])? 01234567:$order['shipping_address']['phone'],
                "Country"=> "lebanon",//$order['shipping_address']['country'],
                "City"=> $order['shipping_address']['city'],
                "Address1"=> "beirut,lebanon", //$order['shipping_address']['address1'],
                "Address2"=> empty( $order['shipping_address']['address2'])? 01234567:$order['shipping_address']['address2'],
                "ZipPostalCode"=> empty( $order['shipping_address']['zip'])? 01234567:$order['shipping_address']['zip'],
            ],

            "CODAmount"=> $codAmount,
            "COdCurrency"=> $order['currency'],
            "ActualWeight"=> $weight,
            "PackageType"=> "Parcel",
            "Length"=> 1,
            "Width"=> 1,
            "Height"=> 1,

            "Notes"=> empty($order['note'])? '':$order['note'],
            "SystemShipmentProvider"=>(empty($order['shipping_lines'][0]['title']) || $order['shipping_lines'][0]['title']=="Standard")? null:"Shipping.".$order['shipping_lines'][0]['title'],
            "MethodName"=>($order['shipping_lines'][0]['code']=="None" || $order['shipping_lines'][0]['code']=="Standard")? null:$order['shipping_lines'][0]['code'],
            "MethodDescription"=>($order['shipping_lines'][0]['code']=="None" || $order['shipping_lines'][0]['code']=="Standard")? null:$order['shipping_lines'][0]['code'],
            "Price"=> $order['total_price']
        );
        //file_put_contents($filename."-inputdata", json_encode($input_data));

    $create_orders = Http::withHeaders([
        'Authorization' => 'Bearer ' . $token->token,
    ])->post('https://test.icarry.com/api-frontend/SmartwareShipment/CreateOrder', [
        "ProcessOrder"=> false,
        "pickupLocation"=> $location_address,
        "dropOffAddress"=> [
            "FirstName"=> $order['shipping_address']['first_name'],
            "LastName"=> $order['shipping_address']['last_name'],
            "Email"=> $order['customer']['email'],
            "PhoneNumber"=> empty( $order['shipping_address']['phone'])? 01234567:$order['shipping_address']['phone'],
            "Country"=> "lebanon",//$order['shipping_address']['country'],
            "City"=> $order['shipping_address']['city'],
            "Address1"=> "beirut,lebanon", //$order['shipping_address']['address1'],
            "Address2"=> empty( $order['shipping_address']['address2'])? 01234567:$order['shipping_address']['address2'],
            "ZipPostalCode"=> empty( $order['shipping_address']['zip'])? 01234567:$order['shipping_address']['zip'],
        ],

        "CODAmount"=> $codAmount,
        "COdCurrency"=> $order['currency'],
        "ActualWeight"=> $weight,
        "PackageType"=> "Parcel",
        "Length"=> 1,
        "Width"=> 1,
        "Height"=> 1,

        "Notes"=> empty($order['note'])? '':$order['note'],
        "SystemShipmentProvider"=>(empty($order['shipping_lines'][0]['title']) || $order['shipping_lines'][0]['title']=="Standard")? null:"Shipping.".$order['shipping_lines'][0]['title'],
        "MethodName"=>($order['shipping_lines'][0]['code']=="None" || $order['shipping_lines'][0]['code']=="Standard")? null:$order['shipping_lines'][0]['code'],
        "MethodDescription"=>($order['shipping_lines'][0]['code']=="None" || $order['shipping_lines'][0]['code']=="Standard")? null:$order['shipping_lines'][0]['code'],
        "Price"=> $order['total_price']
    ])->object();

    $tracking_number = $create_orders->TrackingNumber;
    //file_put_contents($filename.'-1trackingNumber0',json_encode($create_orders));

    $tracking_info= array(
        "number" => $tracking_number,
        "url" =>"https://test.icarry.com/Order/TraceShipment?trackingNumber=".$tracking_number,
        "company"=>"iCARRY"
    );

    $fulfill_track = array(
        'nofity_customer'=>true,
        'tracking_info'=>$tracking_info
    );

    $param = array('fulfillment' => $fulfill_track);
    ////file_put_contents($filename.'-ful_track0', json_encode($param));

    $result = $api->rest('POST', '/admin/fulfillments/'.$fulfillment['id'].'/update_tracking.json',$param);
    $fulfillment_update = $result['body']['fulfillment'];
    ////file_put_contents($filename.'-2update_fulfillment',json_encode($fulfillment_update));

    return true;
})->name('create.shipping_order');

Route::post('/api/order/create', function (Request $request) {
    $shop = $request->header('X-Shopify-Shop-Domain');

    $access_token = Session::where('shop', $shop)->first()->access_token;
    // Create options for the API
    $options = new Options();
    // $options->setType(true); // Makes it private
    $options->setVersion('2022-10');
    $options->setApiKey(env('SHOPIFY_API_KEY'));
    $options->setApiPassword(env('SHOPIFY_API_SECRET'));
    // Create the client and session
    $api = new BasicShopifyAPI($options);
    $api->setSession(new APISession($shop, $access_token));

    $credential = Credential::where('shop',$shop)->first();
    if(empty($credential))
        return false;

    $token = Http::post('https://test.icarry.com/api-frontend/Authenticate/GetTokenForCustomerApi', [
        'Email' => $credential->email,
        'Password' => $credential->password
    ])->object();
    $current_site= "https://".$shop."/";
    //$current_site="https://icarryapp3.myshopify.com/";
    if(!(($token->api_plugin_type=="Shopify") && ($token->site_url==$current_site)))
        return false;

    $filename = time();
    $input = file_get_contents('php://input');
    $order = json_decode($input, true);
    //file_put_contents($filename.'-0create-order-input', $input);

    // Now run your requests...
    if($order['fulfillment_status']=="fulfilled")
        return false;
    $result = $api->rest('GET', '/admin/orders/'.$order['id'].'/fulfillment_orders.json');
    $fulfillment_orders = $result['body']['fulfillment_orders'];
    //file_put_contents($filename.'-0fulfillment_order', json_encode($fulfillment_orders));
    $temp = serialize($order['payment_gateway_names']);

    $codString = "Cash on Delivery (COD)";

    foreach($fulfillment_orders as $fulfillment_order)
    {
        $codAmount = 0;
        $quantity = 0;
        $weight = 0;
        $fulfillment_line_items = array();
        foreach($fulfillment_order->line_items as $ful_line_item)
        {
            $item = array(
                'id' => $ful_line_item->id,
                'quantity' => $ful_line_item->quantity
            );

            foreach($order["line_items"] as $line_item){

                if($line_item['id'] == $ful_line_item->line_item_id){
                    $quantity = $line_item['quantity'];
                    $price = $line_item['price'];
                    $codAmount += $quantity * $price;
                    $weight += $line_item['grams']*$quantity/1000;
                }
            }
            array_push($fulfillment_line_items, $item);
        }
        $filename=time();

        $lineitem = array(
            'fulfillment_order_id' => $fulfillment_order->id,
            'fulfillment_order_line_items' => $fulfillment_line_items
        );

        $line_item_by_fulfillment_order = array();
        array_push($line_item_by_fulfillment_order, $lineitem);

        if(strpos($temp, $codString)==false)
            $codAmount=0;

        $location = $api->rest('GET', '/admin/locations/'.$fulfillment_order->assigned_location_id.'.json');
        $location_address = $location['body']['location']['name'];
        ////file_put_contents($filename.'-1location0', $location_address);


        $inputdata=array(
            "ProcessOrder"=> false,
            "pickupLocation"=> $location_address,
            "dropOffAddress"=> [
                "FirstName"=> $order['shipping_address']['first_name'],
                "LastName"=> $order['shipping_address']['last_name'],
                "Email"=> $order['customer']['email'],
                "PhoneNumber"=> empty( $order['shipping_address']['phone'])? 01234567:$order['shipping_address']['phone'],
                "Country"=> $order['shipping_address']['country'],
                "City"=> $order['shipping_address']['city'],
                "Address1"=>$order['shipping_address']['address1'],
                "Address2"=> empty( $order['shipping_address']['address2'])? 01234567:$order['shipping_address']['address2'],
                "ZipPostalCode"=> empty( $order['shipping_address']['zip'])? 01234567:$order['shipping_address']['zip'],
            ],

            "CODAmount"=> $codAmount,
            "COdCurrency"=> $order['currency'],
            "ActualWeight"=> $weight,
            "PackageType"=> "Parcel",
            "Length"=> 1,
            "Width"=> 1,
            "Height"=> 1,

            "Notes"=> empty($order['note'])? '':$order['note'],
            "SystemShipmentProvider"=>(empty($order['shipping_lines'][0]['title']) || $order['shipping_lines'][0]['title']=="Standard")? null:"Shipping.".$order['shipping_lines'][0]['title'],
            "MethodName"=>($order['shipping_lines'][0]['code']=="None" || $order['shipping_lines'][0]['code']=="Standard")? null:$order['shipping_lines'][0]['code'],
            "MethodDescription"=>($order['shipping_lines'][0]['code']=="None" || $order['shipping_lines'][0]['code']=="Standard")? null:$order['shipping_lines'][0]['code'],
            "Price"=> $order['total_price']
        );
        ////file_put_contents($filename.'-inputdata', json_encode($inputdata));
        $create_orders = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token->token,
        ])->post('https://test.icarry.com/api-frontend/SmartwareShipment/CreateOrder', [
            "ProcessOrder"=> false,
            "pickupLocation"=> $location_address,
            "dropOffAddress"=> [
                "FirstName"=> $order['shipping_address']['first_name'],
                "LastName"=> $order['shipping_address']['last_name'],
                "Email"=> $order['customer']['email'],
                "PhoneNumber"=> empty( $order['shipping_address']['phone'])? 01234567:$order['shipping_address']['phone'],
                "Country"=> $order['shipping_address']['country'],
                "City"=> $order['shipping_address']['city'],
                "Address1"=> $order['shipping_address']['address1'],
                "Address2"=> empty( $order['shipping_address']['address2'])? 01234567:$order['shipping_address']['address2'],
                "ZipPostalCode"=> empty( $order['shipping_address']['zip'])? 01234567:$order['shipping_address']['zip'],
            ],

            "CODAmount"=> $codAmount,
            "COdCurrency"=> $order['currency'],
            "ActualWeight"=> $weight,
            "PackageType"=> "Parcel",
            "Length"=> 1,
            "Width"=> 1,
            "Height"=> 1,

            "Notes"=> empty($order['note'])? '':$order['note'],
            "SystemShipmentProvider"=>(empty($order['shipping_lines'][0]['title']) || $order['shipping_lines'][0]['title']=="Standard")? null:"Shipping.".$order['shipping_lines'][0]['title'],
            "MethodName"=>($order['shipping_lines'][0]['code']=="None" || $order['shipping_lines'][0]['code']=="Standard")? null:$order['shipping_lines'][0]['code'],
            "MethodDescription"=>($order['shipping_lines'][0]['code']=="None" || $order['shipping_lines'][0]['code']=="Standard")? null:$order['shipping_lines'][0]['code'],
            "Price"=> $order['total_price']
        ])->object();

        $tracking_number = $create_orders->TrackingNumber;
        //file_put_contents($filename.'-1trackingNumber0',json_encode($create_orders));

        $tracking_info= array(
            "number" => $tracking_number,
            "url" =>"https://test.icarry.com/Order/TraceShipment?trackingNumber=".$tracking_number,
            "company"=>"iCARRY"
        );


        $fulfillment = array(
            'line_items_by_fulfillment_order'=>$line_item_by_fulfillment_order,
            'nofity_customer'=>true,
            'tracking_info'=>$tracking_info
        );

        $param = array('fulfillment' => $fulfillment);
        //file_put_contents($filename.'-fulfilitem', json_encode($param));
        $result = $api->rest('POST', '/admin/fulfillments.json', $param);
        $fulfillment_result = $result['body']['fulfillment'];
        //file_put_contents($filename.'-response_fulfillment', json_encode($fulfillment_result));
    }
})->name('create.order');

Route::get('/api/configuration', function (Request $request) {
    /** @var AuthSession */
    try {
        $session = $request->get('shopifySession'); // Provided by the shopify.auth middleware, guaranteed to be active
        $client = new Rest($session->getShop(), $session->getAccessToken());

        $carrier_services=CarrierService::all($session);
        foreach($carrier_services as $carrier){
            if($carrier->name == env('CARRIER_SERVICE_NAME'))
            {
                $carrier_id = $carrier->id;
                CarrierService::delete($session, $carrier_id);
                break;
            }
        }

        $webhooks=Webhook::all($session);
        foreach($webhooks as $webhook){
            if($webhook->topic == 'fulfillments/create' || $webhook->topic == 'orders/create' || $webhook->topic == "app/uninstalled")
            {
                $webhook_id = $webhook->id;
                Webhook::delete($session, $webhook_id);
            }
        }

        $carrier_service = new CarrierService($session);
        $carrier_service->name = env('CARRIER_SERVICE_NAME');
        $carrier_service->callback_url = route("shipping.getrate");
        $carrier_service->service_discovery = true;
        $carrier_service->save(
            true, // Update Object
        );

        $webhook = new Webhook($session);
        $webhook->topic = "fulfillments/create";
        $webhook->address = route('create.shipping_order');
        $webhook->format = "json";
        $webhook->save(
            true, // Update Object
        );

        $webhook = new Webhook($session);
        $webhook->topic = "orders/create";
        $webhook->address = route('create.order');
        $webhook->format = "json";
        $webhook->save(
            true, // Update Object
        );

        $webhook = new Webhook($session);
        $webhook->topic = "app/uninstalled";
        $webhook->address = route('webhooks');
        $webhook->format = "json";
        $webhook->save(
            true, // Update Object
        );

        return response()->json(['message' => "Added service and webhook"], 200);
    }
    catch (\Exception $e) {
        Log::error("Got an exception when adding service and webhook: {$e->getMessage()}");
        return response()->json(['message' => $e->getMessage()], 500);
}

})->middleware('shopify.auth');

Route::post('/api/configuration/post', function (Request $request) {
        $session = $request->get('shopifySession'); // Provided by the shopify.auth middleware, guaranteed to be active
        $shop = $session->getShop();
        $email = $request->input('Email');
        $password = $request->input('Password');

        $user_info = Credential::where('email', $email)->where('password', $password)->first();
        try {
            if(empty($user_info))
            {
                $shop_info=Credential::where('shop', $shop)->first();
                if(empty($shop_info)){
                    $user_info = new Credential;
                    $user_info->email = $email;
                    $user_info->password = $password;
                    $user_info->shop = $shop;
                    $user_info->save();
                    return response()->json(['message' => "created"]);
                }
                else{
                    $shop_info->email = $email;
                    $shop_info->password = $password;
                    $shop_info->save();
                    return response()->json(['message' => "updated"]);
                }
            }
            else {
                $user_info->email = $email;
                $user_info->password = $password;
                $user_info->shop = $shop;
                $user_info->save();
                return response()->json(['message' => "updated"]);
            }
        } catch (\Exception $e) {
            return response()->json(['message' => "error"]);
        }

})->middleware('shopify.auth');

Route::get('/api/configuration/get', function (Request $request) {
    $session = $request->get('shopifySession'); // Provided by the shopify.auth middleware, guaranteed to be active
    $shop = $session->getShop();

    $scopes = AccessScope::all($session);
    ////file_put_contents("scope", json_encode($scopes));

    $success = $code = $error = null;
    try{
        $credential = Credential::where('shop', $shop)->first();
        $data = array();
        if(!empty($credential))
        {
            $success  = true;
            $code = 200;
            $data = array(
                "email" =>$credential->email,
                "password" => $credential->password
            );
        }
    }
    catch (\Exception $e){
        $success = false;
        $code = 500;
        $error = $e->getMessage();
    } finally {
        return response()->json(["success" => $success, "data" => $data, "error" => $error], $code);
    }
})->middleware('shopify.auth');

Route::post('/api/configuration/check', function (Request $request) {
        $session = $request->get('shopifySession'); // Provided by the shopify.auth middleware, guaranteed to be active
        $shop = $session->getShop();
        $email = $request->input('Email');
        $password = $request->input('Password');

        $data = Http::post('https://test.icarry.com/api-frontend/Authenticate/GetTokenForCustomerApi', [
            'Email' => $email,
            'Password' => $password
        ])->object();
        if(isset($data->api_plugin_type))
        {
            $token = $data->token;
            $api_type = $data->api_plugin_type;
            $site_url = $data->site_url;
            $current_site= "https://".$shop."/";
            //$current_site="https://icarryapp3.myshopify.com/";

            if($api_type=='Shopify'){
                if($current_site ==$site_url){
                    return response()->json(['message' => "connected"]);
                }
                else return response()->json(['message' => "site_url_error"]);
            }
            else{
                return response()->json(['message' => "plugin_error"]);
            }
        }
        else
        {
            return response()->json(['message'=>"input_error"]);
        }
})->middleware('shopify.auth');
