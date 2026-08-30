<?php

/**
 * Unit tests for LeaseDecideskWebhookService.
 *
 * Covers payload shape, the shouldDeliver gate (status + URL),
 * config/secret round-trips, and fail-soft logging on non-2xx
 * response or transport-level error.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-ifrs-16-lease/specs/bookkeeping-lease-reassessment/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\LeaseDecideskWebhookService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the decidesk webhook delivery skeleton.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class LeaseDecideskWebhookServiceTest extends TestCase {

	private IAppConfig&MockObject $appConfig;

	private ICredentialsManager&MockObject $credentialsManager;

	private IClientService&MockObject $clientService;

	private LoggerInterface&MockObject $logger;

	private LeaseDecideskWebhookService $service;

	/**
	 * Build a fresh service with all collaborators mocked.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->credentialsManager = $this->createMock(originalClassName: ICredentialsManager::class);
		$this->clientService = $this->createMock(originalClassName: IClientService::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->service = new LeaseDecideskWebhookService(
			appConfig: $this->appConfig,
			credentialsManager: $this->credentialsManager,
			clientService: $this->clientService,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * The buildPayload helper preserves the audit-relevant fields and stamps
	 * a kind + ISO 8601 requestedAt.
	 *
	 * @return void
	 */
	public function testBuildPayloadIncludesAuditFields(): void {
		$event = [
			'id' => 'evt-001',
			'reassessmentNumber' => 'LEASE-001-reassess-001',
			'eventType' => 'extension-option-reassessment',
			'sourceLease' => 'LEASE-001',
			'administrationId' => 'admin-01',
			'triggerDescription' => 'Board decision: renew',
			'preEventLiabilityCents' => 100_000_00,
			'postEventLiabilityCents' => 150_000_00,
			'rouAssetAdjustmentCents' => 50_000_00,
			'plImpactCents' => 0,
			'remeasurementApproach' => 'catch-up-adjustment',
			'approver' => 'person-99',
		];

		$payload = $this->service->buildPayload(event: $event);

		$this->assertSame(expected: 'shillinq', actual: $payload['source']);
		$this->assertSame(expected: 'lease-reassessment-approval-request', actual: $payload['kind']);
		$this->assertSame(expected: 'evt-001', actual: $payload['eventId']);
		$this->assertSame(expected: 'LEASE-001', actual: $payload['leaseContractId']);
		$this->assertSame(expected: 50_000_00, actual: $payload['rouAssetAdjustmentCents']);
		$this->assertNotEmpty(actual: $payload['requestedAt']);

	}//end testBuildPayloadIncludesAuditFields()

	/**
	 * shouldDeliver returns false when the event is already approved.
	 *
	 * @return void
	 */
	public function testShouldDeliverFalseForApprovedEvent(): void {
		$this->assertFalse(
			condition: $this->service->shouldDeliver(event: ['status' => 'approved'])
		);

	}//end testShouldDeliverFalseForApprovedEvent()

	/**
	 * shouldDeliver returns false when no URL is configured even if the
	 * event is pending-approval.
	 *
	 * @return void
	 */
	public function testShouldDeliverFalseWhenWebhookDisabled(): void {
		$this->appConfig->method('getValueString')->willReturn('');

		$this->assertFalse(
			condition: $this->service->shouldDeliver(event: ['status' => 'pending-approval'])
		);

	}//end testShouldDeliverFalseWhenWebhookDisabled()

	/**
	 * shouldDeliver returns true on a pending-approval event with a
	 * configured URL.
	 *
	 * @return void
	 */
	public function testShouldDeliverTrueOnPendingApprovalWithUrl(): void {
		$this->appConfig->method('getValueString')->willReturn('https://decidesk.example/webhook');

		$this->assertTrue(
			condition: $this->service->shouldDeliver(event: ['status' => 'pending-approval'])
		);

	}//end testShouldDeliverTrueOnPendingApprovalWithUrl()

	/**
	 * A 2xx response from decidesk yields a true delivery result and
	 * does NOT log a warning.
	 *
	 * @return void
	 */
	public function testDeliverReturnsTrueOn2xxResponse(): void {
		$this->appConfig->method('getValueString')->willReturn('https://decidesk.example/webhook');
		$this->credentialsManager->method('retrieve')->willReturn('secret-token');

		$response = $this->createMock(originalClassName: IResponse::class);
		$response->method('getStatusCode')->willReturn(202);

		$client = $this->createMock(originalClassName: IClient::class);
		$client->expects($this->once())
			->method('post')
			->willReturn($response);

		$this->clientService->method('newClient')->willReturn($client);
		$this->logger->expects($this->never())->method('warning');

		$result = $this->service->deliver(event: [
			'id' => 'evt-002',
			'status' => 'pending-approval',
		]);

		$this->assertTrue(condition: $result);

	}//end testDeliverReturnsTrueOn2xxResponse()

	/**
	 * A non-2xx response logs a warning and returns false (fail-soft).
	 *
	 * @return void
	 */
	public function testDeliverReturnsFalseOnNon2xx(): void {
		$this->appConfig->method('getValueString')->willReturn('https://decidesk.example/webhook');
		$this->credentialsManager->method('retrieve')->willReturn('');

		$response = $this->createMock(originalClassName: IResponse::class);
		$response->method('getStatusCode')->willReturn(500);

		$client = $this->createMock(originalClassName: IClient::class);
		$client->method('post')->willReturn($response);

		$this->clientService->method('newClient')->willReturn($client);
		$this->logger->expects($this->once())->method('warning');

		$result = $this->service->deliver(event: [
			'id' => 'evt-003',
			'status' => 'pending-approval',
		]);

		$this->assertFalse(condition: $result);

	}//end testDeliverReturnsFalseOnNon2xx()

	/**
	 * Transport-level exceptions are caught and logged; the caller never
	 * sees the exception (fail-soft, the persisted event is the source of
	 * truth).
	 *
	 * @return void
	 */
	public function testDeliverSwallowsTransportException(): void {
		$this->appConfig->method('getValueString')->willReturn('https://decidesk.example/webhook');
		$this->credentialsManager->method('retrieve')->willReturn('');

		$client = $this->createMock(originalClassName: IClient::class);
		$client->method('post')->willThrowException(exception: new \RuntimeException(message: 'connection refused'));

		$this->clientService->method('newClient')->willReturn($client);
		$this->logger->expects($this->once())->method('warning');

		$result = $this->service->deliver(event: [
			'id' => 'evt-004',
			'status' => 'pending-approval',
		]);

		$this->assertFalse(condition: $result);

	}//end testDeliverSwallowsTransportException()

	/**
	 * deliver short-circuits and returns false when shouldDeliver=false,
	 * never invoking the HTTP client.
	 *
	 * @return void
	 */
	public function testDeliverShortCircuitsOnApprovedEvent(): void {
		$this->clientService->expects($this->never())->method('newClient');

		$result = $this->service->deliver(event: [
			'id' => 'evt-005',
			'status' => 'approved',
		]);

		$this->assertFalse(condition: $result);

	}//end testDeliverShortCircuitsOnApprovedEvent()

	/**
	 * setWebhookToken with an empty string deletes the stored credential.
	 *
	 * @return void
	 */
	public function testSetWebhookTokenEmptyDeletes(): void {
		$this->credentialsManager->expects($this->once())
			->method('delete');
		$this->credentialsManager->expects($this->never())
			->method('store');

		$this->service->setWebhookToken(token: '   ');

	}//end testSetWebhookTokenEmptyDeletes()

	/**
	 * setWebhookToken with a non-empty string stores the trimmed value.
	 *
	 * @return void
	 */
	public function testSetWebhookTokenStoresTrimmed(): void {
		$this->credentialsManager->expects($this->once())
			->method('store')
			->with(
				$this->anything(),
				LeaseDecideskWebhookService::CREDENTIAL_ID_TOKEN,
				'abc-123'
			);

		$this->service->setWebhookToken(token: '  abc-123  ');

	}//end testSetWebhookTokenStoresTrimmed()

}//end class
