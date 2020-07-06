<?php

namespace Ptchr\Magento2RestClient\Tests;

use GuzzleHttp\Exception\GuzzleException;
use PHPUnit\Framework\TestCase;
use Ptchr\Magento2RestClient\Client;

class ClientTest extends TestCase
{
    /**
     * @var Client
     */
    private Client $client;

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

    /** @test * */
    public function creating_a_customer_cart_instance()
    {
        $customer = $this->client->searchCustomerByEmail($_SERVER['CUSTOMER_EMAIL'])['items'][0];
        $customerId = $customer['id'];

        $quoteId = $this->client->createCart($customerId);
        $this->assertIsInt($quoteId);

        $cart = $this->client->addProductToCart($quoteId, 'WS03-XS-Red', 3);
        $this->assertIsArray($cart);

        $shippingInfo = $this->client->addShippingInformationToCart($customer, $quoteId);
        $this->assertIsArray($shippingInfo);

        $paymentMethods = $this->client->getPaymentMethods($quoteId);
        $this->assertIsArray($paymentMethods);

       $order = $this->client->createOrder($quoteId, 'checkmo');
       $this->assertNotNull($order);
    }
}
