<?php

/**
 * Unit tests for PipelinqConfig.
 *
 * Exercises endpoint getter/setter, token storage via the secrets
 * store (never plaintext, never logged), and the testConnection()
 * health-check across success / 401 / 404 / 5xx / timeout cases.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Pipelinq
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-01-config-contact-link/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Pipelinq;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\Pipelinq\PipelinqConfig;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for PipelinqConfig.
 */
final class PipelinqConfigTest extends TestCase {

	/**
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * @var ICredentialsManager&MockObject
	 */
	private ICredentialsManager&MockObject $credentials;

	/**
	 * @var IClientService&MockObject
	 */
	private IClientService&MockObject $clientService;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Service under test.
	 *
	 * @var PipelinqConfig
	 */
	private PipelinqConfig $config;

	/**
	 * Set up mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->credentials = $this->createMock(ICredentialsManager::class);
		$this->clientService = $this->createMock(IClientService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->config = new PipelinqConfig(
			appConfig: $this->appConfig,
			credentialsManager: $this->credentials,
			clientService: $this->clientService,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * getPipelinqEndpoint pulls from IAppConfig under the canonical key.
	 */
	public function testGetPipelinqEndpointReturnsAppConfigValue(): void {
		$this->appConfig->expects(self::once())
			->method('getValueString')
			->with(Application::APP_ID, PipelinqConfig::KEY_ENDPOINT, '')
			->willReturn('https://pipelinq.example.com');

		self::assertSame('https://pipelinq.example.com', $this->config->getPipelinqEndpoint());

	}//end testGetPipelinqEndpointReturnsAppConfigValue()

	/**
	 * setPipelinqEndpoint trims the value and writes it to IAppConfig.
	 */
	public function testSetPipelinqEndpointTrimsAndPersists(): void {
		$this->appConfig->expects(self::once())
			->method('setValueString')
			->with(Application::APP_ID, PipelinqConfig::KEY_ENDPOINT, 'https://pipelinq.example.com/api');

		$this->config->setPipelinqEndpoint("  https://pipelinq.example.com/api  \n");

	}//end testSetPipelinqEndpointTrimsAndPersists()

	/**
	 * Token storage: a non-empty token MUST land in the secrets store
	 * via ICredentialsManager::store — NOT IAppConfig::setValueString.
	 *
	 * This is the contract that keeps the token out of plaintext config.
	 */
	public function testSetPipelinqTokenStoresInSecretsStoreNotAppConfig(): void {
		$this->credentials->expects(self::once())
			->method('store')
			->with('', PipelinqConfig::CREDENTIAL_ID_TOKEN, 'secret-token-value');

		// IAppConfig must NOT be called for the token — strict expectation.
		$this->appConfig->expects(self::never())
			->method('setValueString');

		$this->config->setPipelinqToken('secret-token-value');

	}//end testSetPipelinqTokenStoresInSecretsStoreNotAppConfig()

	/**
	 * An empty token deletes the secret — never an empty store call.
	 */
	public function testSetPipelinqTokenEmptyDeletesSecret(): void {
		$this->credentials->expects(self::once())
			->method('delete')
			->with('', PipelinqConfig::CREDENTIAL_ID_TOKEN);
		$this->credentials->expects(self::never())->method('store');

		$this->config->setPipelinqToken('   ');

	}//end testSetPipelinqTokenEmptyDeletesSecret()

	/**
	 * getPipelinqToken retrieves from the credentials manager.
	 */
	public function testGetPipelinqTokenReadsFromSecretsStore(): void {
		$this->credentials->expects(self::once())
			->method('retrieve')
			->with('', PipelinqConfig::CREDENTIAL_ID_TOKEN)
			->willReturn('stored-token');

		self::assertSame('stored-token', $this->config->getPipelinqToken());

	}//end testGetPipelinqTokenReadsFromSecretsStore()

	/**
	 * hasPipelinqToken reflects whether the secret is non-empty.
	 */
	public function testHasPipelinqTokenTrueWhenSecretPresent(): void {
		$this->credentials->method('retrieve')->willReturn('not-empty');
		self::assertTrue($this->config->hasPipelinqToken());

	}//end testHasPipelinqTokenTrueWhenSecretPresent()

	/**
	 * hasPipelinqToken is false when nothing is stored.
	 */
	public function testHasPipelinqTokenFalseWhenAbsent(): void {
		$this->credentials->method('retrieve')->willReturn(null);
		self::assertFalse($this->config->hasPipelinqToken());

	}//end testHasPipelinqTokenFalseWhenAbsent()

	/**
	 * The logger MUST NOT receive any log call that includes the token
	 * value as part of a successful setPipelinqToken roundtrip.
	 *
	 * Verifies the "never logged" half of ADR-005's secrets handling.
	 */
	public function testTokenNeverLoggedOnHappyPath(): void {
		$this->logger->expects(self::never())->method('info');
		$this->logger->expects(self::never())->method('warning');
		$this->logger->expects(self::never())->method('error');
		$this->logger->expects(self::never())->method('debug');

		$this->credentials->expects(self::once())
			->method('store')
			->with('', PipelinqConfig::CREDENTIAL_ID_TOKEN, 'super-secret');

		$this->config->setPipelinqToken('super-secret');

	}//end testTokenNeverLoggedOnHappyPath()

