<?php

declare(strict_types=1);

namespace FlixTech\CircuitBreakerMiddleware;

use Ejsmont\CircuitBreaker\CircuitBreakerInterface;
use FlixTech\CircuitBreakerMiddleware\Exception\CircuitIsClosedException;
use Psr\Http\Message\RequestInterface;

class Middleware
{
    const CB_SERVICE_NAME_HEADER = 'X-CB-Service-Name';
    const CB_TRANSFER_OPTION_KEY = 'circuit_breaker.requested_service_name';

    /**
     * @var CircuitBreakerInterface
     */
    private $circuitBreaker;

    /**
     * @var callable
     */
    private $exceptionMap;

    public function __construct(CircuitBreakerInterface $circuitBreaker, callable $exceptionMap = null)
    {
        $this->circuitBreaker = $circuitBreaker;
        $this->exceptionMap = $exceptionMap;

        if (!$exceptionMap) {
            $this->exceptionMap = function (): bool {
                return true;
            };
        }
    }

    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $requestOptions) use ($handler) {
            $serviceName = $this->extractServiceName($request, $requestOptions);

            if (!$serviceName) {
                return $handler($request, $requestOptions);
            }

            if (!$this->circuitBreaker->isAvailable($serviceName)) {
                return \GuzzleHttp\Promise\rejection_for(
                    new CircuitIsClosedException(
                        sprintf('Circuit for service "%s" is closed', $serviceName)
                    )
                );
            }

            /** @var \GuzzleHttp\Promise\PromiseInterface $promise */
            $promise = $handler($request, $requestOptions);

            return $promise->then(
                function ($value) use ($serviceName) {
                    $this->circuitBreaker->reportSuccess($serviceName);

                    return \GuzzleHttp\Promise\promise_for($value);
                },
                function ($reason) use ($serviceName) {
                    if (call_user_func($this->exceptionMap, $reason)) {
                        $this->circuitBreaker->reportFailure($serviceName);
                    }

                    return \GuzzleHttp\Promise\rejection_for($reason);
                }
            );
        };
    }

    private function extractServiceName(RequestInterface $request, array $requestOptions): string
    {
        $serviceName = '';

        if (array_key_exists(self::CB_TRANSFER_OPTION_KEY, $requestOptions)) {
            $serviceName = $requestOptions[self::CB_TRANSFER_OPTION_KEY];
        }

        if (!$serviceName) {
            $header = $request->getHeader(self::CB_SERVICE_NAME_HEADER);

            if (0 !== count($header)) {
                $serviceName = $header[0];
            }
        }

        return $serviceName;
    }
}
