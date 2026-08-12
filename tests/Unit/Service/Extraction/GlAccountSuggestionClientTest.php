<?php

/**
 * Unit tests for GlAccountSuggestionClient (REQ-GAC-003 / REQ-GAC-005).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Extraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/gl-account-suggestion-consume/specs/gl-account-suggestion-consume/spec.md#requirement-req-gac-003
 * @spec openspec/changes/gl-account-suggestion-consume/specs/gl-account-suggestion-consume/spec.md#requirement-req-gac-005
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Extraction;

use OCA\Shillinq\Service\Extraction\GlAccountSuggestionClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers: successful suggestion request/decode, docudesk route unavailable
 * (docudesk not installed), a transport failure, and the best-effort
 * correction-post contract (success and failure, neither ever throws).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class GlAccountSuggestionClientTest extends TestCase {

	/**
	 * Mock IClientService.
	 *
	 * @var IClientService&MockObject
	 */
	private IClientService&MockObject $clientService;

	/**
	 * Mock IURLGenerator.
	 *
	 * @var IURLGenerator&MockObject
	 */
	private IURLGenerator&MockObject $urlGenerator;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->clientService = $this->createMock(IClientService::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * Build the client under test.
	 *
	 * @return GlAccountSuggestionClient
	 */
	private function buildClient(): GlAccountSuggestionClient {
		return new GlAccountSuggestionClient(
			clientService: $this->clientService,
			urlGenerator: $this->urlGenerator,
			logger: $this->logger,
		);

	}//end buildClient()

	/**
	 * REQ-GAC-003: a successful suggestion request decodes docudesk's
	 * response and returns it verbatim.
	 *
	 * @return void
	 */
	public function testRequestSuggestionReturnsDecodedResponse(): void {
		$this->urlGenerator->method('linkToRouteAbsolute')
			->with(GlAccountSuggestionClient::SUGGEST_ROUTE_NAME, ['id' => 'ext-123'])
			->willReturn('https://nc.example/apps/docudesk/api/extraction/ext-123/suggest-account');

		$body = json_encode(
			[
				'extractionId' => 'ext-123',
				'supplierIdentity' => '12345678',
				'identityType' => 'kvk',
				'suggestedAccounts' => [
					[
						'code' => '4300',
						'label' => 'Kantoorkosten',
						'confidence' => 0.8,
						'rationale' => 'Booked to 4300 in 8 of the last 10 invoices from this supplier',
					],
				],
				'source' => 'history',
			]
		);

		$response = $this->createMock(originalClassName: IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn($body);

		$client = $this->createMock(originalClassName: IClient::class);
		$client->expects(self::once())->method('post')->willReturn($response);
		$this->clientService->method('newClient')->willReturn($client);

		$result = $this->buildClient()->requestSuggestion(
			extractionId: 'ext-123',
			candidateAccounts: [['code' => '4300', 'label' => 'Kantoorkosten']]
		);

		self::assertTrue($result['success']);
		self::assertSame('history', $result['suggestion']['source']);
		self::assertSame('4300', $result['suggestion']['suggestedAccounts'][0]['code']);

	}//end testRequestSuggestionReturnsDecodedResponse()

	/**
	 * REQ-GAC-006: when docudesk's route cannot be resolved (docudesk not
	 * installed), the client degrades gracefully — never throws.
	 *
	 * @return void
	 */
	public function testRequestSuggestionDegradesWhenRouteUnavailable(): void {
		$this->urlGenerator->method('linkToRouteAbsolute')
			->willThrowException(new \RuntimeException('route not found'));

		$result = $this->buildClient()->requestSuggestion(extractionId: 'ext-123', candidateAccounts: []);

		self::assertFalse($result['success']);
		self::assertNull($result['suggestion']);

	}//end testRequestSuggestionDegradesWhenRouteUnavailable()

	/**
	 * REQ-GAC-006: a transport failure (connection refused, timeout, etc.)
	 * degrades gracefully — never throws.
	 *
	 * @return void
	 */
	public function testRequestSuggestionDegradesOnTransportFailure(): void {
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://nc.example/x');

		$client = $this->createMock(originalClassName: IClient::class);
		$client->method('post')->willThrowException(new \RuntimeException('connection refused'));
		$this->clientService->method('newClient')->willReturn($client);

		$result = $this->buildClient()->requestSuggestion(extractionId: 'ext-123', candidateAccounts: []);

		self::assertFalse($result['success']);
		self::assertNull($result['suggestion']);

	}//end testRequestSuggestionDegradesOnTransportFailure()

	/**
	 * REQ-GAC-005: a successful correction post reports success and carries
	 * the operator's code/label.
	 *
	 * @return void
	 */
	public function testPostCorrectionSucceeds(): void {
		$this->urlGenerator->method('linkToRouteAbsolute')
			->with(GlAccountSuggestionClient::CORRECTIONS_ROUTE_NAME, ['id' => 'ext-123'])
			->willReturn('https://nc.example/apps/docudesk/api/extraction/ext-123/corrections');

		$response = $this->createMock(originalClassName: IResponse::class);
		$response->method('getStatusCode')->willReturn(200);

		$client = $this->createMock(originalClassName: IClient::class);
		$client->expects(self::once())
			->method('post')
			->with(
				self::anything(),
				self::callback(
					static function (array $options): bool {
						$decoded = json_decode((string)$options['body'], true);
						return $decoded['fields']['glAccountCode'] === '4900'
							&& $decoded['fields']['glAccountLabel'] === 'Diversen';
					}
				)
			)
			->willReturn($response);
		$this->clientService->method('newClient')->willReturn($client);

		$result = $this->buildClient()->postCorrection(extractionId: 'ext-123', accountCode: '4900', accountLabel: 'Diversen');

		self::assertTrue($result['success']);

	}//end testPostCorrectionSucceeds()

	/**
	 * REQ-GAC-005: a failed correction post never throws — best-effort,
	 * fail-soft, so it can never block or undo the already-committed local
	 * booking.
	 *
	 * @return void
	 */
	public function testPostCorrectionFailsSoftly(): void {
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://nc.example/x');

		$client = $this->createMock(originalClassName: IClient::class);
		$client->method('post')->willThrowException(new \RuntimeException('timeout'));
		$this->clientService->method('newClient')->willReturn($client);

		$result = $this->buildClient()->postCorrection(extractionId: 'ext-123', accountCode: '4900', accountLabel: null);

		self::assertFalse($result['success']);

	}//end testPostCorrectionFailsSoftly()
}//end class
