<?php

namespace Ptchr\Magento2RestClient;

use Carbon\Carbon;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Ptchr\Magento2RestClient\Exceptions\BillingAddressNotFoundException;
use Ptchr\Magento2RestClient\Exceptions\ShippingAddressNotFoundException;

class Client
{
    /**
     * @var string
     */
    private string $baseUrl;

    /**
     * @var string
     */
    private string $username;

    /**
     * @var string
     */
    private string $password;

    /**
     * @var \GuzzleHttp\Client
     */
    private $guzzle;

    /**
     * @var string
     */
    private string $apiPrefix = '/rest/V1/';

    /**
     * @var Carbon|null
     */
    private ?Carbon $authenticatedAt = null;

    /**
     * @var string
     */
    private string $accessToken;

    /**
     * Client constructor.
     * @param string $baseUrl
     * @param string $username
     * @param string $password
     * @throws GuzzleException
     */
    public function __construct(string $baseUrl, string $username, string $password)
    {
        $this->baseUrl = $baseUrl;
        $this->username = $username;
        $this->password = $password;
        $this->guzzle = new \GuzzleHttp\Client();
        $this->authenticate();
    }

    /**
     * @throws GuzzleException
     */
    private function authenticate(): void
    {
        $isAuthenticated = $this->authenticatedAt instanceof Carbon && Carbon::now()->gt($this->authenticatedAt->addHours(4));

        if ($isAuthenticated) {
            return;
        }

        $response = $this->guzzle->post($this->baseUrl . $this->apiPrefix . 'integration/admin/token', [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'username' => $this->username,
                'password' => $this->password,
            ],
        ]);

