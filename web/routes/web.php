<?php

use App\Exceptions\ShopifyProductCreatorException;
use App\Lib\AuthRedirection;
use App\Lib\EnsureBilling;
use App\Lib\ProductCreator;
use App\Models\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

use Shopify\Auth\OAuth;
use Shopify\Auth\Session as AuthSession;
use Shopify\Clients\HttpHeaders;
use Shopify\Clients\Rest;
use Shopify\Context;
use Shopify\Exception\InvalidWebhookException;

use Shopify\Webhooks\Registry;
use Shopify\Webhooks\Topics;



use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;
use Shopify\Rest\Admin2022_10\Order;
use Shopify\Rest\Admin2022_10\Product;
use Shopify\Rest\Admin2022_10\CarrierService;
use Shopify\Rest\Admin2022_10\Webhook;
use Shopify\Rest\Admin2022_10\FulfillmentOrder;

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
    console.log($shop);

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
});

Route::post('/api/shipping/getrate', function (Request $request) {
    $input = file_get_contents('php://input');

    // parse the request
    $rates = json_decode($input, true);

    // total up the cart quantities for simple rate calculations
    $quantity = 0;
    $weight = 0;
    foreach($rates['rate']['items'] as $item) {
        $quantity += $item['quantity'];
        $weight += $item['grams']/1000;
    }

    $token = Http::post('https://test.icarry.com/api-frontend/Authenticate/GetTokenForCustomerApi', [
        'Email' => 'api@customer.test',
        'Password' => 'Xyz78900'
    ])->object()->token;

    $carrier_rates = Http::withHeaders([
        'Authorization' => 'Bearer ' . $token,
    ])->post('https://test.icarry.com/api-frontend/SmartwareShipment/EstimateRatesByCOD', [
        'incluedShippingCost' => false,
        'CODAmount' => $quantity,
        'COdCurrency' => 'AED',
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

    $data = array();

    foreach($carrier_rates as $rate) {
        $arr = array(
            'service_name'=> $rate->Name,
            'service_code'=> empty($rate->MethodName)? "None":$rate->MethodName,
            'description' => $rate->Description,
            'total_price'=> $rate->Rate,
            'currency'=> 'AED',
            'min_delivery_date'=> date('Y-m-d H:i:s O', strtotime('+1 days')),
            'max_delivery_date'=> date('Y-m-d H:i:s O', strtotime('+2 days'))
        );
        array_push($data, $arr);
    }

    $res['rates'] = $data;

    header('Content-Type: application/json');
    echo json_encode($res);

})->name('shipping.getrate');

Route::post('/api/shipping/create_order', function (Request $request) {

    // Create options for the API
    $options = new Options();
    // $options->setType(true); // Makes it private
    $options->setVersion('2022-10');
    $options->setApiKey(env('SHOPIFY_API_KEY'));
    $options->setApiPassword(env('SHOPIFY_API_SECRET'));
    // Create the client and session
    $api = new BasicShopifyAPI($options);

    $api->setSession(new APISession('icarrytest3.myshopify.com','shpua_9afeee0456a420e7a614900cead889cc'));

    $filename = time();
    $input = file_get_contents('php://input');
    $orders = json_decode($input, true);


    // Now run your requests...
    $result = $api->rest('GET', '/admin/orders/'.$orders['order_id'].'.json');
    $quantity = 0;
    $weight = 0;
    foreach($orders['line_items'] as $item) {
        $quantity += $item['quantity'];
        $weight += $item['grams']/1000;
    }

    $token = Http::post('https://test.icarry.com/api-frontend/Authenticate/GetTokenForCustomerApi', [
        'Email' => 'api@customer.test',
        'Password' => 'Xyz78900'
    ])->object()->token;

    $create_orders = Http::withHeaders([
        'Authorization' => 'Bearer ' . $token,
    ])->post('https://test.icarry.com/api-frontend/SmartwareShipment/CreateOrder', [
        "ProcessOrder"=> false,
        "dropOffAddress"=> [
            "FirstName"=> $result['body']['order']['shipping_address']['first_name'],
            "LastName"=> $result['body']['order']['shipping_address']['last_name'],
            "Email"=> $result['body']['order']['customer']['email'],
            "PhoneNumber"=> empty( $result['body']['order']['shipping_address']['phone'])? 01234567:$result['body']['order']['shipping_address']['phone'],
            "Country"=> "lebanon",//$result['body']['order']['shipping_address']['country'],
            "City"=> $result['body']['order']['shipping_address']['city'],
            "Address1"=> "beirut,lebanon", //$result['body']['order']['shipping_address']['address1'],
            "Address2"=> empty( $result['body']['order']['shipping_address']['address2'])? 01234567:$result['body']['order']['shipping_address']['address2'],
            "ZipPostalCode"=> empty( $result['body']['order']['shipping_address']['zip'])? 01234567:$result['body']['order']['shipping_address']['zip'],
        ],

        "CODAmount"=> $quantity,
        "COdCurrency"=> $result['body']['order']['currency'],
        "ActualWeight"=> $weight,
        "PackageType"=> "Parcel",
        "Length"=> 1,
        "Width"=> 1,
        "Height"=> 1,

        "Notes"=> empty($result['body']['order']['note'])? '':$result['body']['order']['note'],
        "SystemShipmentProvider"=>(empty($result['body']['order']['shipping_lines'][0]['title']) || $result['body']['order']['shipping_lines'][0]['title']=="Standard")? null:"Shipping.".$result['body']['order']['shipping_lines'][0]['title'],
        "MethodName"=>($result['body']['order']['shipping_lines'][0]['code']=="None" || $result['body']['order']['shipping_lines'][0]['code']=="Standard")? null:$result['body']['order']['shipping_lines'][0]['code'],
        "MethodDescription"=>($result['body']['order']['shipping_lines'][0]['code']=="None" || $result['body']['order']['shipping_lines'][0]['code']=="Standard")? null:$result['body']['order']['shipping_lines'][0]['code'],
        "Price"=> $result['body']['order']['total_price']
    ])->object();

    $filename =  time();
    file_put_contents($filename.'-outputorder',json_encode($create_orders));

    return true;
})->name('create.shipping_order');

Route::post('/api/order/create', function (Request $request) {
    // $filename = time();
    // file_put_contents($filename.'-shop', "abcd");
    // $shop = Auth::user();
    // file_put_contents($filename.'-shop', $shop);

    // $domain = $shop->getDomain()->toNative();
    // file_put_contents($filename.'-domain', $domain);

    // $shopApi = $shop->api()->rest('GET', '/admin/shop.json')['body']['shop'];
    // file_put_contents($filename.'-shopApi', $shopApi);


    // Log::info("Shop {$domain}'s object:" . json_encode($shop));
    // Log::info("Shop {$domain}'s API objct:" . json_encode($shopApi));
    // Create options for the API
    $options = new Options();
    // $options->setType(true); // Makes it private
    $options->setVersion('2022-10');
    $options->setApiKey(env('SHOPIFY_API_KEY'));
    $options->setApiPassword(env('SHOPIFY_API_SECRET'));
    // Create the client and session
    $api = new BasicShopifyAPI($options);
    $api->setSession(new APISession('icarrytest3.myshopify.com','shpua_9afeee0456a420e7a614900cead889cc'));

    $filename = time();
    $input = file_get_contents('php://input');
    $order = json_decode($input, true);
    file_put_contents($filename.'-create-order-input', $input);

    // Now run your requests...
    $result = $api->rest('GET', '/admin/orders/'.$order['id'].'/fulfillment_orders.json');
    //$result = $api->rest('GET', '/admin/orders/5202996724002/fulfillment_orders.json');
    $fulfillment_orders = $result['body']['fulfillment_orders'];

    $token = Http::post('https://test.icarry.com/api-frontend/Authenticate/GetTokenForCustomerApi', [
        'Email' => 'api@customer.test',
        'Password' => 'Xyz78900'
    ])->object()->token;
    $test = 111;

    // file_put_contents($filename.'--fulfillment_orders', json_encode($fulfillment_orders));
    $result = $api->rest('GET', '/admin/orders/'.$order['id'].'.json');
    $got_order = $result['body']['order'];

    foreach($fulfillment_orders as $fulfillment_order)
    {
        $fulfillment_line_items = array();
        foreach($fulfillment_order->line_items as $ful_line_item)
        {
            $item = array(
                'id' => $ful_line_item->id,
                'quantity' => $ful_line_item->quantity
            );
            array_push($fulfillment_line_items, $item);
        }

        $lineitem = array(
            'fulfillment_order_id' => $fulfillment_order->id,
            'fulfillment_order_line_items' => $fulfillment_line_items
        );

        $line_item_by_fulfillment_order = array();
        array_push($line_item_by_fulfillment_order, $lineitem);

        $fulfillment = array('line_items_by_fulfillment_order'=>$line_item_by_fulfillment_order);

        $param = array('fulfillment' => $fulfillment);
        //file_put_contents($filename.'-fulfilitem', json_encode($param));

        $result = $api->rest('POST', '/admin/fulfillments.json', $param);
        $fulfillment_result = $result['body']['fulfillment'];

        file_put_contents($filename.'-response_fulfillment', json_encode($fulfillment_result));

        $quantity = 0;
        $weight = 0;
        foreach($fulfillment_result->line_items as $item) {
            $quantity += $item['quantity'];
            $weight += $item['grams']/1000;
        }


        $result = $api->rest('GET', '/admin/locations/'.$fulfillment_result->location_id.'.json');
        $location_address = $result['body']['location']['name'];
        file_put_contents($filename.'-location', $location_address);
        $create_orders = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->post('https://test.icarry.com/api-frontend/SmartwareShipment/CreateOrder', [
            "ProcessOrder"=> false,
            "pickupLocation"=> $location_address,
            "dropOffAddress"=> [
                "FirstName"=> $got_order['shipping_address']['first_name'],
                "LastName"=> $got_order['shipping_address']['last_name'],
                "Email"=> $got_order['customer']['email'],
                "PhoneNumber"=> empty( $got_order['shipping_address']['phone'])? 01234567:$got_order['shipping_address']['phone'],
                "Country"=> "lebanon",//$got_order['shipping_address']['country'],
                "City"=> $got_order['shipping_address']['city'],
                "Address1"=> "beirut,lebanon", //$got_order['shipping_address']['address1'],
                "Address2"=> empty( $got_order['shipping_address']['address2'])? 01234567:$got_order['shipping_address']['address2'],
                "ZipPostalCode"=> empty( $got_order['shipping_address']['zip'])? 01234567:$got_order['shipping_address']['zip'],
            ],

            "CODAmount"=> $quantity,
            "COdCurrency"=> $got_order['currency'],
            "ActualWeight"=> $weight,
            "PackageType"=> "Parcel",
            "Length"=> 1,
            "Width"=> 1,
            "Height"=> 1,

            "Notes"=> empty($got_order['note'])? '':$got_order['note'],
            "SystemShipmentProvider"=>(empty($got_order['shipping_lines'][0]['title']) || $got_order['shipping_lines'][0]['title']=="Standard")? null:"Shipping.".$got_order['shipping_lines'][0]['title'],
            "MethodName"=>($got_order['shipping_lines'][0]['code']=="None" || $got_order['shipping_lines'][0]['code']=="Standard")? null:$got_order['shipping_lines'][0]['code'],
            "MethodDescription"=>($got_order['shipping_lines'][0]['code']=="None" || $got_order['shipping_lines'][0]['code']=="Standard")? null:$got_order['shipping_lines'][0]['code'],
            "Price"=> $got_order['total_price']
        ])->object();

        $filename =  time();
        file_put_contents($filename.'-carrier_response',json_encode($create_orders));

        $tracking_number = $create_orders->TrackingNumber;
        //file_put_contents($filename.'-trackingNumber',$tracking_number);

        $tracking_info= array("number" => $tracking_number);

        $fulfill_track = array(
            'nofity_customer'=>true,
            'tracking_info'=>$tracking_info
        );

        $param = array('fulfillment' => $fulfill_track);
        file_put_contents($filename.'-ful_track', json_encode($param));

        $result = $api->rest('POST', '/admin/fulfillments/'.$fulfillment_result->id.'/update_tracking.json',$param);
        $fulfillment_update = $result['body']['fulfillment'];
        file_put_contents($filename.'-response_fulfillment_update', json_encode($fulfillment_update));
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
            if($webhook->topic == 'fulfillments/create' || $webhook->topic == 'orders/create')
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

        return response()->json(['message' => "Added service and webhook"], 200);
    }
    catch (\Exception $e) {
        Log::error("Got an exception when adding service and webhook: {$e->getMessage()}");
        return response()->json(['message' => $e->getMessage()], 500);
}

})->middleware('shopify.auth');
