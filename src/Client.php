<?php

namespace Ptchr\Magento2RestClient;

use Carbon\Carbon;
use GuzzleHttp\Exception\GuzzleException;

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
    private $authenticatedAt = null;

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

        return json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
    }
}
