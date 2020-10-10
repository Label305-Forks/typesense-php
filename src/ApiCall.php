<?php

namespace Typesence;

use \Typesence\Lib\Node;
use \Typesence\Lib\Configuration;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use \Typesence\Exceptions\ServerError;
use \Typesence\Exceptions\ObjectNotFound;
use \Typesence\Exceptions\RequestMalformed;
use \Typesence\Exceptions\HTTPStatus0Error;
use \Typesence\Exceptions\ServiceUnavailable;
use \Typesence\Exceptions\RequestUnauthorized;
use \Typesence\Exceptions\ObjectAlreadyExists;
use \Typesence\Exceptions\ObjectUnprocessable;
use \Typesence\Exceptions\TypesenseClientError;

/**
 * Class ApiCall
 *
 * @package \Typesence
 * @date    4/5/20
 * @author  Abdullah Al-Faqeir <abdullah@devloops.net>
 */
class ApiCall
{

    private const API_KEY_HEADER_NAME = 'X-TYPESENSE-API-KEY';

    /**
     * @var \GuzzleHttp\Client
     */
    private $client;

    /**
     * @var \Typesence\Lib\Configuration
     */
    private $config;

    /**
     * @var array|\Typesence\Lib\Node[]
     */
    private static $nodes;

    /**
     * @var \Typesence\Lib\Node
     */
    private static $nearestNode;

    /**
     * @var int
     */
    private $nodeIndex;

    /**
     * ApiCall constructor.
     *
     * @param  \Typesence\Lib\Configuration  $config
     */
    public function __construct(Configuration $config)
    {
        $this->config      = $config;
        $this->client      = new \GuzzleHttp\Client();
        self::$nodes       = $this->config->getNodes();
        self::$nearestNode = $this->config->getNearestNode();
        $this->nodeIndex   = 0;
        $this->initializeNodes();
    }

    /**
     *  Initialize Nodes
     */
    private function initializeNodes(): void
    {
        if (self::$nearestNode !== null) {
            $this->setNodeHealthCheck(self::$nearestNode, true);
        }

        foreach (self::$nodes as &$node) {
            $this->setNodeHealthCheck($node, true);
        }
    }

    /**
     * @param  string  $endPoint
     * @param  array  $params
     * @param  bool  $asJson
     *
     * @return string|array
     * @throws \Typesence\Exceptions\TypesenseClientError
     * @throws \Exception|\GuzzleHttp\Exception\GuzzleException
     */
    public function get(string $endPoint, array $params, bool $asJson = true)
    {
        return $this->makeRequest('get', $endPoint, $asJson, [
          'data' => $params ?? [],
        ]);
    }

    /**
     * @param  string  $endPoint
     * @param  mixed  $body
     *
     * @param  bool  $asJson
     *
     * @return array|string
     * @throws \Typesence\Exceptions\TypesenseClientError
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function post(string $endPoint, $body, bool $asJson = true): array
    {
        return $this->makeRequest('post', $endPoint, $asJson, [
          'data' => $body ?? [],
        ]);
    }

    /**
     * @param  string  $endPoint
     * @param  array  $body
     *
     * @return array
     * @throws \Typesence\Exceptions\TypesenseClientError|\GuzzleHttp\Exception\GuzzleException
     */
    public function put(string $endPoint, array $body): array
    {
        return $this->makeRequest('put', $endPoint, true, [
          'data' => $body ?? [],
        ]);
    }

    /**
     * @param  string  $endPoint
     *
     * @return array
     * @throws \Typesence\Exceptions\TypesenseClientError|\GuzzleHttp\Exception\GuzzleException
     */
    public function delete(string $endPoint): array
    {
        return $this->makeRequest('delete', $endPoint, true, []);
    }

