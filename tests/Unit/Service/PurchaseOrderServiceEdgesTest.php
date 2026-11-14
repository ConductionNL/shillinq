<?php

/**
 * Edge-case unit tests for PurchaseOrderService.
 *
 * Covers the Peppol submit-failure fallback, the manual PDF+email reason,
 * the buyer participant lookup, the requisition policy gate, the approval
 * Activity emission, notification failures, the PO-number sequence and the
 * persistence / query failure paths.
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
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\PurchaseOrderApprovalEntry;
use OCA\Shillinq\Lifecycle\FrameworkAgreementDrawdownGuard;
use OCA\Shillinq\Lifecycle\SupplierQualificationGuard;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\ApprovalActivity;
use OCA\Shillinq\Service\ApprovalActivityEmitter;
use OCA\Shillinq\Service\PurchaseOrder\PeppolBisOrderMapper;
use OCA\Shillinq\Service\PurchaseOrder\PeppolTransmissionAdapterInterface;
use OCA\Shillinq\Service\PurchaseOrder\PurchaseOrderMailerInterface;
use OCA\Shillinq\Service\PurchaseOrderApprovalService;
use OCA\Shillinq\Service\PurchaseOrderService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\Activity\IEvent;
use OCP\Activity\IManager as IActivityManager;
use OCP\IAppConfig;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * PurchaseOrderService edge-case tests.
 */
#[CoversClass(PurchaseOrderService::class)]
#[UsesClass(PeppolBisOrderMapper::class)]
#[UsesClass(SupplierQualificationGuard::class)]
#[UsesClass(FrameworkAgreementDrawdownGuard::class)]
#[UsesClass(PurchaseOrderApprovalService::class)]
#[UsesClass(PurchaseOrderApprovalEntry::class)]
#[UsesClass(ApprovalActivityEmitter::class)]
#[UsesClass(ApprovalActivity::class)]
final class PurchaseOrderServiceEdgesTest extends TestCase {

	/**
	 * Logger mock shared by the service under test.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the service.
	 *
	 * @param ObjectServiceInterface                  $objectService Object service double.
	 * @param array<string,string>                    $config        App-config overrides.
	 * @param PeppolTransmissionAdapterInterface|null $adapter       Peppol port.
	 * @param PurchaseOrderMailerInterface|null       $mailer        Mailer port.
	 * @param INotificationManager|null               $notifications Notification manager.
	 * @param ApprovalActivityEmitter|null            $emitter       Activity emitter.
	 *
	 * @return PurchaseOrderService
	 */
	private function buildService(
		ObjectServiceInterface $objectService,
		array $config = [],
		?PeppolTransmissionAdapterInterface $adapter = null,
		?PurchaseOrderMailerInterface $mailer = null,
		?INotificationManager $notifications = null,
		?ApprovalActivityEmitter $emitter = null,
	): PurchaseOrderService {
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($config): string {
				if (array_key_exists($key, $config) === true) {
					return $config[$key];
				}

				return $default;
			}
		);

		$context = $this->createStub(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(
			static fn (string $administrationId): bool => $administrationId === 'adm-1'
		);
		$context->method('currentUserId')->willReturn('requester-1');

		if (isset($this->logger) === false) {
			$this->logger = $this->createMock(LoggerInterface::class);
		}

		return new PurchaseOrderService(
			appConfig: $appConfig,
			administrationContext: $context,
			notificationManager: ($notifications ?? $this->createStub(INotificationManager::class)),
			logger: $this->logger,
			objectService: $objectService,
			peppolAdapter: ($adapter ?? $this->createStub(PeppolTransmissionAdapterInterface::class)),
			purchaseOrderMailer: ($mailer ?? $this->createStub(PurchaseOrderMailerInterface::class)),
			activityEmitter: $emitter,
		);

	}//end buildService()

