<?php

namespace Ptchr\Magento2RestClient\Tests;

use GuzzleHttp\Exception\GuzzleException;
use PHPUnit\Framework\TestCase;
use Ptchr\Magento2RestClient\Client;

class ClientTest extends TestCase
{
    /**
     * @test
     * @throws GuzzleException
     */
    public function searching_a_customer()
    {
        $client = new Client(
            $_SERVER['BASE_URL'],
            $_SERVER['ADMIN_USERNAME'],
            $_SERVER['ADMIN_PASSWORD']
        );

        $customer = $client->searchCustomerByEmail('info@meubelke.be');
        $this->assertIsArray($customer);
    }
}