        $this->authenticatedAt = Carbon::now();
        $this->accessToken = str_replace('"', '', $response->getBody()->getContents());
    }

    /**
     * @param ResponseInterface $response
     * @return mixed
     */
    private function formatResponseData(ResponseInterface $response)
    {
        return json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param string $email
     * @return array
     * @throws GuzzleException
     */
    public function searchCustomerByEmail(string $email): array
    {
        $this->authenticate();
        $parameters = [
            'searchCriteria' => [
                'pageSize' => 1,
                'filterGroups' => [
                    [
                        'filters' => [
                            [
                                'field' => 'email',
                                'value' => $email,
                            ],
                        ],
                    ],
                ],

            ],
        ];

        $response = $this->guzzle->get($this->baseUrl . $this->apiPrefix . 'customers/search', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type' => 'application/json',
            ],

            'query' => $parameters,
        ]);

        return $this->formatResponseData($response);
    }

    /**
     * Creates a new Magento 2 Cart, returns the quote_id
     *
     * @param int $customerId
     * @return int
     * @throws GuzzleException
     */
    public function createCart(int $customerId): int
    {
        $response = $this->guzzle->post($this->baseUrl . $this->apiPrefix . 'customers/' . $customerId . '/carts', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type' => 'application/json',
            ],
        ]);

        return $this->formatResponseData($response);
    }

    /**
     * @param int $quoteId
     * @param string $sku
     * @param int $quantity
     * @return mixed
     * @throws GuzzleException
     */
    public function addProductToCart(int $quoteId, string $sku, int $quantity)
    {
        $response = $this->guzzle->post($this->baseUrl . $this->apiPrefix . 'carts/mine/items', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type' => 'application/json',
            ],

            'json' => [
                'cartItem' => [
                    'sku' => $sku,
                    'qty' => $quantity,
                    'quote_id' => $quoteId,
                ],
            ],
        ]);

        return $this->formatResponseData($response);
    }

    /**
     * @param array $customer
     * @param int $quoteId
     * @return mixed
     * @throws BillingAddressNotFoundException
     * @throws GuzzleException
     * @throws ShippingAddressNotFoundException
     */
    public function addShippingInformationToCart(array $customer, int $quoteId)
    {
        $shippingAddress = $this->findShippingAddress($customer);
        $billingAddress = $this->findBillingAddress($customer);


        $response = $this->guzzle->post(
            $this->baseUrl . $this->apiPrefix . 'carts/' . $quoteId . '/shipping-information',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json',
                ],

                'json' => [
                    'addressInformation' => [
                        'shippingAddress' => $shippingAddress,
                        'billingAddress' => $billingAddress,
                        'shipping_method_code' => 'flatrate',
                        'shipping_carrier_code' => 'flatrate',
                    ],
                ],
            ]
        );


        return $this->formatResponseData($response);
    }

    /**
     * @param array $customer
     * @return array
     * @throws BillingAddressNotFoundException
     */
    private function findBillingAddress(array $customer): array
    {
        foreach ($customer['addresses'] as $address) {
            if (array_key_exists('default_billing', $address) && $address['default_billing'] === true) {
                return $this->mapAddressFields($customer, $address);
            }
        }

        throw new BillingAddressNotFoundException('Billing address not found for customer');
    }

    /**
     * @param array $customer
     * @return array|null
     * @throws ShippingAddressNotFoundException
     */
    private function findShippingAddress(array $customer): ?array
    {
        foreach ($customer['addresses'] as $address) {
            if (array_key_exists('default_shipping', $address) && $address['default_shipping'] === true) {
                return $this->mapAddressFields($customer, $address);
            }
        }

        throw new ShippingAddressNotFoundException('Shipping address not found for customer');
    }

    /**
     * @param int $quoteId
     * @return array
     * @throws GuzzleException
     */
    public function getAvailablePaymentMethodsForCart(int $quoteId): array
    {
        $response = $this->guzzle->get(
            $this->baseUrl . $this->apiPrefix . 'carts/' . $quoteId . '/payment-methods',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json',
                ],
            ]
        );

        return $this->formatResponseData($response);
    }

    /**
     * @param array $customer
     * @param array $address
     * @return array
     */
    private function mapAddressFields(array $customer, array $address)
    {
        return [
            'firstname' => $address['firstname'],
            'lastname' => $address['lastname'],
            'postcode' => $address['postcode'],
            'email' => $customer['email'],
            'street' => $address['street'],
            'telephone' => $address['telephone'],
            'country_id' => $address['country_id'],
            'city' => $address['city'],
            'region' => $address['region']['region'],
            'region_code' => $address['region']['region_code'],
            'region_id' => $address['region']['region_id'],
        ];
    }

    /**
     * @param int $quoteId
     * @param string $paymentMethod
     * @param string|null $purchaseOrderNumber
     * @return string OrderID
     * @throws GuzzleException
     */
    public function setPaymentInformation(
        int $quoteId,
        string $paymentMethod,
        string $purchaseOrderNumber = null
    ): string {
        $data = [
            'method' => [
                'method' => $paymentMethod,
            ],
        ];

        if ($purchaseOrderNumber !== null) {
            $data['method']['po_number'] = $purchaseOrderNumber;
        }

        $response = $this->guzzle->put(
            $this->baseUrl . $this->apiPrefix . 'carts/' . $quoteId . '/selected-payment-method',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json',
                ],

                'json' => $data,
            ]
        );

        return $this->formatResponseData($response);
    }

    /**
     * @param int $quoteId
     * @param string $paymentMethod
     * @param string|null $purchaseOrderNumber
     * @return string OrderID
     * @throws GuzzleException
     */
    public function createOrder(int $quoteId, string $paymentMethod, string $purchaseOrderNumber = null): string
    {
        $data = [
            'paymentMethod' => [
                'method' => $paymentMethod,
            ],
        ];

        if ($purchaseOrderNumber !== null) {
            $data['paymentMethod']['po_number'] = $purchaseOrderNumber;
        }

        $response = $this->guzzle->put(
            $this->baseUrl . $this->apiPrefix . 'carts/' . $quoteId . '/order',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json',
                ],

                'json' => $data,
            ]
        );

        return $this->formatResponseData($response);
    }
}