	/**
	 * A fully approved PO record.
	 *
	 * @return array<string,mixed>
	 */
	private function approvedPo(): array {
		return [
			'id' => 'po-1',
			'administrationId' => 'adm-1',
			'poNumber' => 'PO-2026-adm-1-000001',
			'supplierId' => 'sup-1',
			'currency' => 'EUR',
			'totalAmount' => 100.0,
			'statusCode' => 'draft',
			'lines' => [
				[
					'lineNumber' => 1,
					'productCode' => 'P-1',
					'quantity' => 1.0,
					'unitPrice' => 100.0,
					'lineTotal' => 100.0,
					'vatRate' => 0.21,
					'vatAmount' => 21.0,
					'glAccount' => '4400',
				],
			],
			'approvalChain' => [
				[
					'role' => 'teamleider',
					'order' => 1,
					'decision' => 'approved',
					'decidedAt' => '2026-06-01T12:00:00+00:00',
					'userId' => 'tl-1',
				],
			],
		];

	}//end approvedPo()

	/**
	 * A valid create payload worth EUR 100.
	 *
	 * @param array<string,mixed> $extra Extra payload fields.
	 *
	 * @return array<string,mixed>
	 */
	private function payload(array $extra = []): array {
		return array_merge(
			[
				'supplierId' => 'sup-1',
				'costCenter' => 'CC-1',
				'lines' => [
					['productCode' => 'P-1', 'quantity' => 2, 'unitPrice' => 50.0, 'vatRate' => 0.21, 'glAccount' => '4400'],
				],
			],
			$extra
		);

	}//end payload()

	/**
	 * A zero or negative amount needs no approvers.
	 *
	 * @return void
	 */
	public function testDetermineApprovalChainIsEmptyForNonPositiveAmounts(): void {
		$service = $this->buildService(objectService: new InMemoryObjectServiceStub());

		self::assertSame([], $service->determineApprovalChain(amount: 0.0));
		self::assertSame([], $service->determineApprovalChain(amount: -12.5));
		self::assertSame([['role' => 'teamleider', 'order' => 1]], $service->determineApprovalChain(amount: 0.01));

	}//end testDetermineApprovalChainIsEmptyForNonPositiveAmounts()

	/**
	 * When the Access Point throws, the PO falls back to PDF+email with reason
	 * `peppol_send_failed`; the UBL carries the administration's own buyer
	 * participant id.
	 *
	 * @return void
	 */
	public function testPeppolSubmitFailureFallsBackToPdfEmail(): void {
		$os = new InMemoryObjectServiceStub(
			data: [
				'PurchaseOrder' => [$this->approvedPo()],
				'Administration' => [['id' => 'adm-1', 'peppolParticipantId' => ' 0106:12345678 ']],
			]
		);

		$capturedUbl = '';
		$adapter = $this->createMock(PeppolTransmissionAdapterInterface::class);
		$adapter->expects($this->once())->method('lookupParticipant')
			->with('adm-1', 'sup-1')
			->willReturn('0192:999');
		$adapter->expects($this->once())->method('submitOrder')
			->with(
				'0192:999',
				$this->callback(
					static function (string $ubl) use (&$capturedUbl): bool {
						$capturedUbl = $ubl;
						return true;
					}
				)
			)
			->willThrowException(new RuntimeException('AP down'));

		$mailer = $this->createMock(PurchaseOrderMailerInterface::class);
		$mailer->expects($this->once())->method('sendPurchaseOrderEmail')
			->with('adm-1', $this->callback(static fn (array $po): bool => $po['id'] === 'po-1'));

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->logger->expects($this->once())->method('warning')->with(
			'PurchaseOrderService: Peppol submission failed, falling back to PDF+email',
			['purchaseOrderId' => 'po-1', 'exception' => 'AP down']
		);

		$service = $this->buildService(objectService: $os, adapter: $adapter, mailer: $mailer);
		$result = $service->sendToPeppol(administrationId: 'adm-1', purchaseOrderId: 'po-1');

		self::assertSame('sent', $result['statusCode']);
		self::assertSame('peppol_send_failed', $result['peppolFallbackReason']);
		self::assertArrayNotHasKey('peppolMessageId', $result);
		self::assertStringContainsString('12345678', $capturedUbl);
		self::assertStringNotContainsString('00000000', $capturedUbl);

	}//end testPeppolSubmitFailureFallsBackToPdfEmail()