	/**
	 * testConnection success: 200 OK → success:true with status 200.
	 */
	public function testTestConnectionSuccessOn200(): void {
		$this->primeEndpointAndToken('https://pipelinq.example.com', 'tok');

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);

		$client = $this->createMock(IClient::class);
		$client->expects(self::once())
			->method('get')
			->with(
				'https://pipelinq.example.com/health',
				self::callback(
					function (array $options): bool {
						return ($options['headers']['Authorization'] ?? '') === 'Bearer tok'
							&& ($options['timeout'] ?? 0) > 0;
					}
				)
			)
			->willReturn($response);

		$this->clientService->method('newClient')->willReturn($client);

		$result = $this->config->testConnection();
		self::assertTrue($result['success']);
		self::assertSame(200, $result['status']);

	}//end testTestConnectionSuccessOn200()

	/**
	 * testConnection: 401 → success:false, status:401 surfaced.
	 */
	public function testTestConnectionFailureOn401(): void {
		$this->primeEndpointAndToken('https://pipelinq.example.com', 'tok');
		$this->stubHealthResponse(statusCode: 401);

		$result = $this->config->testConnection();
		self::assertFalse($result['success']);
		self::assertSame(401, $result['status']);
		self::assertStringContainsString('401', $result['message']);

	}//end testTestConnectionFailureOn401()

	/**
	 * testConnection: 404 → success:false, status:404 surfaced.
	 */
	public function testTestConnectionFailureOn404(): void {
		$this->primeEndpointAndToken('https://pipelinq.example.com', 'tok');
		$this->stubHealthResponse(statusCode: 404);

		$result = $this->config->testConnection();
		self::assertFalse($result['success']);
		self::assertSame(404, $result['status']);
		self::assertStringContainsString('404', $result['message']);

	}//end testTestConnectionFailureOn404()

	/**
	 * testConnection: 5xx → success:false, status:503 surfaced.
	 */
	public function testTestConnectionFailureOn5xx(): void {
		$this->primeEndpointAndToken('https://pipelinq.example.com', 'tok');
		$this->stubHealthResponse(statusCode: 503);

		$result = $this->config->testConnection();
		self::assertFalse($result['success']);
		self::assertSame(503, $result['status']);

	}//end testTestConnectionFailureOn5xx()

	/**
	 * testConnection: transport exception (timeout/DNS) → success:false,
	 * status:0, exception class+message logged (NO token in the log).
	 */
	public function testTestConnectionFailureOnTransportException(): void {
		$this->primeEndpointAndToken('https://pipelinq.example.com', 'tok');

		$client = $this->createMock(IClient::class);
		$client->method('get')->willThrowException(new RuntimeException('Connection timed out'));
		$this->clientService->method('newClient')->willReturn($client);

		$this->logger->expects(self::once())
			->method('warning')
			->with(
				self::stringContains('pipelinq health-check failed'),
				self::callback(
					function (array $ctx): bool {
						// CRITICAL: the log payload MUST NOT contain the token.
						$serialised = json_encode($ctx);
						return strpos((string)$serialised, 'tok') === false
							&& ($ctx['exception'] ?? '') === RuntimeException::class;
					}
				)
			);

		$result = $this->config->testConnection();
		self::assertFalse($result['success']);
		self::assertSame(0, $result['status']);
		self::assertStringContainsString('Connection timed out', $result['message']);

	}//end testTestConnectionFailureOnTransportException()

	/**
	 * testConnection short-circuits with a clear message when no endpoint
	 * is configured — avoiding a useless health-check against ''.
	 */
	public function testTestConnectionShortCircuitsOnMissingEndpoint(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		// No HTTP client should be obtained.
		$this->clientService->expects(self::never())->method('newClient');

		$result = $this->config->testConnection();
		self::assertFalse($result['success']);
		self::assertStringContainsString('endpoint', $result['message']);

	}//end testTestConnectionShortCircuitsOnMissingEndpoint()

	/**
	 * testConnection short-circuits when no token is configured.
	 */
	public function testTestConnectionShortCircuitsOnMissingToken(): void {
		$this->appConfig->method('getValueString')->willReturn('https://pipelinq.example.com');
		$this->credentials->method('retrieve')->willReturn(null);
		$this->clientService->expects(self::never())->method('newClient');

		$result = $this->config->testConnection();
		self::assertFalse($result['success']);
		self::assertStringContainsString('token', $result['message']);

	}//end testTestConnectionShortCircuitsOnMissingToken()

	/**
	 * Configure appConfig + credentialsManager mocks to return the
	 * supplied endpoint URL and token to subsequent reads.
	 *
	 * @param string $endpoint Endpoint URL.
	 * @param string $token Token value.
	 */
	private function primeEndpointAndToken(string $endpoint, string $token): void {
		$this->appConfig->method('getValueString')->willReturn($endpoint);
		$this->credentials->method('retrieve')->willReturn($token);

	}//end primeEndpointAndToken()

	/**
	 * Stub the HTTP client to return a response with the given status.
	 *
	 * @param int $statusCode HTTP status code.
	 */
	private function stubHealthResponse(int $statusCode): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($statusCode);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($response);
		$this->clientService->method('newClient')->willReturn($client);

	}//end stubHealthResponse()
}//end class
