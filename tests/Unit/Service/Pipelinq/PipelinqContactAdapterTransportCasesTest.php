<?php

/**
 * Unit tests for the PipelinqContactAdapter's real HTTP transport.
 *
 * Drives getContact() through the un-overridden dispatch path with only the
 * Nextcloud HTTP client mocked: endpoint and token resolution, request
 * options, the verb dispatch, body decoding, and the contact cache.
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
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-02-http-adapter-core/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Pipelinq;

use OCA\Shillinq\Service\Pipelinq\CircuitBreaker;
use OCA\Shillinq\Service\Pipelinq\PipelinqContact;
use OCA\Shillinq\Service\Pipelinq\PipelinqContactAdapter;
use OCA\Shillinq\Service\Pipelinq\PipelinqRequestRun;
use OCA\Shillinq\Service\Pipelinq\PipelinqTransportException;
use OCA\Shillinq\Service\Pipelinq\RetryPolicy;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Verifies the adapter's transport against a mocked HTTP client.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-02-http-adapter-core/tasks.md
 */
#[CoversClass(PipelinqContactAdapter::class)]
#[UsesClass(CircuitBreaker::class)]
#[UsesClass(PipelinqContact::class)]
#[UsesClass(PipelinqRequestRun::class)]
#[UsesClass(PipelinqTransportException::class)]
#[UsesClass(RetryPolicy::class)]
final class PipelinqContactAdapterTransportCasesTest extends TestCase {

	/**
	 * The in-memory cache contents.
	 *
	 * @var array<string, mixed>
	 */
	private array $cacheStore = [];

