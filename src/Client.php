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
    protected $baseUrl;

    /**
     * @var string
     */
    protected $username;

    /**
     * @var string
     */
    protected $password;

    /**
     * @var \GuzzleHttp\Client
     */
    protected $guzzle;

    /**
     * @var string
     */
    protected $apiPrefix = '/rest/V1/';

    /**
     * @var string
     */
    protected $allApiPrefix = '/rest/all/V1/';

    /**
     * @var Carbon|null
     */
    protected $authenticatedAt;

    /**
     * @var string
     */
    protected $accessToken;

    /**
     * Client constructor.
     * @param string $baseUrl
     * @param string $username
     * @param string $password
     */
    public function __construct(string $baseUrl, string $username, string $password)
    {
        $this->baseUrl = $baseUrl;
        $this->username = $username;
        $this->password = $password;
        $this->guzzle = new \GuzzleHttp\Client();
        $this->authenticate();
    }

    protected function authenticate(): void
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
        $this->accessToken = trim(str_replace('"', '', $response->getBody()->getContents()));
    }

    /**
     * @param ResponseInterface $response
     * @return mixed
     * @throws \JsonException
     */
    protected function formatResponseData(ResponseInterface $response)
    {
        return json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param string $method
     * @param string $url
     * @param array $options
     * @return mixed
     * @throws GuzzleException
     * @throws \JsonException
     */
    protected function request(string $method, string $url, array $options = [])
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
     * @param array $customer
     * @return array
     * @throws GuzzleException
     * @throws \JsonException
     */
    public function createCustomer(array $customer): array
    {
        return $this->request('post', $this->baseUrl . $this->apiPrefix . 'customers', [
            'json' => [
                'customer' => $customer,
            ],
        ]);
    }

    /**
     * @param array $customer
     * @return array
     * @throws GuzzleException
     * @throws \JsonException
     */
    public function updateCustomer(int $customerId, array $customer): array
    {
        return $this->request('put', $this->baseUrl . $this->apiPrefix . 'customers/' . $customerId, [
            'json' => [
                'customer' => $customer,
            ],
        ]);
    }

    /**
     * @param int $page
     * @param int $pageSize
     * @param array $filterGroups Like `[[filters => [[field => 'email', conditionType => 'eq' | 'like', value => '%@e.mail']]]]`
     *     See https://magento.redoc.ly/2.3.7-admin/tag/customerssearch
     *     and https://devdocs.magento.com/guides/v2.4/extension-dev-guide/searching-with-repositories.html#filter-group.
     *     FilterGroups are joined AND, filters within FilterGroups are joined OR.
     * @return array
     * @throws GuzzleException
     * @throws \JsonException
     */
    public function getCustomers(int $page = 1, int $pageSize = 25, array $filterGroups = []): array
    {
        $parameters = [
            'searchCriteria' => [
                'pageSize' => $pageSize,
                'currentPage' => $page,
                'filterGroups' => $filterGroups,
            ],
        ];

        return $this->request('get', $this->baseUrl . $this->apiPrefix . 'customers/search', [
            'query' => $parameters,
        ]);
    }

    /**
     * @param int $customerId
     * @return bool
     * @throws GuzzleException
     * @throws \JsonException
     */
    public function deleteCustomer(int $customerId): bool
    {
        return $this->request('delete', $this->baseUrl . $this->apiPrefix . 'customers/' . $customerId);
    }

    /**
     * @param string $email
     * @return array
     * @throws GuzzleException
     * @throws \JsonException
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
     * @throws \JsonException
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
     * @throws \JsonException
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
     * @param array $customer
     * @param int $quoteId
     * @return mixed
     * @throws GuzzleException
     * @throws ShippingAddressNotFoundException
     * @throws \JsonException
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
     * @throws \JsonException
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
    protected function findBillingAddress(array $customer): array
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
    protected function findShippingAddress(array $customer): ?array
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
     * @throws \JsonException
     */
    public function getAvailablePaymentMethodsForCart(int $quoteId): array
    {
        return $this->request(
            'get',
            $this->baseUrl . $this->apiPrefix . 'carts/' . $quoteId . '/payment-methods'
        );
    }

    /**
     * @param array $customer
     * @param array $address
     * @return array
     */
    protected function mapAddressFields(array $customer, array $address): array
    {
        return [
            'firstname' => $address['firstname'],
            'lastname' => $address['lastname'],
            'company' => $address['company'] ?? null,
            'postcode' => $address['postcode'],
            'email' => $customer['email'],
            'street' => $address['street'],
            'telephone' => $address['telephone'],
            'country_id' => $address['country_id'],
            'city' => $address['city'],
            'region' => $address['region']['region'] ?? null,
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
     * @throws \JsonException
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
     * @throws \JsonException
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
     * @throws \JsonException
     */
    public function cancelOrder(int $orderId)
    {
        return $this->request(
            'post',
            $this->baseUrl . $this->apiPrefix . 'orders/' . $orderId . '/cancel'
        );
    }

    /**
     * @param int $orderId
     * @param bool $paid
     * @return mixed
     * @throws GuzzleException
     * @throws \JsonException
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
     * @throws \JsonException
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
     * @throws \JsonException
     */
    public function getOrder(int $orderId)
    {
        try {
            return $this->request(
                'get',
                $this->baseUrl . $this->apiPrefix . 'orders/' . $orderId
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

            $parameters['searchCriteria']['filterGroups'][]['filters'][] = $filter;
        }

        foreach ($sortBy as $sort) {
            if (! isset($sort['direction'], $sort['field'])) {
                throw new \Exception('Filter must contain the direction and field key + value');
            }

            $parameters['searchCriteria']['sortOrders'][] = $sort;
        }

        return $this->request('get', $this->baseUrl . $this->apiPrefix . 'orders', [
            'query' => $parameters,
        ]);
    }

    /**
     * @param int $quoteId
     * @return array
     * @throws GuzzleException
     * @throws \JsonException
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

    /**
     * @param int $pageSize
     * @param int $currentPage
     * @return mixed
     * @throws GuzzleException
     * @throws \JsonException
     */
    public function getAllProducts(int $pageSize, int $currentPage = 1)
    {
        $parameters = [
            'query' => [
                'searchCriteria' => [
                    'pageSize' => $pageSize,
                    'currentPage' => $currentPage,
                ],
            ],
        ];

        return $this->request('get', $this->baseUrl . $this->apiPrefix . 'products', $parameters);
    }

    /**
     * @param string $sku
     * @return mixed
     * @throws GuzzleException
     * @throws \JsonException
     */
    public function getProductBySku(string $sku)
    {
        $parameters = [
            'searchCriteria' => [
                'pageSize' => 1,
                'filterGroups' => [
                    [
                        'filters' => [
                            [
                                'field' => 'sku',
                                'value' => $sku,
                            ],
                        ],
                    ],
                ],

            ],
        ];

        return $this->request('get', $this->baseUrl . $this->apiPrefix . 'products', [
            'query' => $parameters,
        ]);
    }

    /**
     * @param int $orderId
     * @param string $comment
     * @param bool $notifyCustomer
     * @param bool $visibleOnStoreFront
     * @return mixed
     * @throws GuzzleException
     * @throws \JsonException
     */
    public function addOrderComment(
        int $orderId,
        string $comment,
        bool $notifyCustomer = false,
        bool $visibleOnStoreFront = false
    ) {
        return $this->request('post', $this->baseUrl . $this->apiPrefix . 'orders/' . $orderId . '/comments', [
            'json' => [
                'statusHistory' => [
                    'comment' => $comment,
                    'is_customer_notified' => (int)$notifyCustomer,
                    'is_visible_on_front' => (int)$visibleOnStoreFront,
                    'parent_id' => 0,
                ],
            ],
        ]);
    }

    /**
     * Stores a product image from all store views
     *
     * @param string $sku
     * @param int $position
     * @param bool $disabled
     * @param string $fileName
     * @param string $mimeType
     * @param string $contents
     * @param array $types
     * @return mixed
     * @throws GuzzleException
     * @throws \JsonException
     */
    public function createProductImage(
        string $sku,
        int $position,
        bool $disabled,
        string $fileName,
        string $mimeType,
        string $contents,
        array $types = []
    ) {
        $parameters = [
            'json' => [
                'entry' => [
                    'media_type' => 'image',
                    'label' => 'Image',
                    'position' => $position,
                    'disabled' => $disabled,
                    'file' => $fileName,
                    'types' => $types,
                    'content' => [
                        'base64EncodedData' => $contents,
                        'type' => $mimeType,
                        'name' => $fileName,
                    ],
                ],
            ],
        ];

        return $this->request(
            'post',
            $this->baseUrl . $this->allApiPrefix . 'products/' . $sku . '/media',
            $parameters
        );
    }

    /**
     * Removes a product image from all store views
     *
     * @param string $sku
     * @param int $mediaId
     * @return mixed
     * @throws GuzzleException
     * @throws \JsonException
     */
    public function removeProductImage(string $sku, int $mediaId)
    {
        return $this->request(
            'delete',
            $this->baseUrl . $this->allApiPrefix . 'products/' . $sku . '/media/' . $mediaId
        );
    }

    /**
     * @return array
     * @throws GuzzleException
     * @throws \JsonException
     */
    public function getWebsites(): array
    {
        return $this->request(
            'get',
            $this->baseUrl . $this->allApiPrefix . 'store/websites'
        );
    }

    /**
     * @return array
     * @throws GuzzleException
     * @throws \JsonException
     */
    public function getStoreViews(): array
    {
        $storeViews = $this->request('get', $this->baseUrl.$this->allApiPrefix.'store/storeViews');

        $storeViews = array_filter($storeViews, function ($storeView) {
            return $storeView['code'] !== 'admin';
        });

        return $storeViews;
    }

    /**
     * @return array
     * @throws GuzzleException
     * @throws \JsonException
     */
    public function getStoreGroups(): array
    {
        return $this->request(
            'get',
            $this->baseUrl . $this->allApiPrefix . 'store/storeGroups'
        );
    }
}