	/**
	 * A manual PDF+email send without a reason records the manual default.
	 *
	 * @return void
	 */
	public function testSendToPdfEmailWithoutReasonRecordsManualFallback(): void {
		$os = new InMemoryObjectServiceStub(data: ['PurchaseOrder' => [$this->approvedPo()]]);
		$service = $this->buildService(objectService: $os);

		$result = $service->sendToPDFEmail(administrationId: 'adm-1', purchaseOrderId: 'po-1', fallbackReason: '   ');

		self::assertSame('manual_pdf_email_fallback', $result['peppolFallbackReason']);
		self::assertSame('sent', $result['statusCode']);
		self::assertSame('manual_pdf_email_fallback', $os->saved[0]['object']['peppolFallbackReason']);

	}//end testSendToPdfEmailWithoutReasonRecordsManualFallback()

	/**
	 * With the requisition policy on, a blank requisition is refused.
	 *
	 * @return void
	 */
	public function testRequisitionPolicyRefusesBlankRequisition(): void {
		$os = new InMemoryObjectServiceStub();
		$service = $this->buildService(
			objectService: $os,
			config: ['require_approved_requisition_for_po' => 'true']
		);

		try {
			$service->createPurchaseOrder(administrationId: 'adm-1', payload: $this->payload());
			self::fail('Expected the requisition policy to refuse');
		} catch (RuntimeException $e) {
			self::assertSame('A purchase order requires an approved requisition', $e->getMessage());
		}

		self::assertSame([], $os->saved);

	}//end testRequisitionPolicyRefusesBlankRequisition()

	/**
	 * With the requisition policy on, an unknown or unapproved requisition is
	 * refused, and an approved one lets the PO through.
	 *
	 * @return void
	 */
	public function testRequisitionPolicyChecksTheRequisitionStatus(): void {
		$os = new InMemoryObjectServiceStub(
			data: [
				'Requisition' => [
					['id' => 'req-draft', 'administrationId' => 'adm-1', 'statusCode' => 'draft'],
					['id' => 'req-ok', 'administrationId' => 'adm-1', 'statusCode' => 'converted'],
				],
			]
		);
		$service = $this->buildService(
			objectService: $os,
			config: ['require_approved_requisition_for_po' => 'true']
		);

		foreach (['req-missing', 'req-draft'] as $requisitionId) {
			try {
				$service->createPurchaseOrder(
					administrationId: 'adm-1',
					payload: $this->payload(extra: ['requisitionId' => $requisitionId])
				);
				self::fail('Expected refusal for ' . $requisitionId);
			} catch (RuntimeException $e) {
				self::assertSame('Purchase order requires an approved requisition', $e->getMessage());
			}
		}

		$po = $service->createPurchaseOrder(
			administrationId: 'adm-1',
			payload: $this->payload(extra: ['requisitionId' => ' req-ok '])
		);
		self::assertSame('req-ok', $po['requisitionId']);
		self::assertSame(100.0, $po['totalAmount']);

	}//end testRequisitionPolicyChecksTheRequisitionStatus()

