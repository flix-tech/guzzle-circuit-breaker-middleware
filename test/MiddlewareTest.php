<?php

declare(strict_types=1);

namespace FlixTech\CircuitBreakerMiddleware\Test;

use Ejsmont\CircuitBreaker\CircuitBreakerInterface;
use FlixTech\CircuitBreakerMiddleware\Exception\CircuitIsClosedException;
use FlixTech\CircuitBreakerMiddleware\Middleware;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class MiddlewareTest extends TestCase
{
    /**
     * @var RequestInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    private $requestMock;

    /**
     * @var CircuitBreakerInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    private $cbMock;

    protected function setUp()
    {
        $this->requestMock = $this->getMockForAbstractClass(RequestInterface::class);
        $this->cbMock = $this->getMockForAbstractClass(CircuitBreakerInterface::class);
    }

    /**
     * @test
     */
    public function it_will_extract_service_name_from_header()
    {
        $this->requestMock
            ->expects($this->once())
            ->method('getHeader')
            ->with(Middleware::CB_SERVICE_NAME_HEADER)
            ->willReturn(['service']);

        $this->cbMock
            ->expects($this->once())
            ->method('isAvailable')
            ->with('service');

        (new Middleware($this->cbMock))(
            function () {
                return new FulfilledPromise(true);
            }
        )($this->requestMock, []);
    }

    /**
     * @test
     */
    public function it_will_extract_service_name_from_request_options()
    {
        $this->requestMock
            ->expects($this->never())
            ->method('getHeader');

        $requestOptions = [Middleware::CB_TRANSFER_OPTION_KEY => 'service'];

        $this->cbMock
            ->expects($this->once())
            ->method('isAvailable')
            ->with('service');

        (new Middleware($this->cbMock))(
            function () {
                return new FulfilledPromise(true);
            }
        )($this->requestMock, $requestOptions);
    }

    /**
     * @test
     */
    public function it_will_use_custom_service_name_extractor()
    {
        $this->requestMock
            ->expects($this->never())
            ->method('getHeader');

        $requestOptions = ['custom_key' => 'service'];

        $this->cbMock
            ->expects($this->once())
            ->method('isAvailable')
            ->with('service');

        (new Middleware(
            $this->cbMock,
            function (RequestInterface $request, array $requestOptions) {
                return $requestOptions['custom_key'];
            }
        ))(
            function () {
                return new FulfilledPromise(true);
            }
        )($this->requestMock, $requestOptions);
    }

    /**
     * @test
     */
    public function it_will_pass_through_to_handler_when_no_service_name_can_be_extracted()
    {
        $this->requestMock
            ->expects($this->once())
            ->method('getHeader')
            ->with(Middleware::CB_SERVICE_NAME_HEADER)
            ->willReturn([]);

        $this->cbMock
            ->expects($this->never())
            ->method('isAvailable');

        $promise = new FulfilledPromise(true);

        $expectedPromise = (new Middleware($this->cbMock))(
            function () use ($promise) {
                return $promise;
            }
        )($this->requestMock, []);

        $this->assertEquals($expectedPromise, $promise);
    }

    /**
     * @test
     */
    public function it_will_instantly_reject_when_service_is_not_available()
    {
        $requestOptions = [Middleware::CB_TRANSFER_OPTION_KEY => 'service'];

        $this->cbMock
            ->expects($this->once())
            ->method('isAvailable')
            ->with('service')
            ->willReturn(false);

        $expectedPromise = new RejectedPromise(
            new CircuitIsClosedException('Circuit for service "service" is closed')
        );

        $promise = (new Middleware($this->cbMock))(
            function () {}
        )($this->requestMock, $requestOptions);

        $this->assertEquals($expectedPromise, $promise);
    }

    /**
     * @test
     */
    public function it_will_report_success_for_fulfilled_promise()
    {
        $requestOptions = [Middleware::CB_TRANSFER_OPTION_KEY => 'service'];

        $this->cbMock
            ->expects($this->once())
            ->method('isAvailable')
            ->with('service')
            ->willReturn(true);

        $this->cbMock
            ->expects($this->never())
            ->method('reportFailure');

        $this->cbMock
            ->expects($this->once())
            ->method('reportSuccess');

        /** @var \GuzzleHttp\Promise\PromiseInterface $promise */
        $promise = (new Middleware($this->cbMock))(
            function () {
                return new FulfilledPromise(true);
            }
        )($this->requestMock, $requestOptions);

        $promise->wait();
    }

    /**
     * @test
     * @expectedException \FlixTech\CircuitBreakerMiddleware\Exception\CircuitIsClosedException
     */
    public function it_will_report_failure_for_rejected_promise()
    {
        $requestOptions = [Middleware::CB_TRANSFER_OPTION_KEY => 'service'];

        $this->cbMock
            ->expects($this->once())
            ->method('isAvailable')
            ->with('service')
            ->willReturn(true);

        $this->cbMock
            ->expects($this->once())
            ->method('reportFailure');

        $this->cbMock
            ->expects($this->never())
            ->method('reportSuccess');

        /** @var \GuzzleHttp\Promise\PromiseInterface $promise */
        $promise = (new Middleware($this->cbMock))(
            function () {
                return new RejectedPromise(
                    new CircuitIsClosedException('Circuit for service "service" is closed')
                );
            }
        )($this->requestMock, $requestOptions);

        $promise->wait();
    }

    /**
     * @test
     * @expectedException \FlixTech\CircuitBreakerMiddleware\Exception\CircuitIsClosedException
     */
    public function it_applies_custom_exception_map()
    {
        $requestOptions = [Middleware::CB_TRANSFER_OPTION_KEY => 'service'];

        $this->cbMock
            ->expects($this->once())
            ->method('isAvailable')
            ->with('service')
            ->willReturn(true);

        $this->cbMock
            ->expects($this->never())
            ->method('reportFailure');

        $this->cbMock
            ->expects($this->never())
            ->method('reportSuccess');

        /** @var \GuzzleHttp\Promise\PromiseInterface $promise */
        $promise = (new Middleware(
            $this->cbMock,
            null,
            function ($reason) {
                return !$reason instanceof CircuitIsClosedException;
            })
        )(
            function () {
                return new RejectedPromise(
                    new CircuitIsClosedException('Circuit for service "service" is closed')
                );
            }
        )($this->requestMock, $requestOptions);

        $promise->wait();
    }

    /**
     * @test
     */
    public function it_should_integrate_with_other_handlers_in_the_stack()
    {
        $request = new Request('GET', '/');

        $responses = [
            new Response(),
            new Response(),
            new RequestException(
                'Not found.',
                $request,
                new Response(404)
            ),
            new RequestException(
                'Internal server error',
                $request,
                new Response(500)
            ),
            new Response()
        ];

        $mockHandler = new MockHandler($responses);

        $callbackMiddleware = function ($handler) {
            return function (RequestInterface $request, array $requestOptions) use ($handler) {
                return $handler($request, $requestOptions);
            };
        };

        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push($callbackMiddleware);
        $handlerStack->push(new Middleware($this->cbMock), 'circuit_breaker_middleware');

        $this->cbMock
            ->expects($this->exactly(4))
            ->method('isAvailable')
            ->with('service')
            ->willReturnOnConsecutiveCalls(true, true, true, false);

        $this->cbMock
            ->expects($this->once())
            ->method('reportSuccess');

        $this->cbMock
            ->expects($this->exactly(2))
            ->method('reportFailure');

        $client = new Client(['handler' => $handlerStack]);

        $client->send($request);
        $client->send($request, [Middleware::CB_TRANSFER_OPTION_KEY => 'service']);
        try {
            $client->send($request, [Middleware::CB_TRANSFER_OPTION_KEY => 'service']);
        } catch (\Exception $e) {}

        try {
            $client->send($request, [Middleware::CB_TRANSFER_OPTION_KEY => 'service']);
        } catch (\Exception $e) {}

        $this->expectException(CircuitIsClosedException::class);
        $client->send($request, [Middleware::CB_TRANSFER_OPTION_KEY => 'service']);
    }
}
