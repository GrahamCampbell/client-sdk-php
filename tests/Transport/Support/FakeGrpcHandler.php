<?php

declare(strict_types=1);

namespace Momento\Tests\Transport\Support;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use LogicException;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * Stands in for CurlMultiHandler below the transport while honoring the
 * published Guzzle handler contract.
 */
class FakeGrpcHandler
{
    /** @var array<int, array{request: RequestInterface, options: array}> */
    public array $invocations = [];

    /** @var array<int, callable> */
    private array $responders = [];

    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $this->invocations[] = ["request" => $request, "options" => $options];
        if (!$this->responders) {
            throw new LogicException("FakeGrpcHandler has no responder enqueued");
        }
        $responder = array_shift($this->responders);

        return $responder($request, $options);
    }

    /**
     * Enqueue a successful transfer.
     *
     * @param Response $response the response to fulfill with
     * @param array<string, string[]> $trailers the parsed trailer array
     * @return void
     */
    public function respond(Response $response, array $trailers = []): void
    {
        $this->responders[] = static function (RequestInterface $request, array $options) use ($response, $trailers): PromiseInterface {
            $response->getBody()->rewind();
            if (isset($options["on_stats"])) {
                ($options["on_stats"])(new TransferStats($request, $response, 0.01, null, []));
            }
            if (isset($options["on_trailers"])) {
                ($options["on_trailers"])($trailers, $response, $request);
            }

            return new FulfilledPromise($response);
        };
    }

    /**
     * Enqueue a failed transfer.
     *
     * @param callable $reasonFactory fn(RequestInterface): Throwable
     * @param mixed $handlerErrorData errno for the on_stats capture, or null
     * @return void
     */
    public function fail(callable $reasonFactory, $handlerErrorData = null): void
    {
        $this->responders[] = static function (RequestInterface $request, array $options) use ($reasonFactory, $handlerErrorData): PromiseInterface {
            if (isset($options["on_stats"])) {
                ($options["on_stats"])(new TransferStats($request, null, 0.01, $handlerErrorData, []));
            }

            return new RejectedPromise($reasonFactory($request));
        };
    }

    /**
     * Enqueue a transfer that only cancellation can settle.
     *
     * @return void
     */
    public function respondPending(): void
    {
        $this->responders[] = static function (RequestInterface $request, array $options): PromiseInterface {
            return new Promise();
        };
    }

    public function lastRequest(): RequestInterface
    {
        return $this->invocations[count($this->invocations) - 1]["request"];
    }

    /**
     * @return array
     */
    public function lastOptions(): array
    {
        return $this->invocations[count($this->invocations) - 1]["options"];
    }
}