	/**
	 * Creating a PO numbers it after this year's existing POs, emits one
	 * approval_requested Activity, and a failing notification is logged
	 * without aborting the creation.
	 *
	 * @return void
	 */
	public function testCreateEmitsActivityAndSurvivesNotificationFailure(): void {
		$thisYear = date('Y');
		$os = new InMemoryObjectServiceStub(
			data: [
				'PurchaseOrder' => [
					['id' => 'old-1', 'administrationId' => 'adm-1', 'createdAt' => $thisYear . '-01-05T00:00:00+00:00'],
					['id' => 'old-2', 'administrationId' => 'adm-1', 'createdAt' => $thisYear . '-02-05T00:00:00+00:00'],
					['id' => 'old-3', 'administrationId' => 'adm-1', 'createdAt' => '1999-02-05T00:00:00+00:00'],
					['id' => 'old-4', 'administrationId' => 'adm-1', 'createdAt' => ''],
				],
				'AdministrationMembership' => [
					['administrationId' => 'adm-1', 'role' => 'teamleider', 'userId' => 'tl-1'],
					['administrationId' => 'adm-1', 'role' => 'teamleider', 'userId' => '  '],
				],
			]
		);

		$notifications = $this->createMock(INotificationManager::class);
		$notifications->expects($this->once())->method('createNotification')
			->willThrowException(new RuntimeException('notify down'));
		$notifications->expects($this->never())->method('notify');

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->logger->expects($this->once())->method('warning')->with(
			'PurchaseOrderService: failed to dispatch approval notification',
			$this->callback(
				static fn (array $ctx): bool => $ctx['approverId'] === 'tl-1'
					&& $ctx['role'] === 'teamleider'
					&& $ctx['exception'] === 'notify down'
			)
		);

		$event = $this->createMock(IEvent::class);
		foreach (['setApp', 'setType', 'setAuthor', 'setAffectedUser', 'setTimestamp', 'setObject', 'setMessage'] as $method) {
			$event->method($method)->willReturnSelf();
		}

		$event->expects($this->once())->method('setSubject')
			->with('approval_requested', $this->callback(static fn (array $p): bool => str_starts_with($p['object'], 'Purchase order PO-')))
			->willReturnSelf();
		$activityManager = $this->createMock(IActivityManager::class);
		$activityManager->method('generateEvent')->willReturn($event);
		$activityManager->expects($this->once())->method('publish')->with($event);
		$emitter = new ApprovalActivityEmitter(
			activityManager: $activityManager,
			userSession: $this->createStub(IUserSession::class),
			logger: $this->logger
		);

		$service = $this->buildService(objectService: $os, notifications: $notifications, emitter: $emitter);
		$po = $service->createPurchaseOrder(administrationId: 'adm-1', payload: $this->payload());

		self::assertStringEndsWith('-adm-1-000003', $po['poNumber']);
		self::assertSame('draft', $po['statusCode']);
		self::assertSame('requester-1', $po['requesterId']);
		$tasks = array_values(array_filter($os->saved, static fn (array $s): bool => $s['schema'] === 'ApprovalTask'));
		self::assertCount(1, $tasks);
		self::assertSame('teamleider', $tasks[0]['object']['role']);
		self::assertSame($po['id'], $tasks[0]['object']['purchaseOrderId']);

	}//end testCreateEmitsActivityAndSurvivesNotificationFailure()

	/**
	 * A failing save is logged and re-raised as "Failed to persist <schema>";
	 * an empty register slug falls back to 'shillinq'.
	 *
	 * @return void
	 */
	public function testSaveFailureIsLoggedAndRaised(): void {
		$os = $this->createMock(ObjectServiceInterface::class);
		$os->expects($this->atLeastOnce())->method('setRegister')->with('shillinq')->willReturnSelf();
		$os->method('setSchema')->willReturnSelf();
		$os->method('findAll')->willReturn([]);
		$os->method('saveObject')->willThrowException(new RuntimeException('db gone'));

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->logger->expects($this->once())->method('error')->with(
			'PurchaseOrderService: failed to persist object',
			['schema' => 'PurchaseOrder', 'exception' => 'db gone']
		);

		$service = $this->buildService(objectService: $os, config: ['register' => '']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Failed to persist PurchaseOrder');
		$service->createPurchaseOrder(administrationId: 'adm-1', payload: $this->payload());

	}//end testSaveFailureIsLoggedAndRaised()

	/**
	 * A failing query is logged and read as "no rows", so the PO reads as not found.
	 *
	 * @return void
	 */
	public function testQueryFailureReadsAsNotFound(): void {
		$os = $this->createMock(ObjectServiceInterface::class);
		$os->method('setRegister')->willReturnSelf();
		$os->method('setSchema')->willReturnSelf();
		$os->method('findAll')->willThrowException(new RuntimeException('query broke'));
		$os->expects($this->never())->method('saveObject');

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->logger->expects($this->once())->method('error')->with(
			'PurchaseOrderService: failed to query OpenRegister',
			['schema' => 'PurchaseOrder', 'exception' => 'query broke']
		);

		$service = $this->buildService(objectService: $os);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Purchase order not found');
		$service->sendToPDFEmail(administrationId: 'adm-1', purchaseOrderId: 'po-1');

	}//end testQueryFailureReadsAsNotFound()
}//end class