	/**
	 * Build an in-memory cache.
	 *
	 * @return ICache
	 */
	private function cache(): ICache {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key): mixed => ($this->cacheStore[$key] ?? null));
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value): bool {
				$this->cacheStore[$key] = $value;
				return true;
			}
		);
		return $cache;

	}//end cache()

	/**
	 * Build an app config answering the endpoint and token.
	 *
	 * @param string $endpoint The configured endpoint.
	 * @param string $token The configured token.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(string $endpoint, string $token): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($endpoint, $token): string {
				return match ($key) {
					PipelinqContactAdapter::CONFIG_KEY_ENDPOINT => $endpoint,
					PipelinqContactAdapter::CONFIG_KEY_TOKEN => $token,
					default => $default,
				};
			}
		);
		return $config;

	}//end appConfig()

	/**
	 * Build a response.
	 *
	 * @param int $status The status code.
	 * @param string $body The raw body.
	 *
	 * @return IResponse
	 */
	private function response(int $status, string $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);
		return $response;

	}//end response()

	/**
	 * Build the adapter over a client service that hands out $client.
	 *
	 * @param IClient $client The HTTP client.
	 * @param string $endpoint The configured endpoint.
	 * @param string $token The configured token.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return PipelinqContactAdapter
	 */
	private function adapter(
		IClient $client,
		string $endpoint = 'https://crm.example/',
		string $token = ' secret-token ',
		?LoggerInterface $logger = null,
	): PipelinqContactAdapter {
		$clientService = $this->createMock(IClientService::class);
		$clientService->expects(self::atMost(1))->method('newClient')->willReturn($client);

		return new PipelinqContactAdapter(
			clientService: $clientService,
			appConfig: $this->appConfig(endpoint: $endpoint, token: $token),
			logger: ($logger ?? $this->createMock(LoggerInterface::class)),
			cache: $this->cache(),
			retryPolicy: new RetryPolicy(),
			breaker: new CircuitBreaker(failureThreshold: 5, cooldownSeconds: 300),
			sleeper: static function (int $seconds): void {
			},
		);

	}//end adapter()

	/**
	 * A fetched contact is read with a GET to the trimmed endpoint, the bearer
	 * token and JSON headers, then cached.
	 *
	 * @return void
	 */
	public function testGetContactSendsAnAuthenticatedGetAndCachesTheContact(): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())
			->method('get')
			->with(
				'https://crm.example/api/v1/contacts/c%2F1',
				[
					'timeout' => PipelinqContactAdapter::REQUEST_TIMEOUT_SECONDS,
					'connect_timeout' => PipelinqContactAdapter::REQUEST_TIMEOUT_SECONDS,
					'headers' => [
						'Authorization' => 'Bearer secret-token',
						'Accept' => 'application/json',
						'User-Agent' => 'shillinq/pipelinq-adapter',
					],
				]
			)
			->willReturn($this->response(status: 200, body: '{"legalName":" Acme BV ","email":"a@acme.example"}'));

		$adapter = $this->adapter(client: $client);
		$contact = $adapter->getContact(externalId: ' c/1 ');

		self::assertTrue($contact->isFound());
		self::assertSame('c/1', $contact->externalId);
		self::assertSame('Acme BV', $contact->legalName);
		self::assertSame('a@acme.example', $contact->email);

		// The second read is served from the cache: the client is asked once.
		$again = $adapter->getContact(externalId: 'c/1');
		self::assertSame('Acme BV', $again->legalName);
		self::assertArrayHasKey(PipelinqContactAdapter::CONTACT_CACHE_KEY_PREFIX . 'c/1', $this->cacheStore);

	}//end testGetContactSendsAnAuthenticatedGetAndCachesTheContact()

	/**
	 * An empty body is an empty, found contact.
	 *
	 * @return void
	 */
	public function testAnEmptyBodyIsAFoundContactWithNoFields(): void {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->response(status: 204, body: ''));

		$contact = $this->adapter(client: $client)->getContact(externalId: 'c2');

		self::assertTrue($contact->isFound());
		self::assertSame('', $contact->legalName);

	}//end testAnEmptyBodyIsAFoundContactWithNoFields()

	/**
	 * Invalid JSON falls back to a not-found contact and is not retried.
	 *
	 * @return void
	 */
	public function testInvalidJsonFallsBackToNotFoundWithoutRetry(): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())->method('get')->willReturn($this->response(status: 200, body: '{not json'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with('pipelinq contact response malformed', self::callback(
				static fn (array $context): bool => $context['status'] === 200 && $context['externalId'] === 'c3'
			));

		$contact = $this->adapter(client: $client, logger: $logger)->getContact(externalId: 'c3');

		self::assertFalse($contact->isFound());
		self::assertSame('c3', $contact->externalId);

	}//end testInvalidJsonFallsBackToNotFoundWithoutRetry()

	/**
	 * A JSON scalar root is refused as a transport error.
	 *
	 * @return void
	 */
	public function testAScalarJsonRootIsRefused(): void {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->response(status: 200, body: '"just a string"'));

		$this->expectException(PipelinqTransportException::class);
		$this->expectExceptionMessage('pipelinq response root must be a JSON object');

		$this->adapter(client: $client)->getContact(externalId: 'c4');

	}//end testAScalarJsonRootIsRefused()

	/**
	 * A missing endpoint fails before any request is made.
	 *
	 * @return void
	 */
	public function testAMissingEndpointFailsWithoutARequest(): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::never())->method('get');

		$this->expectException(PipelinqTransportException::class);
		$this->expectExceptionMessage('pipelinq endpoint is not configured');

		$this->adapter(client: $client, endpoint: '   ')->getContact(externalId: 'c5');

	}//end testAMissingEndpointFailsWithoutARequest()

	/**
	 * A missing token fails before any request is made.
	 *
	 * @return void
	 */
	public function testAMissingTokenFailsWithoutARequest(): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::never())->method('get');

		$this->expectException(PipelinqTransportException::class);
		$this->expectExceptionMessage('pipelinq token is not configured');

		$this->adapter(client: $client, token: '')->getContact(externalId: 'c6');

	}//end testAMissingTokenFailsWithoutARequest()

	/**
	 * An empty external id is refused before the cache or the network.
	 *
	 * @return void
	 */
	public function testAnEmptyExternalIdIsRefused(): void {
		$client = $this->createMock(IClient::class);
		$client->expects(self::never())->method('get');

		$this->expectException(PipelinqTransportException::class);
		$this->expectExceptionMessage('pipelinq Contact lookup requires a non-empty externalId');

		$this->adapter(client: $client)->getContact(externalId: '  ');

	}//end testAnEmptyExternalIdIsRefused()

	/**
	 * A corrupt cache entry is ignored and the contact is fetched.
	 *
	 * @return void
	 */
	public function testACorruptCacheEntryIsIgnored(): void {
		$this->cacheStore[PipelinqContactAdapter::CONTACT_CACHE_KEY_PREFIX . 'c7'] = '{broken';

		$client = $this->createMock(IClient::class);
		$client->expects(self::once())->method('get')->willReturn($this->response(status: 200, body: '{"legalName":"Fresh"}'));

		$contact = $this->adapter(client: $client)->getContact(externalId: 'c7');

		self::assertSame('Fresh', $contact->legalName);

	}//end testACorruptCacheEntryIsIgnored()

	/**
	 * A cache entry whose JSON is not an object is ignored too.
	 *
	 * @return void
	 */
	public function testANonObjectCacheEntryIsIgnored(): void {
		$this->cacheStore[PipelinqContactAdapter::CONTACT_CACHE_KEY_PREFIX . 'c8'] = '42';

		$client = $this->createMock(IClient::class);
		$client->expects(self::once())->method('get')->willReturn($this->response(status: 200, body: '{"legalName":"Fresh"}'));

		self::assertSame('Fresh', $this->adapter(client: $client)->getContact(externalId: 'c8')->legalName);

	}//end testANonObjectCacheEntryIsIgnored()

	/**
	 * The adapter exposes its cache and starts with a closed breaker.
	 *
	 * @return void
	 */
	public function testExposesItsCacheAndBreakerState(): void {
		$adapter = $this->adapter(client: $this->createMock(IClient::class));

		self::assertSame(CircuitBreaker::STATE_CLOSED, $adapter->circuitState());
		$adapter->cache()->set('k', 'v');
		self::assertSame('v', $this->cacheStore['k']);

	}//end testExposesItsCacheAndBreakerState()
}//end class
