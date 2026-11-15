<?php

/**
 * Unit tests for LeaseReassessmentService.
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

use OCA\Shillinq\Service\LeaseAmortizationCalculator;
use OCA\Shillinq\Service\LeaseReassessmentService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the IFRS 16 reassessment flow — indexation, extension-option,
 * modification, impairment — and the decidesk approval-routing threshold
 * (REQ-LR-001..REQ-LR-007).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class LeaseReassessmentServiceTest extends TestCase {

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturn('shillinq');

	}//end setUp()

	/**
	 * Build the service against an in-memory OR stub seeded with the given leases.
	 *
	 * The stub exposes setRegister/setSchema/findAll/saveObject so it stands in
	 * for the real OpenRegister ObjectService.
	 *
	 * @param array<int,array<string,mixed>> $leases LeaseContract records.
	 *
	 * @return array{0:LeaseReassessmentService,1:object} Service + the stub for assertions.
	 */
	private function buildService(array $leases): array {
		$stub = new class($leases) {

			/**
			 * LeaseContract records.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $leases;

			/**
			 * Captured saved rows, by schema.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			public array $saved = [];

			/**
			 * Last register/schema set, for assertion.
			 *
			 * @var string
			 */
			public string $lastSchema = '';

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $leases Lease records.
			 */
			public function __construct(array $leases) {
				$this->leases = $leases;
			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->lastSchema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return leases / events matching the administration filter.
			 *
			 * @param array<string,mixed> $params Query params.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				if ($this->lastSchema === 'LeaseReassessmentEvent') {
					return ($this->saved['LeaseReassessmentEvent'] ?? []);
				}

				$admin = ($params['filters']['administrationId'] ?? null);
				if ($admin === null) {
					return $this->leases;
				}

				return array_values(
					array_filter(
						$this->leases,
						static fn (array $lease): bool => ($lease['administrationId'] ?? null) === $admin
					)
				);
			}//end findAll()

			/**
			 * Capture saveObject calls; echo the row back.
			 *
			 * The schema may arrive as an explicit argument or through a
			 * preceding setSchema() call — the real ObjectService honours both,
			 * so the capture falls back to the last schema that was set rather
			 * than filing the row under an empty key.
			 *
			 * @param array<string,mixed> $object The row.
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object, string $register = '', string $schema = ''): array {
				$target = ($schema !== '') ? $schema : $this->lastSchema;
				$this->saved[$target] = ($this->saved[$target] ?? []);
				$this->saved[$target][] = $object;
				return $object;
			}//end saveObject()
		};

		$this->container->method('get')->willReturn($stub);

		$service = new LeaseReassessmentService(
			appConfig: $this->appConfig,
			calculator: new LeaseAmortizationCalculator(),
			logger: $this->createMock(LoggerInterface::class),
			objectService: new DuckObjectServiceAdapter($stub),
		);

		return [$service, $stub];
	}//end buildService()

	/**
	 * Build a baseline capitalised vehicle lease fixture.
	 *
	 * @param array<string,mixed> $overrides Optional field overrides.
	 *
	 * @return array<string,mixed>
	 */
	private function leaseFixture(array $overrides = []): array {
		$lease = [
			'@self' => ['slug' => 'lease-v', 'id' => 'lease-v'],
			'leaseNumber' => 'VH-2024-001',
			'lessor' => 'org-acme',
			'assetClass' => 'vehicle',
			'classification' => 'IFRS16-capitalised',
			'status' => 'active',
			'commencementDate' => '2024-01-01',
			'endDate' => '2026-12-31',
			'nonCancellableTermMonths' => 36,
			'paymentFrequency' => 'monthly',
			'paymentTiming' => 'in-arrears',
			'basePaymentAmount' => 1000.0,
			'paymentCurrency' => 'EUR',
			'ibrPercent' => 4.0,
			'administrationId' => 'adm-1',
			'extensionOptions' => [],
		];

		return array_merge($lease, $overrides);
	}//end leaseFixture()

	/**
	 * Indexation creates an event with payment-amount delta and balanced GL lines (REQ-LR-001).
	 *
	 * @return void
	 */
	public function testIndexationEventBuildsGlLines(): void {
		[$service, $stub] = $this->buildService([$this->leaseFixture()]);

		$event = $service->recordIndexationEvent(
			leaseContractId: 'lease-v',
			administrationId: 'adm-1',
			newPaymentAmount: 1021.0,
			triggerDescription: 'CPI +2.1%',
			approver: 'person-1',
		);

		self::assertIsArray($event);
		self::assertSame('indexation-remeasurement', $event['eventType']);
		self::assertSame('catch-up-adjustment', $event['remeasurementApproach']);
		self::assertSame('VH-2024-001-reassess-001', $event['reassessmentNumber']);
		self::assertSame('lease-v', $event['sourceLease']);
		self::assertSame(1000.0, $event['oldContractSnapshot']['basePaymentAmount']);
		self::assertSame(1021.0, $event['newContractSnapshot']['basePaymentAmount']);
		self::assertGreaterThan($event['preEventLeaseLiability'], $event['postEventLeaseLiability']);
		self::assertGreaterThan(0.0, $event['rouAssetAdjustment']);

		// Balanced GL lines: total debit == total credit.
		$debit = 0.0;
		$credit = 0.0;
		foreach ($event['glLines'] as $line) {
			if ($line['side'] === 'debit') {
				$debit += (float)$line['amount'];
				continue;
			}

			$credit += (float)$line['amount'];
		}

		self::assertEqualsWithDelta($debit, $credit, 0.01);
		self::assertSame('LeaseReassessmentEvent', array_key_first($stub->saved));

	}//end testIndexationEventBuildsGlLines()

	/**
	 * Extension-option reassessment lengthens the schedule (REQ-LR-002).
	 *
	 * @return void
	 */
	public function testExtensionOptionReassessment(): void {
		[$service, ] = $this->buildService([$this->leaseFixture()]);

		$event = $service->recordExtensionOptionReassessment(
			leaseContractId: 'lease-v',
			administrationId: 'adm-1',
			updatedExtensionOptions: [
				['months' => 24, 'exerciseLikelihood' => 'reasonably-certain'],
			],
			triggerDescription: 'Board decision: renew',
			approver: 'person-2',
		);

		self::assertIsArray($event);
		self::assertSame('extension-option-reassessment', $event['eventType']);

		// Extending the schedule by 24 months increases the post-event liability.
		self::assertGreaterThan(
			$event['preEventLeaseLiability'],
			$event['postEventLeaseLiability']
		);
		self::assertGreaterThan(0.0, $event['rouAssetAdjustment']);

	}//end testExtensionOptionReassessment()

	/**
	 * Modification with only basePaymentAmount routes to payment-modification (REQ-LR-003).
	 *
	 * @return void
	 */
	public function testPaymentModificationEventType(): void {
		[$service, ] = $this->buildService([$this->leaseFixture()]);

		$event = $service->recordModification(
			leaseContractId: 'lease-v',
			administrationId: 'adm-1',
			newTerms: ['basePaymentAmount' => 1200.0],
			approach: 'catch-up-adjustment',
			triggerDescription: 'Renegotiated payment',
		);

		self::assertIsArray($event);
		self::assertSame('payment-modification', $event['eventType']);
		self::assertSame('catch-up-adjustment', $event['remeasurementApproach']);

	}//end testPaymentModificationEventType()

	/**
	 * Modification with term change routes to term-modification (REQ-LR-003).
	 *
	 * @return void
	 */
	public function testTermModificationEventType(): void {
		[$service, ] = $this->buildService([$this->leaseFixture()]);

		$event = $service->recordModification(
			leaseContractId: 'lease-v',
			administrationId: 'adm-1',
			newTerms: ['nonCancellableTermMonths' => 48],
		);

		self::assertIsArray($event);
		self::assertSame('term-modification', $event['eventType']);

	}//end testTermModificationEventType()

	/**
	 * Modification with only IBR change routes to IBR-reset (REQ-LR-003).
	 *
	 * @return void
	 */
	public function testIbrResetEventType(): void {
		[$service, ] = $this->buildService([$this->leaseFixture()]);

		$event = $service->recordModification(
			leaseContractId: 'lease-v',
			administrationId: 'adm-1',
			newTerms: ['ibrPercent' => 6.0],
		);

		self::assertIsArray($event);
		self::assertSame('IBR-reset', $event['eventType']);

	}//end testIbrResetEventType()

	/**
	 * Impairment writes the RoU down to recoverable value and emits a P&L loss (REQ-LR-004).
	 *
	 * @return void
	 */
	public function testImpairmentEmitsPlLoss(): void {
		[$service, ] = $this->buildService([$this->leaseFixture()]);

		$event = $service->recordImpairment(
			leaseContractId: 'lease-v',
			administrationId: 'adm-1',
			recoverableValue: 5000.0,
			triggerDescription: 'Vehicle damage',
			approver: 'person-3',
		);

		self::assertIsArray($event);
		self::assertSame('impairment', $event['eventType']);
		self::assertLessThan(0.0, $event['rouAssetAdjustment']);
		self::assertGreaterThan(0.0, $event['plImpact']);

		// GL lines: Dr. lease-modification-gain-loss / Cr. rou-asset.
		$debitSubtype = null;
		$creditSubtype = null;
		foreach ($event['glLines'] as $line) {
			if ($line['side'] === 'debit') {
				$debitSubtype = $line['leaseAccountSubtype'];
				continue;
			}

			$creditSubtype = $line['leaseAccountSubtype'];
		}

		self::assertSame('lease-modification-gain-loss', $debitSubtype);
		self::assertSame('rou-asset', $creditSubtype);

	}//end testImpairmentEmitsPlLoss()

	/**
	 * Material events (> EUR 100K RoU delta) are flagged pending-approval (REQ-LR-007).
	 *
	 * @return void
	 */
	public function testMaterialEventRoutesPendingApproval(): void {
		// A 100K monthly payment for 60 months produces a multi-million RoU; the
		// catch-up adjustment from doubling that payment well exceeds 100K.
		$lease = $this->leaseFixture(
			[
				'basePaymentAmount' => 100000.0,
				'nonCancellableTermMonths' => 60,
			]
		);

		[$service, ] = $this->buildService([$lease]);

		$event = $service->recordIndexationEvent(
			leaseContractId: 'lease-v',
			administrationId: 'adm-1',
			newPaymentAmount: 200000.0,
		);

		self::assertIsArray($event);
		self::assertSame('pending-approval', $event['status']);
		self::assertSame(10_000_000, $service->approvalThresholdCents());

	}//end testMaterialEventRoutesPendingApproval()

	/**
	 * Immaterial events auto-approve (REQ-LR-007).
	 *
	 * @return void
	 */
	public function testImmaterialEventAutoApproves(): void {
		[$service, ] = $this->buildService([$this->leaseFixture()]);

		$event = $service->recordIndexationEvent(
			leaseContractId: 'lease-v',
			administrationId: 'adm-1',
			newPaymentAmount: 1010.0,
		);

		self::assertIsArray($event);
		self::assertSame('approved', $event['status']);

	}//end testImmaterialEventAutoApproves()

	/**
	 * An out-of-scope lease returns null (ADR-005 IDOR safety).
	 *
	 * @return void
	 */
	public function testOutOfScopeLeaseReturnsNull(): void {
		[$service, ] = $this->buildService([$this->leaseFixture()]);

		$event = $service->recordIndexationEvent(
			leaseContractId: 'lease-v',
			administrationId: 'OTHER-ADMIN',
			newPaymentAmount: 1010.0,
		);

		self::assertNull($event);

	}//end testOutOfScopeLeaseReturnsNull()

	/**
	 * Pins every saved reassessment event field for field, in key order, for
	 * an indexation, an extension-option reassessment, a modification with an
	 * unknown approach (normalised to catch-up) and with a prospective one,
	 * and an impairment; and that every record method refuses a lease of
	 * another administration with null and writes nothing.
	 *
	 * @return void
	 */
	public function testEveryRecordMethodWritesItsEventFieldForFieldAndRefusesOutOfScope(): void {
		$calls = [
			static fn (LeaseReassessmentService $s, string $adm): ?array => $s->recordIndexationEvent(
				leaseContractId: 'lease-v',
				administrationId: $adm,
				newPaymentAmount: 1030.0,
				approver: 'p-1'
			),
			static fn (LeaseReassessmentService $s, string $adm): ?array => $s->recordExtensionOptionReassessment(
				leaseContractId: 'lease-v',
				administrationId: $adm,
				updatedExtensionOptions: [['termMonths' => 12, 'exerciseLikelihood' => 'reasonably-certain']],
				triggerDescription: 'Optie zeker'
			),
			static fn (LeaseReassessmentService $s, string $adm): ?array => $s->recordModification(
				leaseContractId: 'lease-v',
				administrationId: $adm,
				newTerms: ['basePaymentAmount' => 900.0, 'newField' => 'x'],
				approach: 'bogus'
			),
			static fn (LeaseReassessmentService $s, string $adm): ?array => $s->recordModification(
				leaseContractId: 'lease-v',
				administrationId: $adm,
				newTerms: ['nonCancellableTermMonths' => 48],
				approach: 'prospective',
				approver: 'p-2'
			),
			static fn (LeaseReassessmentService $s, string $adm): ?array => $s->recordImpairment(
				leaseContractId: 'lease-v',
				administrationId: $adm,
				recoverableValue: 10000.0
			),
		];

		$rows = [];
		foreach ($calls as $call) {
			[$service, $stub] = $this->buildService([$this->leaseFixture()]);
			self::assertNull($call($service, 'OTHER-ADMIN'));
			self::assertSame([], $stub->saved);

			$event = $call($service, 'adm-1');
			self::assertSame($event, $stub->saved['LeaseReassessmentEvent'][0]);
			self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}/', (string)$event['eventDate']);
			unset($event['eventDate']);
			$rows[] = $event;
		}

		self::assertSame(
			[
				'eventType', 'remeasurementApproach', 'oldContractSnapshot', 'newContractSnapshot', 'preEventLeaseLiability',
				'postEventLeaseLiability', 'rouAssetAdjustment', 'plImpact', 'triggerDescription', 'approver', 'glLines',
				'reassessmentNumber', 'sourceLease', 'lease', 'administrationId', 'status', 'postedToGl', 'approvalDate',
			],
			array_keys($rows[0])
		);
		self::assertSame(
			['indexation-remeasurement', 'extension-option-reassessment', 'scope-modification', 'term-modification', 'impairment'],
			array_column($rows, 'eventType')
		);
		self::assertSame(
			['catch-up-adjustment', 'catch-up-adjustment', 'catch-up-adjustment', 'prospective', 'catch-up-adjustment'],
			array_column($rows, 'remeasurementApproach')
		);
		self::assertSame(
			['Indexation clause triggered', 'Optie zeker', 'Lease terms modified', 'Lease terms modified', 'Impairment write-down'],
			array_column($rows, 'triggerDescription')
		);
		self::assertSame(['p-1', '', '', 'p-2', ''], array_column($rows, 'approver'));
		self::assertSame(1030.0, $rows[0]['newContractSnapshot']['basePaymentAmount']);
		self::assertSame($rows[0]['oldContractSnapshot'], $rows[4]['newContractSnapshot']);
		self::assertSame($rows[4]['preEventLeaseLiability'], $rows[4]['postEventLeaseLiability']);
		self::assertSame(0.0, $rows[0]['plImpact']);
		self::assertSame(-$rows[4]['rouAssetAdjustment'], $rows[4]['plImpact']);
		self::assertSame(0.0, $rows[3]['rouAssetAdjustment']);
		self::assertSame(
			round($rows[0]['postEventLeaseLiability'] - $rows[0]['preEventLeaseLiability'], 2),
			$rows[0]['rouAssetAdjustment']
		);

		$golden = json_encode($rows, JSON_THROW_ON_ERROR);
		self::assertSame(
			'12c0667d',
			substr(hash('sha256', $golden), 0, 8),
			'Saved event rows changed: ' . $golden
		);

	}//end testEveryRecordMethodWritesItsEventFieldForFieldAndRefusesOutOfScope()

	/**
	 * Prospective approach records the event without a GL adjustment line
	 * (the future schedule absorbs the change).
	 *
	 * @return void
	 */
	public function testProspectiveModificationOmitsGlLines(): void {
		[$service, ] = $this->buildService([$this->leaseFixture()]);

		$event = $service->recordModification(
			leaseContractId: 'lease-v',
			administrationId: 'adm-1',
			newTerms: ['basePaymentAmount' => 1100.0],
			approach: 'prospective',
		);

		self::assertIsArray($event);
		self::assertSame(0.0, $event['rouAssetAdjustment']);
		self::assertSame([], $event['glLines']);

	}//end testProspectiveModificationOmitsGlLines()

	/**
	 * Reassessment numbers increment within a lease (REQ-LR-001 sequential numbering).
	 *
	 * @return void
	 */
	public function testReassessmentNumbersIncrement(): void {
		[$service, ] = $this->buildService([$this->leaseFixture()]);

		$first = $service->recordIndexationEvent(
			leaseContractId: 'lease-v',
			administrationId: 'adm-1',
			newPaymentAmount: 1010.0,
		);
		$second = $service->recordIndexationEvent(
			leaseContractId: 'lease-v',
			administrationId: 'adm-1',
			newPaymentAmount: 1020.0,
		);

		self::assertIsArray($first);
		self::assertIsArray($second);
		self::assertSame('VH-2024-001-reassess-001', $first['reassessmentNumber']);
		self::assertSame('VH-2024-001-reassess-002', $second['reassessmentNumber']);

	}//end testReassessmentNumbersIncrement()
}//end class
