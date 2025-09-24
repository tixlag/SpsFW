<?php

namespace SpsFW\Core\Psr;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class GuzzleHttpClientAdapter implements HttpClientInterface
{
    private Client $client;

    public function __construct(private array $config = [])
    {
        $this->client = new Client($config);
    }

    public function send(RequestInterface $request): ResponseInterface
    {
        return $this->client->send($request);
    }
}