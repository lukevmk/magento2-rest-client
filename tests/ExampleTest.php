<?php

namespace Ptchr\Magento2RestClient\Tests;

use Dotenv\Dotenv;
use GuzzleHttp\Exception\GuzzleException;
use PHPUnit\Framework\TestCase;
use Ptchr\Magento2RestClient\Client;

class ExampleTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $dotEnv = Dotenv::createImmutable(__DIR__.'/../');
        $dotEnv->load();
    }

    /**
     * @test
     * @throws GuzzleException
     */
    public function searching_a_customer()
    {
        $client = new Client(
            $_ENV['BASE_URL'],
            $_ENV['ADMIN_USERNAME'],
            $_ENV['ADMIN_PASSWORD']
        );

        $customer = $client->searchCustomerByEmail('info@meubelke.be');
        $this->assertIsArray($customer);
    }
}
