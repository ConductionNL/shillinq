<?php

/**
 * Unit tests for PipelinqContactAdapter's HTTP transport.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Pipelinq
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Pipelinq;

use OCA\Shillinq\Service\Pipelinq\CustomerBridgeMetricsService;
use OCA\Shillinq\Service\Pipelinq\PipelinqContactAdapter;
use OCA\Shillinq\Service\Pipelinq\PipelinqTransportException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The other adapter tests replace dispatch(); these drive the real one
 * through Nextcloud's HTTP client double. They pin the request built from
 * config (endpoint joined to the path, bearer token, JSON body), the verb
 * routing, the response decoding, the retry schedule handed to the sleeper,
 * the refusals when config is missing, and the default circuit breaker
 * reporting its transition.
 *
 * @covers \OCA\Shillinq\Service\Pipelinq\PipelinqContactAdapter
 * @uses \OCA\Shillinq\Service\Pipelinq\CircuitBreaker
 * @uses \OCA\Shillinq\Service\Pipelinq\RetryPolicy
 * @uses \OCA\Shillinq\Service\Pipelinq\PipelinqRequestRun
 * @uses \OCA\Shillinq\Service\Pipelinq\PipelinqTransportException
 * @uses \OCA\Shillinq\Service\Pipelinq\CustomerBridgeMetricsService
 */
final class PipelinqContactAdapterTransportTest extends TestCase {

	/**
	 * Requests made, as [verb, url, options].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $requests = [];

	/**
	 * Seconds the sleeper was asked to wait.
	 *
	 * @var array<int, integer>
	 */
	private array $slept = [];

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A response with this status and body.
	 *
	 * @param integer $status The status.
	 * @param string  $body   The body.
	 *
	 * @return IResponse The response.
	 */
	private function response(int $status, string $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);

