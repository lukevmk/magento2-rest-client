<?php

namespace Ptchr\Magento2RestClient;

use Carbon\Carbon;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Ptchr\Magento2RestClient\Exceptions\BillingAddressNotFoundException;
use Ptchr\Magento2RestClient\Exceptions\OrderNotFoundException;
use Ptchr\Magento2RestClient\Exceptions\ShippingAddressNotFoundException;

class Client
{
    /**
     * @var string
     */
    private $baseUrl;

    /**
     * @var string
     */
    private $username;

    /**
     * @var string
     */
    private $password;

    /**
     * @var \GuzzleHttp\Client
     */
    private $guzzle;

    /**
     * @var string
     */
    private $apiPrefix = '/rest/V1/';

    /**
     * @var Carbon|null
     */
    private $authenticatedAt;

    /**
     * @var string
     */
    private $accessToken;

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
     * @param string $method
     * @param string $url
     * @param array $options
     * @return mixed
     * @throws GuzzleException
     */
    private function request(string $method, string $url, array $options = [])
    {
        $auth = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type' => 'application/json',
            ],
        ];

        $options = array_merge($auth, $options);

        return $this->formatResponseData($this->guzzle->request($method, $url, $options));
    }

    /**
     * @param string $email
     * @return array
     * @throws GuzzleException
     */
    public function searchCustomerByEmail(string $email): array
    {
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

        return $this->request('get', $this->baseUrl . $this->apiPrefix . 'customers/search', [
            'query' => $parameters,
        ]);
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
        return $this->request('post', $this->baseUrl . $this->apiPrefix . 'customers/' . $customerId . '/carts');
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
        return $this->request('post', $this->baseUrl . $this->apiPrefix . 'carts/mine/items', [
            'json' => [
                'cartItem' => [
                    'sku' => $sku,
                    'qty' => $quantity,
                    'quote_id' => $quoteId,
                ],
            ],
        ]);
    }

    /**
     * @param int $quoteId
     * @param array $customer
     * @return mixed
     * @throws GuzzleException
     * @throws ShippingAddressNotFoundException
     */
    public function estimateAvailableShippingMethodsForCart(array $customer, int $quoteId)
    {
        $shippingAddress = $this->findShippingAddress($customer);

        return $this->request(
            'post',
            $this->baseUrl . $this->apiPrefix . 'carts/' . $quoteId . '/estimate-shipping-methods',
            [
                'json' => [
                    'address' => $shippingAddress,
                ],
            ]
        );
    }

    /**
     * @param array $customer
     * @param int $quoteId
     * @param string $methodCode
     * @param string $carrierCode
     * @return mixed
     * @throws BillingAddressNotFoundException
     * @throws GuzzleException
     * @throws ShippingAddressNotFoundException
     */
    public function addShippingInformationToCart(
        array $customer,
        int $quoteId,
        string $methodCode = 'flatrate',
        string $carrierCode = 'flatrate'
    ) {
        $shippingAddress = $this->findShippingAddress($customer);
        $billingAddress = $this->findBillingAddress($customer);

        $data = [
            'addressInformation' => [
                'shippingAddress' => $shippingAddress,
                'billingAddress' => $billingAddress,
                'shipping_method_code' => $methodCode,
                'shipping_carrier_code' => $carrierCode,
            ],
        ];

        return $this->request(
            'post',
            $this->baseUrl . $this->apiPrefix . 'carts/' . $quoteId . '/shipping-information',
            [
                'json' => $data,
            ]
        );
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
        return $this->request(
            'get',
            $this->baseUrl . $this->apiPrefix . 'carts/' . $quoteId . '/payment-methods',
        );
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

        return $this->request(
            'put',
            $this->baseUrl . $this->apiPrefix . 'carts/' . $quoteId . '/selected-payment-method',
            [
                'json' => $data,
            ]
        );
    }

    /**
     * @param int $quoteId
     * @param string $paymentMethod
     * @param bool $isVirtual
     * @param string|null $purchaseOrderNumber
     * @return int OrderID
     * @throws GuzzleException
     */
    public function createOrder(
        int $quoteId,
        string $paymentMethod,
        bool $isVirtual = false,
        string $purchaseOrderNumber = null
    ): int {
        $data = [
            'paymentMethod' => [
                'method' => $paymentMethod,
            ],
        ];

        if ($purchaseOrderNumber !== null) {
            $data['paymentMethod']['po_number'] = $purchaseOrderNumber;
        }

        $orderId = (int)$this->request(
            'put',
            $this->baseUrl . $this->apiPrefix . 'carts/' . $quoteId . '/order',
            [
                'json' => $data,
            ]
        );

        if (! $isVirtual) {
            return $orderId;
        }

        $this->request(
            'post',
            $this->baseUrl . $this->apiPrefix . 'orders',
            [
                'json' => [
                    'entity' => [
                        'entity_id' => $orderId,
                        'is_virtual' => 1,
                    ],
                ],
            ]
        );

        return $orderId;
    }

    /**
     * @param int $orderId
     * @return mixed
     * @throws GuzzleException
     */
    public function cancelOrder(int $orderId)
    {
        return $this->request(
            'post',
            $this->baseUrl . $this->apiPrefix . 'orders/' . $orderId . '/cancel',
        );
    }

    /**
     * @param int $orderId
     * @param bool $paid
     * @return mixed
     * @throws GuzzleException
     */
    public function fullInvoiceOrder(int $orderId, bool $paid = true)
    {
        if ($paid) {
            $data = [
                'json' => [
                    'capture' => true,
                    'notify' => true,
                ],
            ];
        } else {
            $data = [];
        }

        return $this->request(
            'post',
            $this->baseUrl . $this->apiPrefix . 'order/' . $orderId . '/invoice',
            $data
        );
    }

    /**
     * @param $orderId
     * @return mixed
     * @throws GuzzleException
     */
    public function shipOrder($orderId)
    {
        return $this->request(
            'post',
            $this->baseUrl . $this->apiPrefix . 'order/' . $orderId . '/ship'
        );
    }

    /**
     * @param int $orderId
     * @return mixed
     * @throws GuzzleException
     * @throws OrderNotFoundException
     */
    public function getOrder(int $orderId)
    {
        try {
            return $this->request(
                'get',
                $this->baseUrl . $this->apiPrefix . 'orders/' . $orderId,
            );
        } catch (ClientException $exception) {
            if ($exception->getCode() === 404) {
                throw new OrderNotFoundException('Order with id: ' . $orderId . ' not found!');
            }

            throw new ClientException(
                $exception->getMessage(),
                $exception->getRequest(),
                $exception->getResponse(),
                $exception
            );
        }
    }

    /**
     * @param int $currentPage
     * @param int $resultsPerPage
     * @param array $filters
     * @param array $sortBy
     * @return mixed
     * @throws GuzzleException
     * @throws \Exception
     */
    public function getOrders(int $currentPage = 1, int $resultsPerPage = 12, array $filters = [], array $sortBy = [])
    {
        $parameters = [
            'searchCriteria' => [
                'currentPage' => $currentPage,
                'pageSize' => $resultsPerPage,
                'filterGroups' => [],
            ],
        ];

        foreach ($filters as $filter) {
            if (! isset($filter['field'], $filter['value'])) {
                throw new \Exception('Filter must contain the field and value key + value');
            }

            $parameters['searchCriteria']['filterGroups']['filters'][] = $filter;
        }

        foreach ($sortBy as $sort) {
            if (! isset($sort['direction'], $sort['field'])) {
                throw new \Exception('Filter must contain the direction and field key + value');
            }

            $parameters['sortOrders'][] = $sort;
        }

        return $this->request('get', $this->baseUrl . $this->apiPrefix . 'orders', [
            'query' => $parameters,
        ]);
    }

    /**
     * @param int $quoteId
     * @return array
     * @throws GuzzleException
     */
    public function searchOrdersQuoteId(int $quoteId): array
    {
        $parameters = [
            'searchCriteria' => [
                'pageSize' => 1,
                'filterGroups' => [
                    [
                        'filters' => [
                            [
                                'field' => 'quote_id',
                                'value' => $quoteId,
                            ],
                        ],
                    ],
                ],

            ],
        ];

        return $this->request('get', $this->baseUrl . $this->apiPrefix . 'orders', [
            'query' => $parameters,
        ]);
    }
}
