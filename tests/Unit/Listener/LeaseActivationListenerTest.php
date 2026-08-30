<?php

/**
 * Unit tests for LeaseActivationListener.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/revive-lease-capabilities/specs/revive-lease-capabilities/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Shillinq\Listener\LeaseActivationListener;
use OCA\Shillinq\Service\LeaseAmortizationCalculator;
use OCA\Shillinq\Service\LeasePaymentScheduleService;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Proves the wiring: a LeaseContract crossing the `draft → active` edge (or
 * created already `active`) persists an amortizing LeasePaymentSchedule, and
 * that non-edge / out-of-scope saves persist nothing (REQ-LEASE-REVIVE-001).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class LeaseActivationListenerTest extends TestCase {

	/**
	 * Captured saveObject payloads from the ObjectService stub.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->saved = [];

	}//end setUp()

	/**
	 * Build the listener over a schedule service backed by an ObjectService
	 * stub seeded with the given leases.
	 *
	 * @param array<int,array<string,mixed>> $leases LeaseContract records.
	 * @param string $schemaSlug Slug the schema resolver reports for the event entity.
	 *
	 * @return LeaseActivationListener
	 */
	private function buildListener(array $leases, string $schemaSlug = 'LeaseContract'): LeaseActivationListener {
		$saved = &$this->saved;
		$stub = new class($leases, $saved) {

			/**
			 * Lease records.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $leases;

			/**
			 * Capture sink (by reference).
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $saved;

			/**
			 * The schema most recently selected on the fluent chain.
			 *
			 * @var string
			 */
			private string $currentSchema = '';

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $leases Lease records.
			 * @param array<int,array<string,mixed>> $saved Capture sink (by reference).
			 */
			public function __construct(array $leases, array &$saved) {
				$this->leases = & $leases;
				$this->saved = & $saved;
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
				$this->currentSchema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return leases matching the administration filter.
			 *
			 * @param array<string,mixed> $params Query params.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
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
			 * Capture a saved object.
			 *
			 * @param array<string,mixed> $object The object payload.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object): array {
				$this->saved[] = ['object' => $object, 'schema' => $this->currentSchema];
				return $object;
			}//end saveObject()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($stub);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$scheduleService = new LeasePaymentScheduleService(
			appConfig: $appConfig,
			calculator: new LeaseAmortizationCalculator(),
			logger: $this->createMock(LoggerInterface::class),
			objectService: new DuckObjectServiceAdapter($stub),
		);

		$schemaResolver = $this->createMock(ListenerSchemaResolver::class);
		$schemaResolver->method('schemaSlug')->willReturn($schemaSlug);

		return new LeaseActivationListener(
			scheduleService: $scheduleService,
			schemaResolver: $schemaResolver,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end buildListener()

	/**
	 * Build an ObjectEntity mock carrying a numeric schema **id**, exactly as
	 * OpenRegister stamps it (`setSchema((string) $schema->getId())`).
	 *
	 * A hand-built entity carrying the slug is a shape production never
	 * produces; the slug arrives through {@see ListenerSchemaResolver}.
	 *
	 * @param string $schemaId Numeric schema id as OR stamps it.
	 * @param array<string,mixed> $object The object body.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schemaId, array $object): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getSchema')->willReturn($schemaId);
		$entity->method('getObject')->willReturn($object);
		return $entity;
	}//end entity()

	/**
	 * A baseline capitalised vehicle lease at a given status.
	 *
	 * @param string $status Lifecycle status.
	 *
	 * @return array<string,mixed>
	 */
	private function lease(string $status): array {
		return [
			'@self' => ['slug' => 'lease-1', 'id' => 'lease-1'],
			'leaseNumber' => 'VH-1',
			'assetClass' => 'vehicle',
			'classification' => 'IFRS16-capitalised',
			'status' => $status,
			'nonCancellableTermMonths' => 36,
			'paymentFrequency' => 'monthly',
			'paymentTiming' => 'in-arrears',
			'basePaymentAmount' => 1000.0,
			'ibrPercent' => 4.0,
			'administrationId' => 'adm-1',
			'extensionOptions' => [],
		];

	}//end lease()

	/**
	 * The draft → active update edge persists an amortizing 36-row schedule.
	 *
	 * @return void
	 */
	public function testDraftToActiveUpdatePersistsAmortizingSchedule(): void {
		$listener = $this->buildListener([$this->lease('active')]);

		$event = $this->createConfiguredMock(
			ObjectUpdatedEvent::class,
			[
				'getObject' => $this->entity('5011', $this->lease('active')),
				'getOldObject' => $this->entity('5011', $this->lease('draft')),
			]
		);

		$listener->handle($event);

		self::assertCount(36, $this->saved);
		self::assertSame('LeasePaymentSchedule', $this->saved[0]['schema']);

		// Every row amortizes: interest + principal == the level payment.
		foreach ($this->saved as $row) {
			$line = $row['object'];
			self::assertEqualsWithDelta(
				(float)$line['paymentAppliedTotal'],
				((float)$line['paymentInterestPortion'] + (float)$line['paymentPrincipalPortion']),
				0.01
			);
		}

		// The final period fully extinguishes the lease liability.
		$final = $this->saved[35]['object'];
		self::assertSame(36, $final['periodSequence']);
		self::assertEqualsWithDelta(0.0, (float)$final['closingLeaseLiability'], 0.01);
		self::assertEqualsWithDelta(0.0, (float)$final['closingRouAsset'], 0.01);

	}//end testDraftToActiveUpdatePersistsAmortizingSchedule()

	/**
	 * A lease created already `active` also generates the schedule.
	 *
	 * @return void
	 */
	public function testCreatedActiveLeaseGeneratesSchedule(): void {
		$listener = $this->buildListener([$this->lease('active')]);

		$event = $this->createConfiguredMock(
			ObjectCreatedEvent::class,
			['getObject' => $this->entity('5011', $this->lease('active'))]
		);

		$listener->handle($event);

		self::assertCount(36, $this->saved);

	}//end testCreatedActiveLeaseGeneratesSchedule()

	/**
	 * A save that does not cross into `active` (already active) writes nothing.
	 *
	 * @return void
	 */
	public function testAlreadyActiveNonEdgeWritesNothing(): void {
		$listener = $this->buildListener([$this->lease('active')]);

		$event = $this->createConfiguredMock(
			ObjectUpdatedEvent::class,
			[
				'getObject' => $this->entity('5011', $this->lease('active')),
				'getOldObject' => $this->entity('5011', $this->lease('active')),
			]
		);

		$listener->handle($event);

		self::assertCount(0, $this->saved);

	}//end testAlreadyActiveNonEdgeWritesNothing()

	/**
	 * An update to a non-LeaseContract schema writes nothing.
	 *
	 * @return void
	 */
	public function testNonLeaseSchemaWritesNothing(): void {
		$listener = $this->buildListener([$this->lease('active')], 'StockMove');

		$event = $this->createConfiguredMock(
			ObjectUpdatedEvent::class,
			[
				'getObject' => $this->entity('5099', $this->lease('active')),
				'getOldObject' => $this->entity('5099', $this->lease('draft')),
			]
		);

		$listener->handle($event);

		self::assertCount(0, $this->saved);

	}//end testNonLeaseSchemaWritesNothing()

	/**
	 * An exempt lease activating carries no schedule (REQ-LE-003).
	 *
	 * @return void
	 */
	public function testExemptLeaseWritesNothing(): void {
		$exemptActive = $this->lease('active');
		$exemptActive['classification'] = 'short-term-exempt';
		$exemptDraft = $this->lease('draft');
		$exemptDraft['classification'] = 'short-term-exempt';

		$listener = $this->buildListener([$exemptActive]);

		$event = $this->createConfiguredMock(
			ObjectUpdatedEvent::class,
			[
				'getObject' => $this->entity('5011', $exemptActive),
				'getOldObject' => $this->entity('5011', $exemptDraft),
			]
		);

		$listener->handle($event);

		self::assertCount(0, $this->saved);

	}//end testExemptLeaseWritesNothing()
}//end class
