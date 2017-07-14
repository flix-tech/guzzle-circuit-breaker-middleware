# Guzzle 6 Circuit-Breaker Middleware

This is a Guzzle 6 middleware that integrates https://github.com/ejsmont-artur/php-circuit-breaker.

### Contents

- [Requirements](#requirements)
  - [Hard Dependencies](#dependencies)
- [Installation](#installation)
- [Usage](#usage)
  - [Example](#example)
- [Testing](#testing)
  - [Unit tests, Coding standards and static analysis](#unit-tests-coding-standards-and-static-analysis)
- [Contributing](#contributing)

## Requirements

### Dependencies

| Dependency | Version | Reason |
|:--- |:---:|:--- |
| **`php`** | ~7.0 | Anything lower has (almost) reached EOL |
| **`guzzlephp/promises`** | ~6.2 | Middlewares pass Promises around |
| **`psr/http-message`** | ~1.0 | Standardization, doh |
| **`ejsmont-artur/php-circuit-breaker`** | ~0.1 | Simple and robust Circuit Breaker implementation for PHP |

## Installation

This library is installed via [`composer`](http://getcomposer.org).

```bash
composer require "flix-tech/guzzle-circuit-breaker-middleware=~1.0"
```

## Usage

> **NOTE**
> It is recommended that this middleware is on top of the stack so that if a service is unavailable, it can instantly
> reject without going further down the chain.

With the default configuration, this middleware will inspect the PSR-7 requests for either a transport option key
`"circuit_breaker.requested_service_name"` exposed via `Middleware::CB_TRANSFER_OPTION_KEY` in the request options
array, or a request header `"X-CB-Service-Name"` exposed via `Middleware::CB_SERVICE_NAME_HEADER`. You can pass an own
service name extractor callable that takes the form `f(RequestInterface $request, array $requestOptions): string`.

The service name will be used to look up the
availability in the circuit breaker. If it is not available, it will be instantly rejected with a
[`CircuitBreakerIsClosedException`](src/Exception/CircuitIsClosedException.php).

If the request was successful, the middleware will report a success to the circuit breaker for the given service.
Otherwise it will report a failure and pass the rejection further down the chain.

You can pass a custom exception map into the middleware with which you can control which exception types and values
should actually trigger a failure report. I.e. a 404 might be a failed configuration issue and might not trigger failure
reports. The exception map callback takes the form `f($rejectedValue): bool`.

## Example

```php
<?php

use Ejsmont\CircuitBreaker\Factory;
use FlixTech\CircuitBreakerMiddleware\Middleware;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\CurlHandler;
use Psr\Http\Message\RequestInterface;

$circuitBreaker = Factory::getSingleApcInstance();

$serviceNameExtractor = function (RequestInterface $request, array $options) {
    if (\array_key_exists('my_custom_option_key', $options)) {
        return $options['my_custom_option_key'];
    }
    
    return null;
};

$exceptionMap = function ($rejectedValue) {
    if ($rejectedValue instanceof RequestException && $rejectedValue->getResponse()) {
        return 404 !== $rejectedValue->getResponse()->getStatusCode();
    }
    
    return true;
};

$middleware = new Middleware(
    $circuitBreaker,
    $serviceNameExtractor,
    $exceptionMap
);

$handlerStack = HandlerStack::create(new CurlHandler());
$handlerStack->push($middleware);

$client = new Client(['handler' => $handlerStack]);
```

## Testing

To run the tests, just run `./vendor/bin/phpunit` from the root of the project after installing the dev requirements.

## Contributing

In order to contribute to this library, follow this workflow:

- Fork the repository
- Create a feature branch
- Work on the feature
- Run tests to verify that the tests are passing
- Open a PR to the upstream `master` branch
- See any Travis CI messages popping up on your PR
- Be happy about contributing to open source!
