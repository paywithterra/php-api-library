# PaywithTerra PHP Library
Officially supported PHP library for using PaywithTerra APIs.

## Prerequisites
PHP version 5.6, 7.0, 7.1, 7.2, 7.3, or 7.4
PHP extensions: ext-json, ext-curl, ext-mbstring 


## Installation

You can use [Composer](https://getcomposer.org/). Follow the [installation instructions](https://getcomposer.org/doc/00-intro.md) if you do not already have composer installed.

~~~~ bash
composer require paywithterra/php-api-library
~~~~

In your PHP script, make sure you include the autoloader:

~~~~ php
require __DIR__ . '/vendor/autoload.php';
~~~~

Alternatively, you can download the [release on GitHub](https://github.com/paywithterra/php-api-library/releases) 
and use file [src/PaywithTerraClient.php](src/PaywithTerraClient.php) separately.

## Using the library

### Create order
~~~~ php
$client1 = new \PaywithTerra\PaywithTerraClient('YOUR_REAL_API_KEY');

$orderInfo = $client1->createOrder([
    "address" => "terra14wvwnyjgsljdps5g3uy9jp2veysd4psu8aytk7",
    "memo" => '#order-1234',
    "webhook" => "http://your-website.com/webhook-pay-complete",
    "amount" => 9990000, // if you want to ask for 9.99 USD
    "denom" => "uusd",
    "return_url" => "http://your-website.com/order/1234"
]);

if($client1->getLastResponseCode() === 200){
    $uuid = $orderInfo['uuid'];
    // Here save uuid to database for later checking purposes
}
~~~~

### Incoming info when order fulfilled
When order is paid PaywithTerra calls your webhook with special signal.
You can check integrity of that data by calling special method.

~~~~ php
$client1 = new \PaywithTerra\PaywithTerraClient('YOUR_REAL_API_KEY');

$realData = $client1->checkIncomingData($_POST);
// If data comes not from PaywithTerra
// (e.g. if not signed with your API key)
// the library will throw an Exception

$uuid = $realData['uuid'];
$memo = $realData['memo'];
$txhash = $realData['txhash'];

// See https://paywithterra.com/docs/api#post-back to know all possible keys
~~~~

### Checking order status at any time (Second check)
~~~~ php
$client1 = new \PaywithTerra\PaywithTerraClient('YOUR_REAL_API_KEY');

// $orderInfo - array with fields "is_payed", "txhash", etc.
// See documentation: https://paywithterra.com/docs/api#second-check
$orderInfo = $client1->getOrderStatusByUUID('yourOrderUUIDFromPaywithTerra123123');

// Or you can just check if order fulfilled at any time
if($client1->isOrderPayedByUUID('yourOrderUUIDFromPaywithTerra123123')){
    // Client already made his payment
}
~~~~

## Notes
We don't use any of modern libraries (like Guzzle or Symphony HTTP Client) as transport 
because we want to keep the minimal supported PHP version as low as possible.  
This approach allows even oldschool and legacy PHP-projects to connect to PaywithTerra.


## License
[The MIT License (MIT)](LICENSE)