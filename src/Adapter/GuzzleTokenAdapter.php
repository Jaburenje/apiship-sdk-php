<?php

namespace Apiship\Adapter;

use Apiship\Exception\ExceptionInterface;
use Apiship\Exception\ResponseException;
use Guzzle\Common\Event;
use Guzzle\Http\Client;
use Guzzle\Http\ClientInterface;
use Guzzle\Http\Message\Response;

class GuzzleTokenAdapter extends AbstractAdapter implements AdapterInterface
{
    /**
     * @var ClientInterface
     */
    protected $client;

    /**
     * @var Response
     */
    protected $response;

    /**
     * @var ExceptionInterface
     */
    protected $exception;

    /**
     * @param string             $accessToken
     * @param bool               $test      (optional)
     * @param ClientInterface    $client    (optional)
     * @param ExceptionInterface $exception (optional)
     * @param string             $platform  (optional)
     */
    public function __construct(
        $accessToken,
        $test = false,
        ClientInterface $client = null,
        ExceptionInterface $exception = null,
        $platform = null
    ) {
        $this->setToken($accessToken);

        $this->test = (bool)$test;

        $that            = $this;
        $this->client    = $client ?: new Client();
        $this->exception = isset($exception) ? $exception : new ResponseException();

        $this->client
            // Set default Authorization header for all request
            ->setDefaultOption('headers/Authorization', $this->getAccessToken())
            // Subscribe completed request event
            ->setDefaultOption('events/request.complete', function (Event $event) use ($that) {
                $that->handleResponse($event);
                $event->stopPropagation();
            });

        if (isset($_SERVER['X-Tracing-Id'])) {
            $this->client->setDefaultOption('query/X-Tracing-Id', $_SERVER['X-Tracing-Id']);
        }

        if ($platform) {
            $this->client->setDefaultOption('headers/platform', $platform);
        }
    }

    /**
     * {@inheritdoc}
     * @throws \Guzzle\Http\Exception\RequestException
     */
    public function get($url, array $headers = [], array $query = [])
    {
        $this->response = $this->client->get(
            $this->getUrl() . $url,
            $headers,
            ['query' => $query]
        )->send();

        return $this->response->getBody(true);
    }

    /**
     * {@inheritdoc}
     */
    public function delete($url, array $headers = [])
    {
        $this->response = $this->client->delete($this->getUrl() . $url, $headers)->send();

        return $this->response->getBody(true);
    }

    /**
     * {@inheritdoc}
     */
    public function put($url, array $headers = [], $content = '')
    {
        $headers['content-type'] = 'application/json';
        $request                 = $this->client->put($this->getUrl() . $url, $headers, $content);
        $this->response          = $request->send();

        return $this->response->getBody(true);
    }

    /**
     * {@inheritdoc}
     */
    public function post($url, array $headers = [], $content = '')
    {
        $headers['content-type'] = 'application/json';
        $request                 = $this->client->post($this->getUrl() . $url, $headers, $content);
        $this->response          = $request->send();

        return $this->response->getBody(true);
    }

    /**
     * @param Event $event
     *
     * @throws \RuntimeException|ExceptionInterface
     */
    protected function handleResponse(Event $event)
    {
        $this->response = $event['response'];

        if ($this->response->isSuccessful()) {
            return;
        }

        $body = $this->response->getBody(true);
        $code = $this->response->getStatusCode();

        if ($this->exception) {
            throw $this->exception->create($body, $code);
        }

        /** @var \StdClass $content */
        $content = json_decode($body);

        throw new \RuntimeException(
            sprintf('[%d]: %s (%s. %s)', $content->code, $content->message, $content->description, $content->moreInfo),
            $code
        );
    }

    /**
     * Performs login request and returns auth result data
     *
     * @return mixed
     */
    protected function login()
    {
        return $this->accessToken;
    }

    /**
     * @return string
     */
    public function getAccessToken()
    {
        return $this->accessToken;
    }

    /**
     * @inheritdoc
     */
    public function getLatestResponseHeaders()
    {
        if (null === $this->response) {
            return null;
        }

        return [
            'x-tracing-id' => (string)$this->response->getHeader('x-tracing-id'),
        ];
    }
}