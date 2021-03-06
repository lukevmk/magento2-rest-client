<?php

namespace Ptchr\Magento2RestClient\Tests;

use GuzzleHttp\Exception\GuzzleException;
use PHPUnit\Framework\TestCase;
use Ptchr\Magento2RestClient\Client;
use Ptchr\Magento2RestClient\Exceptions\BillingAddressNotFoundException;
use Ptchr\Magento2RestClient\Exceptions\OrderNotFoundException;
use Ptchr\Magento2RestClient\Exceptions\ShippingAddressNotFoundException;

class ClientTest extends TestCase
{
    /**
     * @var Client
     */
    private $client;

    /**
     * @throws GuzzleException
     */
    public function setUp(): void
    {
        parent::setUp();
        $this->client = new Client(
            $_SERVER['BASE_URL'],
            $_SERVER['ADMIN_USERNAME'],
            $_SERVER['ADMIN_PASSWORD']
        );
    }

    /**
     * @test
     * @throws GuzzleException
     */
    public function searching_a_customer()
    {
        $customer = $this->client->searchCustomerByEmail($_SERVER['CUSTOMER_EMAIL']);
        $this->assertIsArray($customer);
    }

    /** @test *
     * @throws GuzzleException
     * @throws BillingAddressNotFoundException
     * @throws ShippingAddressNotFoundException
     */
    public function creating_a_customer_order()
    {
        $this->createOrder();
    }

    /** @test * */
    public function searching_orders()
    {
        $currentPage = 1;
        $resultsPerPage = 1;

        $orders = $this->client->getOrders($currentPage, $resultsPerPage);

        $this->assertCount($resultsPerPage, $orders['items']);
        $this->assertEquals($resultsPerPage, $orders['search_criteria']['current_page']);
        $this->assertEquals($resultsPerPage, $orders['search_criteria']['page_size']);
    }

    /** @test * */
    public function order_with_shipment()
    {
        $customer = $this->client->searchCustomerByEmail($_SERVER['CUSTOMER_EMAIL'])['items'][0];
        $customerId = $customer['id'];

        $quoteId = $this->client->createCart($customerId);
        $this->assertIsInt($quoteId);

        $cart = $this->client->addProductToCart($quoteId, $_SERVER['TEST_PRODUCT_SKU'], 3);
        $this->assertIsArray($cart);

        $shippingMethods = $this->client->estimateAvailableShippingMethodsForCart($customer, $quoteId);
        $shippingMethod = $shippingMethods[0];

        $shippingInfo = $this->client->addShippingInformationToCart(
            $customer,
            $quoteId,
            $shippingMethod['method_code'],
            $shippingMethod['carrier_code']
        );
        $this->assertIsArray($shippingInfo);

        $paymentMethods = $this->client->getAvailablePaymentMethodsForCart($quoteId);

        $this->assertIsArray($paymentMethods);

        $paymentMethod = 'checkmo';
        $this->assertNotNull($this->client->setPaymentInformation($quoteId, $paymentMethod, 'test'));

        $orderId = $this->client->createOrder($quoteId, $paymentMethod, false, 'test');
        $this->assertIsInt($orderId);
//
//        $invoice = $this->client->fullInvoiceOrder($orderId, false);
//        $this->assertNotNull($invoice);
//
//        $ship = $this->client->shipOrder($orderId);
//        $this->assertNotNull($ship);
    }

    /** @test * */
    public function getting_product_by_sku()
    {
        $product = $this->client->getProductBySku($_SERVER['TEST_PRODUCT_SKU']);
        $this->assertNotEmpty($product['items'][0]);
    }

    /** @test * */
    public function placing_order_comment()
    {
        $orderId = $this->createOrder();
        $comment = $this->client->addOrderComment($orderId, 'test comment');
        $this->assertTrue($comment);
    }

    private function createOrder()
    {
        $customer = $this->client->searchCustomerByEmail($_SERVER['CUSTOMER_EMAIL'])['items'][0];
        $customerId = $customer['id'];

        $quoteId = $this->client->createCart($customerId);
        $this->assertIsInt($quoteId);

        $cart = $this->client->addProductToCart($quoteId, $_SERVER['TEST_PRODUCT_SKU'], 3);
        $this->assertIsArray($cart);

        $shippingMethods = $this->client->estimateAvailableShippingMethodsForCart($customer, $quoteId);
        $shippingMethod = $shippingMethods[0];

        $shippingInfo = $this->client->addShippingInformationToCart(
            $customer,
            $quoteId,
            $shippingMethod['method_code'],
            $shippingMethod['carrier_code']
        );

        $this->assertIsArray($shippingInfo);

        $paymentMethods = $this->client->getAvailablePaymentMethodsForCart($quoteId);

        $this->assertIsArray($paymentMethods);

        $paymentMethod = 'purchaseorder';
        $this->assertNotNull($this->client->setPaymentInformation($quoteId, $paymentMethod, 'test'));

        $orderId = $this->client->createOrder($quoteId, $paymentMethod, true, 'test');
        $this->assertIsInt($orderId);

        $invoice = $this->client->fullInvoiceOrder($orderId);
        $this->assertNotNull($invoice);

        $this->assertNotNull($this->client->cancelOrder($orderId));

        $order = $this->client->getOrder($orderId);
        $this->assertIsArray($order);

        $this->expectException(OrderNotFoundException::class);
        $this->client->getOrder(123123123);

        $ordersByQuoteId = $this->client->searchOrdersQuoteId($quoteId);
        $this->assertIsArray($ordersByQuoteId);

        return $orderId;
    }

    /** @test **/
    public function retrieving_all_products()
    {
        $response = $this->client->getAllProducts(500);
        $this->assertIsArray($response);
        $this->assertNotEmpty($response['items']);
        $this->assertNotEmpty($response['total_count']);
    }

    /** @test **/
    public function storing_a_product_image()
    {
        $contents = 'iVBORw0KGgoAAAANSUhEUgAAACgAAAAoCAYAAACM/rhtAAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAAWtJREFUeNpi/P//P8NgBkwMgxyMOnDUgTDAyMhIDNYF4vNA/B+IDwCxHLoakgEoFxODiQRXQUYi4e3k2gfDjMRajsP3zED8F8pmA+JvUDEYeArEMugOpFcanA/Ef6A0CPwC4uNoag5SnAjJjGI2tKhkg4rLAfFGIH4IxEuBWIjSKKYkDfZCHddLiwChVhokK8YGohwEZYy3aBmEKmDEhOCgreomo+VmZHxsMEQxIc2MAx3FO/DI3RxMmQTZkI9ALDCaSUYdOOrAIeRAPzQ+PxCHUM2FFDb5paGNBPRa5C20bUhxc4sSB4JaLnvxVHWHsbVu6OnACjyOg+HqgXKgGRD/JMKBoD6LDb0dyAPE94hwHAw/hGYcujlwEQmOg+EV9HJgLBmOg+FMWjsQVKR8psCBoDSrQqoDSSmoG6Hpj1wA6ju30LI9+BBX4UsC+Ai0T4BWVd1EIL5PgeO+APECmoXgaGtm1IE0AgABBgAJAICuV8dAUAAAAABJRU5ErkJggg==';

        $sku = $_SERVER['TEST_PRODUCT_SKU'];

        $newProductImageId = $this->client->createProductImage(
            $sku,
            1,
            true,
            'test-storing-image.png',
            'image/png',
            $contents
        );

        $this->assertNotNull($newProductImageId);

        $removeProductImage = $this->client->removeProductImage($sku, $newProductImageId);
        $this->assertTrue($removeProductImage);
    }
}