		return $response;
	}//end response()

	/**
	 * The adapter, with request() exposed, over a client answering these responses in turn.
	 *
	 * @param array<int, IResponse|\Throwable>       $responses The responses.
	 * @param string                                 $endpoint  The configured endpoint.
	 * @param string                                 $token     The configured token.
	 * @param CustomerBridgeMetricsService|null      $metrics   The metrics.
	 *
	 * @return PipelinqContactAdapter The adapter.
	 */
	private function adapter(array $responses, string $endpoint = 'https://crm.example.test/api/', string $token = 'tok-1', ?CustomerBridgeMetricsService $metrics = null): PipelinqContactAdapter {
		$client = $this->createMock(IClient::class);
		foreach (['get', 'post', 'put', 'delete'] as $verb) {
			$client->method($verb)->willReturnCallback(
				function (string $url, array $options) use ($verb, &$responses): IResponse {
					$this->requests[] = [$verb, $url, $options];
					$next = array_shift($responses);
					if ($next instanceof \Throwable) {
						throw $next;
					}

					return $next;
				}
			);
		}

		$clients = $this->createMock(IClientService::class);
		$clients->expects($this->atMost(1))->method('newClient')->willReturn($client);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => match ($key) {
				PipelinqContactAdapter::CONFIG_KEY_ENDPOINT => $endpoint,
				PipelinqContactAdapter::CONFIG_KEY_TOKEN => $token,
				default => '',
			}
		);

		return new class (
			$clients,
			$config,
			$this->logger,
			$this->createMock(ICache::class),
			sleeper: function (int $seconds): void {
				$this->slept[] = $seconds;
			},
			metrics: $metrics
		) extends PipelinqContactAdapter {

			/**
			 * @param string                    $method  The verb.
			 * @param string                    $path    The path.
			 * @param array<string, mixed>|null $payload The body.
			 *
			 * @return array<string, mixed> The decoded body.
			 */
			public function call(string $method, string $path, ?array $payload = null): array {
				return $this->request($method, $path, $payload);
			}
		};
	}//end adapter()

	/**
	 * A GET joins the path to the endpoint and carries the bearer token.
	 *
	 * @return void
	 */
	public function testAGetCarriesTheTokenAndDecodesTheBody(): void {
		$body = $this->adapter([$this->response(200, '{"id":"c-1"}')])->call('get', 'contacts/c-1');

		$this->assertSame(['id' => 'c-1'], $body);
		$this->assertSame('get', $this->requests[0][0]);
		$this->assertSame('https://crm.example.test/api/contacts/c-1', $this->requests[0][1]);
		$this->assertSame(
			[
				'timeout' => 3,
				'connect_timeout' => 3,
				'headers' => ['Authorization' => 'Bearer tok-1', 'Accept' => 'application/json', 'User-Agent' => 'shillinq/pipelinq-adapter'],
			],
			$this->requests[0][2]
		);
	}//end testAGetCarriesTheTokenAndDecodesTheBody()

	/**
	 * A payload is sent as a JSON body, on the verb asked for, and an empty body decodes to nothing.
	 *
	 * @return void
	 */
	public function testAPayloadIsSentAsJsonOnEachVerb(): void {
		$adapter = $this->adapter([$this->response(201, ''), $this->response(200, '{}'), $this->response(204, '')]);

		$this->assertSame([], $adapter->call('POST', '/timeline', ['url' => 'https://a/b']));
		$adapter->call('PUT', '/timeline/1', ['n' => 1]);
		$adapter->call('DELETE', '');

		$this->assertSame(['post', 'put', 'delete'], array_column($this->requests, 0));
		$this->assertSame('https://crm.example.test/api/timeline', $this->requests[0][1]);
		$this->assertSame('https://crm.example.test/api', $this->requests[2][1]);
		$this->assertSame('{"url":"https://a/b"}', $this->requests[0][2]['body']);
		$this->assertSame('application/json', $this->requests[0][2]['headers']['Content-Type']);
		$this->assertArrayNotHasKey('body', $this->requests[2][2]);
	}//end testAPayloadIsSentAsJsonOnEachVerb()

	/**
	 * An unsupported verb is refused without a call.
	 *
	 * @return void
	 */
	public function testAnUnsupportedVerbIsRefused(): void {
		$this->expectException(PipelinqTransportException::class);
		$this->expectExceptionMessage('unsupported HTTP method PATCH');

		$this->adapter([])->call('patch', '/x');
	}//end testAnUnsupportedVerbIsRefused()

	/**
	 * A body that is not JSON, or not an object, is refused.
	 *
	 * @return void
	 */
	public function testABodyThatIsNotAJsonObjectIsRefused(): void {
		foreach (['<html>' => 'pipelinq response is not valid JSON', '"text"' => 'pipelinq response root must be a JSON object'] as $body => $message) {
			try {
				$this->adapter([$this->response(200, $body)])->call('GET', '/x');
				$this->fail('expected a refusal for ' . $body);
			} catch (PipelinqTransportException $e) {
				$this->assertSame($message, $e->getMessage());
				$this->assertSame(200, $e->statusCode());
			}
		}
	}//end testABodyThatIsNotAJsonObjectIsRefused()

	/**
	 * Without an endpoint or a token nothing is sent.
	 *
	 * @return void
	 */
	public function testMissingConfigurationIsRefused(): void {
		foreach ([['', 'tok', 'pipelinq endpoint is not configured'], ['https://crm.example.test', ' ', 'pipelinq token is not configured']] as [$endpoint, $token, $message]) {
			try {
				$this->adapter([], $endpoint, $token)->call('GET', '/x');
				$this->fail('expected: ' . $message);
			} catch (PipelinqTransportException $e) {
				$this->assertSame($message, $e->getMessage());
			}
		}

		$this->assertSame([], $this->requests);
	}//end testMissingConfigurationIsRefused()

	/**
	 * Transient failures are retried on the backoff schedule until one succeeds.
	 *
	 * @return void
	 */
	public function testTransientFailuresAreRetriedOnTheSchedule(): void {
		$body = $this->adapter(
			[new RuntimeException('connection reset'), $this->response(503, ''), $this->response(200, '{"ok":true}')]
		)->call('GET', '/x');

		$this->assertSame(['ok' => true], $body);
		$this->assertCount(3, $this->requests);
		$this->assertSame([1, 2], $this->slept);
	}//end testTransientFailuresAreRetriedOnTheSchedule()

	/**
	 * The default breaker opens after five failures and says so.
	 *
	 * @return void
	 */
	public function testTheDefaultBreakerReportsItsTransition(): void {
		$states = [];
		$cache  = $this->createMock(ICache::class);
		$cache->method('set')->willReturnCallback(
			static function (string $key, mixed $value) use (&$states): bool {
				$states[] = [$key, $value];

				return true;
			}
		);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createLocal')->willReturn($cache);
		$transitions = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message, array $context) use (&$transitions): void {
				if ($message === 'pipelinq circuit breaker transitioned') {
					$transitions[] = $context;
				}
			}
		);
		$adapter = $this->adapter(array_fill(0, 5, $this->response(400, '')), metrics: new CustomerBridgeMetricsService($factory));

		for ($i = 0; $i < 5; $i++) {
			try {
				$adapter->call('GET', '/x');
			} catch (PipelinqTransportException $e) {
				$this->assertSame(400, $e->statusCode());
			}
		}

		$this->assertSame([['app' => 'shillinq', 'from' => 'closed', 'to' => 'open', 'reason' => '5 consecutive failures']], $transitions);
		$this->assertSame('open', $adapter->circuitState());
		$this->assertContains('open', array_column($states, 1));
	}//end testTheDefaultBreakerReportsItsTransition()
}//end class