    /**
     * Makes the actual http request, along with retries
     *
     * @param  string  $method
     * @param  string  $endPoint
     * @param  bool  $asJson
     * @param  array  $options
     *
     * @return string|array
     * @throws \Typesence\Exceptions\TypesenseClientError|\GuzzleHttp\Exception\GuzzleException
     * @throws \Exception
     */
    private function makeRequest(string $method, string $endPoint, bool $asJson, array $options)
    {
        $numRetries     = 0;
        $last_exception = null;
        while ($numRetries < $this->config->getNumRetries() + 1) {
            $numRetries++;
            $node = $this->getNode();

            try {
                $url   = $node->url().$endPoint;
                $reqOp = $this->getRequestOptions();
                if (isset($options['data'])) {
                    if ($method === 'get') {
                        $reqOp['query'] = http_build_query($options['data']);
                    } elseif (is_string($options['data'])) {
                        $reqOp['body'] = $options['data'];
                    } else {
                        $reqOp['json'] = $options['data'];
                    }
                }

                $response = $this->client->request($method, $url, $reqOp);

                $statusCode = $response->getStatusCode();
                if (0 < $statusCode && $statusCode < 500) {
                    $this->setNodeHealthCheck($node, true);
                }

                if (!(200 <= $statusCode && $statusCode < 300)) {
                    $errorMessage = json_decode($response->getBody()
                                                         ->getContents(), true)['message'] ?? 'API error.';
                    throw $this->getException($statusCode)
                               ->setMessage($errorMessage);
                }

                return $asJson ? json_decode($response->getBody()
                                                      ->getContents(), true) : $response->getBody()
                                                                                        ->getContents();
            } catch (ClientException $exception) {
                if ($exception->getResponse()
                              ->getStatusCode() === 408) {
                    continue;
                }
                $this->setNodeHealthCheck($node, false);
                throw $this->getException($exception->getResponse()
                                                    ->getStatusCode())
                           ->setMessage($exception->getMessage());
            } catch (RequestException $exception) {
                $this->setNodeHealthCheck($node, false);
                throw $this->getException($exception->getResponse()
                                                    ->getStatusCode())
                           ->setMessage($exception->getMessage());
            } catch (TypesenseClientError $exception) {
                $this->setNodeHealthCheck($node, false);
                throw $exception;
            } catch (\Exception $exception) {
                $this->setNodeHealthCheck($node, false);
                $last_exception = $exception;
                sleep($this->config->getRetryIntervalSeconds());
            }
        }

        if ($last_exception) {
            throw $last_exception;
        }
    }

    /**
     * @return array
     */
    private function getRequestOptions(): array
    {
        return [
          'headers'         => [
            self::API_KEY_HEADER_NAME => $this->config->getApiKey(),
          ],
          'connect_timeout' => $this->config->getConnectionTimeoutSeconds(),
        ];
    }

    /**
     * @param  \Typesence\Lib\Node  $node
     *
     * @return bool
     */
    private function nodeDueForHealthCheck(Node $node): bool
    {
        $currentTimestamp = time();
        $checkNode        = ($currentTimestamp - $node->getLastAccessTs()) > $this->config->getHealthCheckIntervalSeconds();
        if ($checkNode) {
            //
        }

        return $checkNode;
    }

    /**
     * @param  \Typesence\Lib\Node  $node
     * @param  bool  $isHealthy
     */
    public function setNodeHealthCheck(Node $node, bool $isHealthy): void
    {
        $node->setHealthy($isHealthy);
        $node->setLastAccessTs(time());
    }

    /**
     * Returns a healthy host from the pool in a round-robin fashion
     * Might return an unhealthy host periodically to check for recovery.
     *
     * @return \Typesence\Lib\Node
     */
    public function getNode(): Lib\Node
    {
        if (self::$nearestNode !== null) {
            if (self::$nearestNode->isHealthy() || $this->nodeDueForHealthCheck(self::$nearestNode)) {
                return self::$nearestNode;
            }
        }
        $i = 0;
        while ($i < count(self::$nodes)) {
            $i++;
            $node            = self::$nodes[$this->nodeIndex];
            $this->nodeIndex = ($this->nodeIndex + 1) % count(self::$nodes);
            if ($node->isHealthy() || $this->nodeDueForHealthCheck($node)) {
                return $node;
            }
        }

        /**
         * None of the nodes are marked healthy, but some of them could have become healthy since last health check.
         * So we will just return the next node.
         */
        return self::$nodes[$this->nodeIndex];
    }

    /**
     * @param  int  $httpCode
     *
     * @return \Typesence\Exceptions\TypesenseClientError
     */
    public function getException(int $httpCode): TypesenseClientError
    {
        switch ($httpCode) {
            case 0:
                return new HTTPStatus0Error();
            case 400:
                return new RequestMalformed();
            case 401:
                return new RequestUnauthorized();
            case 404:
                return new ObjectNotFound();
            case 409:
                return new ObjectAlreadyExists();
            case 422:
                return new ObjectUnprocessable();
            case 500:
                return new ServerError();
            case 503:
                return new ServiceUnavailable();
            default:
                return new TypesenseClientError();
        }
    }

}