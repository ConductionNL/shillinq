<?php

/**
 * Integration tests proving the two procurement-governance gates fire through
 * PurchaseOrderService::createPurchaseOrder().
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
 * @spec openspec/specs/procurement-governance/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\PurchaseOrderService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Proves REQ-PG-002 (unqualified supplier blocked) and REQ-PG-004 (call-off over
 * ceiling blocked) at the single mutation point that creates a PurchaseOrder.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PurchaseOrderGovernanceGuardTest extends TestCase {
	/**
	 * Build the PO service (with self-constructed governance guards sharing the
	 * same in-memory stub) under the given qualification policy.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Seed rows.
	 * @param string $qualificationPolicy 'true' or 'false'.
	 * @param InMemoryObjectServiceStub|null $stubOut Receives the stub.
	 *
	 * @return PurchaseOrderService
	 */
	private function buildService(array $data, string $qualificationPolicy, ?InMemoryObjectServiceStub &$stubOut = null): PurchaseOrderService {
		// ADR-084: the stub used to reach the service (and its self-constructed
		// governance guards) through a ContainerInterface mock, while
		// `objectService:` got a bare createMock() — so the guards consulted an
		// EMPTY double and neither REQ-PG-002 nor REQ-PG-004 could ever fire.
		$stub = new InMemoryObjectServiceStub($data);
		$stubOut = $stub;

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($qualificationPolicy): string {
				return match ($key) {
					'register' => 'shillinq',
					'require_supplier_qualification_for_po' => $qualificationPolicy,
					'require_approved_requisition_for_po' => 'false',
					default => $default,
				};
			}
		);

		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->method('currentUserId')->willReturn('inkoper-1');
		$administrationContext->method('canAccess')->willReturnCallback(
			static fn (string $administrationId): bool => $administrationId === 'adm-1'
		);

		$notificationManager = $this->createMock(INotificationManager::class);
		$notificationManager->method('createNotification')->willReturnCallback(
			function (): INotification {
				$notification = $this->createMock(INotification::class);
				$notification->method('setApp')->willReturnSelf();
				$notification->method('setUser')->willReturnSelf();
				$notification->method('setDateTime')->willReturnSelf();
				$notification->method('setObject')->willReturnSelf();
				$notification->method('setSubject')->willReturnSelf();
				return $notification;
			}
		);

		return new PurchaseOrderService(
			appConfig: $appConfig,
			administrationContext: $administrationContext,
			notificationManager: $notificationManager,
			logger: $this->createMock(LoggerInterface::class),
			objectService: $stub,
		);
	}//end buildService()

	/**
	 * A minimal valid PO payload.
	 *
	 * @param array<string,mixed> $overrides Extra/override payload keys.
	 *
	 * @return array<string,mixed>
	 */
	private function payload(array $overrides = []): array {
		return array_merge(
			[
				'supplierId' => 'SUP-1',
				'costCenter' => 'CC-1',
				'lines' => [['productCode' => 'P1', 'quantity' => 1, 'unitPrice' => 30.00, 'vatRate' => 0.21, 'glAccount' => '4400']],
			],
			$overrides
		);
	}//end payload()

	/**
	 * REQ-PG-002: with the policy ON, a first PO to an unqualified supplier is
	 * blocked and no PurchaseOrder is persisted.
	 *
	 * @return void
	 */
	public function testFirstPoToUnqualifiedSupplierIsBlocked(): void {
		$service = $this->buildService(data: [], qualificationPolicy: 'true', stubOut: $stub);

		try {
			$service->createPurchaseOrder(administrationId: 'adm-1', payload: $this->payload());
			self::fail('Expected the PO to be blocked for an unqualified supplier.');
		} catch (\RuntimeException $e) {
			self::assertStringContainsString('not qualified', $e->getMessage());
		}

		$poSaves = array_filter($stub->saved, static fn ($s) => $s['schema'] === 'PurchaseOrder');
		self::assertSame([], $poSaves, 'No PurchaseOrder should have been persisted.');
	}//end testFirstPoToUnqualifiedSupplierIsBlocked()

	/**
	 * With the policy OFF, an unqualified supplier is allowed (default-inert gate).
	 *
	 * @return void
	 */
	public function testUnqualifiedSupplierAllowedWhenPolicyOff(): void {
		$service = $this->buildService(data: [], qualificationPolicy: 'false', stubOut: $stub);

		$po = $service->createPurchaseOrder(administrationId: 'adm-1', payload: $this->payload());
		self::assertSame('SUP-1', $po['supplierId']);
	}//end testUnqualifiedSupplierAllowedWhenPolicyOff()

	/**
	 * REQ-PG-004: a call-off that exceeds the framework-agreement ceiling is
	 * blocked and no PurchaseOrder is persisted.
	 *
	 * @return void
	 */
	public function testCallOffExceedingCeilingIsBlocked(): void {
		$data = [
			'FrameworkAgreement' => [
				[
					'id' => 'fa-1',
					'administrationId' => 'adm-1',
					'agreementNumber' => 'FA-1',
					'ceilingAmount' => 5000000,
					'drawnAmount' => 4800000,
					'statusCode' => 'active',
					'validFrom' => '2026-01-01',
					'validUntil' => '2028-12-31',
				],
			],
		];
		$service = $this->buildService(data: $data, qualificationPolicy: 'false', stubOut: $stub);

		// Line total 300 000 cents (€3 000) drives drawn 4 800 000 -> 5 100 000 > ceiling.
		$payload = $this->payload(
			[
				'frameworkAgreementId' => 'FA-1',
				'lines' => [['productCode' => 'P1', 'quantity' => 1, 'unitPrice' => 3000.00, 'vatRate' => 0, 'glAccount' => '4400']],
			]
		);

		try {
			$service->createPurchaseOrder(administrationId: 'adm-1', payload: $payload);
			self::fail('Expected the over-ceiling call-off to be blocked.');
		} catch (\RuntimeException $e) {
			self::assertStringContainsString('ceiling', $e->getMessage());
		}

		$poSaves = array_filter($stub->saved, static fn ($s) => $s['schema'] === 'PurchaseOrder');
		self::assertSame([], $poSaves, 'No PurchaseOrder should have been persisted.');
	}//end testCallOffExceedingCeilingIsBlocked()

	/**
	 * A within-ceiling call-off creates the PO and draws down the agreement.
	 *
	 * @return void
	 */
	public function testWithinCeilingCallOffCreatesPoAndDrawsDown(): void {
		$data = [
			'FrameworkAgreement' => [
				[
					'id' => 'fa-1',
					'administrationId' => 'adm-1',
					'agreementNumber' => 'FA-1',
					'ceilingAmount' => 5000000,
					'drawnAmount' => 4800000,
					'statusCode' => 'active',
					'validFrom' => '2026-01-01',
					'validUntil' => '2028-12-31',
				],
			],
		];
		$service = $this->buildService(data: $data, qualificationPolicy: 'false', stubOut: $stub);

		// Line total 100 000 cents (€1 000): 4 800 000 -> 4 900 000, within ceiling.
		$payload = $this->payload(
			[
				'frameworkAgreementId' => 'FA-1',
				'lines' => [['productCode' => 'P1', 'quantity' => 1, 'unitPrice' => 1000.00, 'vatRate' => 0, 'glAccount' => '4400']],
			]
		);

		$po = $service->createPurchaseOrder(administrationId: 'adm-1', payload: $payload);
		self::assertSame('FA-1', $po['frameworkAgreementId']);

		$drawdowns = array_values(
			array_filter($stub->saved, static fn ($s) => $s['schema'] === 'FrameworkAgreement')
		);
		self::assertNotEmpty($drawdowns, 'A drawdown write is expected.');
		self::assertSame(4900000, $drawdowns[array_key_last($drawdowns)]['object']['drawnAmount']);
	}//end testWithinCeilingCallOffCreatesPoAndDrawsDown()
}//end class
