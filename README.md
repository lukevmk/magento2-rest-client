# Magento 2 rest api client

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ptchr/magento2-rest-client.svg?style=flat-square)](https://packagist.org/packages/ptchr/magento2-rest-client)
![Tests](https://github.com/cmesptchr/magento2-rest-client/workflows/Tests/badge.svg)
[![Total Downloads](https://img.shields.io/packagist/dt/ptchr/magento2-rest-client.svg?style=flat-square)](https://packagist.org/packages/cmesptchr/magento2-rest-client)

Installation
------------
##### Streamlines API calls through the Magento 2 REST API

You can install the package via composer:

```bash
composer require ptchr/magento2-rest-client
```

## Usage

Intitialize client
------------------
``` php
$client = new Ptchr\Magento2RestClient\Client('BASE_URL', 'ADMIN_USERNAME', 'ADMIN_PASSWORD');
```

Search customer by email
------------------------
``` php
$customer = $client->searchCustomerByEmail('john@example.com');
```

Create cart instance
--------------------
``` php
$quoteId = $client->createCart($customer['id']);
```

Add product to cart
-------------------
``` php
$cart = $client->addProductToCart($quoteId, 'SKU', 3);
```

Estimate available shipping methods for cart
--------------------------------------------
``` php
$shippingMethods = $client->estimateAvailableShippingMethodsForCart($customer, $quoteId);
```

Add shipping information to cart
--------------------------------
``` php
$shippingInfo = $client->addShippingInformationToCart($customer, $quoteId);
```

Add shipping information with selected shipping method
------------------------------------------------------
``` php
$shippingMethods = $client->estimateAvailableShippingMethodsForCart($customer, $quoteId);
$shippingMethod = $shippingMethods[0];
$shippingInfo = $client->addShippingInformationToCart($customer, $quoteId, $shippingMethod['method_code'], $shippingMethod['carrier_code']);
```

Get available payment methods for cart
------------------------
``` php
$paymentMethods = $this->client->getAvailablePaymentMethodsForCart($quoteId);
```

Set payment information
------------------------
``` php
$this->client->setPaymentInformation($quoteId, $paymentMethod);
```

Set payment information with purchase order number
--------------------------------------------------
``` php
$this->client->setPaymentInformation($quoteId, $paymentMethod, 'purchase_order_number');
```

Create order 
------------
``` php
$orderId = $client->createOrder($quoteId, $paymentMethod);
```

With purchase order number
--------------------------
``` php
$orderId = $client->createOrder($quoteId, $paymentMethod, '123');
```

Cancel order 
------------
``` php
$client->cancelOrder($orderId);
```

Testing
-------

``` bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Credits

- [Christiaan Mes](https://github.com/cmesptchr)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
