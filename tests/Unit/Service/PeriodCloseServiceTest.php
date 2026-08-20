<?php

/**
 * Unit tests for PeriodCloseService.
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
 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-14
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\PeriodCloseException;
use OCA\Shillinq\Service\PeriodCloseService;
use OCA\Shillinq\Service\SuspenseAgeingService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the PeriodClose lifecycle orchestration + role enforcement.
 *
 * Covers REQ-PC-002 (close / audit-lock transitions + stamps), REQ-PC-006
 * (reopen audit trail + close reason), and REQ-PC-008 (role gates).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PeriodCloseServiceTest extends TestCase {

	/**
	 * Mock group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * In-memory ObjectService stub capturing the last saved object.
	 *
	 * @var object
	 */
	private object $objectService;

	/**
	 * Set up shared fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->groupManager = $this->createMock(IGroupManager::class);

	}//end setUp()

	/**
	 * Build a SuspenseAgeingService double returning a fixed worklist size.
	 *
	 * @param int $count The unmatched-item count the ageing reports.
	 * @param int $oldest The oldest days-outstanding the ageing reports.
	 *
	 * @return SuspenseAgeingService&MockObject
	 */
	private function ageingReturning(int $count, int $oldest = 0): SuspenseAgeingService {
		$ageing = $this->createMock(SuspenseAgeingService::class);
		$ageing->method('agedUnmatchedItems')->willReturn(
			[
				'items' => [],
				'count' => $count,
				'oldestDaysOutstanding' => $oldest,
				'totalAmountCents' => 0,
			]
		);
		$ageing->method('hasUnresolvedItems')->willReturn($count > 0);
		return $ageing;
	}//end ageingReturning()

	/**
	 * Build the service over a single seeded PeriodClose record.
	 *
	 * @param array<string,mixed> $record The PeriodClose record the stub returns.
	 * @param SuspenseAgeingService|null $suspenseAgeing Optional suspense ageing double (defaults to an empty worklist).
	 *
	 * @return PeriodCloseService
	 */
	private function buildService(array $record, ?SuspenseAgeingService $suspenseAgeing = null): PeriodCloseService {
		$this->objectService = new class($record) {

			/**
			 * The seeded record.
			 *
			 * @var array<string,mixed>
			 */
			public array $record;

			/**
			 * The last object persisted via saveObject().
			 *
			 * @var array<string,mixed>|null
			 */
			public ?array $saved = null;

			/**
			 * Active schema slug.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $record The seeded record.
			 */
			public function __construct(array $record) {
				$this->record = $record;
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
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return the seeded record when its filters match.
			 *
			 * @param array<string,mixed> $params Query parameters.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$filters = ($params['filters'] ?? []);
				foreach ($filters as $key => $value) {
					if ($key === 'id') {
						// The stub record has no separate id; match on periodId for id lookups.
						if (($this->record['periodId'] ?? null) !== $value) {
							return [];
						}

						continue;
					}

					if (($this->record[$key] ?? null) !== $value) {
						return [];
					}
				}

				return [$this->record];
			}//end findAll()

			/**
			 * Capture the saved object.
			 *
			 * @param array<string,mixed> $object The object.
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object, string $register = '', string $schema = ''): array {
				$this->saved = $object;
				return $object;
			}//end saveObject()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objectService);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new PeriodCloseService(
			appConfig: $appConfig,
			groupManager: $this->groupManager,
			suspenseAgeing: ($suspenseAgeing ?? $this->ageingReturning(0)),
			logger: $this->createMock(LoggerInterface::class),
			objectService: new DuckObjectServiceAdapter($this->objectService),
		);

	}//end buildService()

	/**
	 * Build a PeriodClose fixture.
	 *
	 * @param string $state Lifecycle state.
	 * @param array<int,array<string,mixed>> $checklist Checklist items.
	 *
	 * @return array<string,mixed>
	 */
	private function period(string $state, array $checklist = []): array {
		return [
			'periodId' => '2026-01',
			'administrationId' => 'adm-1',
			'startDate' => '2026-01-01',
			'endDate' => '2026-01-31',
			'fiscalYear' => 2026,
			'state' => $state,
			'closedAt' => null,
			'closedBy' => null,
			'auditLockedAt' => null,
			'auditLockedBy' => null,
			'closeReason' => null,
			'reopenedHistory' => [],
			'taskChecklistItems' => $checklist,
			'aiFlags' => [],
		];

	}//end period()

	/**
	 * Closing a period with mandatory items resolved sets state + stamps (REQ-PC-002).
	 *
	 * @return void
	 */
	public function testClosePeriodTransitionsAndStamps(): void {
		$checklist = [
			['category' => 'ap', 'resolved' => true],
			['category' => 'ar', 'resolved' => true],
		];
		$service = $this->buildService($this->period('closing', $checklist));
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('isInGroup')->willReturn(true);

		$result = $service->closePeriod('2026-01', 'adm-1', 'alice@org.nl');

		self::assertSame('closed', $result['state']);
		self::assertSame('alice@org.nl', $result['closedBy']);
		self::assertNotNull($result['closedAt']);
		self::assertSame('closed', $this->objectService->saved['state']);

	}//end testClosePeriodTransitionsAndStamps()

	/**
	 * Closing is blocked when a mandatory checklist item is unresolved (REQ-PC-002).
	 *
	 * @return void
	 */
	public function testClosePeriodRejectedWhenChecklistIncomplete(): void {
		$checklist = [
			['category' => 'ap', 'resolved' => false],
			['category' => 'ar', 'resolved' => true],
		];
		$service = $this->buildService($this->period('closing', $checklist));
		$this->groupManager->method('isAdmin')->willReturn(true);

		$this->expectException(PeriodCloseException::class);
		try {
			$service->closePeriod('2026-01', 'adm-1', 'alice@org.nl');
		} catch (PeriodCloseException $e) {
			self::assertSame(PeriodCloseService::ERR_VALIDATION, $e->getStatus());
			throw $e;
		}

	}//end testClosePeriodRejectedWhenChecklistIncomplete()

	/**
	 * Closing is BLOCKED while the bank-reconciliation suspense worklist is non-empty
	 * (payment-control-guards REQ-PCG-003) — the failing-path proof.
	 *
	 * @return void
	 */
	public function testClosePeriodBlockedWhenSuspenseNonEmpty(): void {
		$checklist = [
			['category' => 'ap', 'resolved' => true],
			['category' => 'ar', 'resolved' => true],
		];
		// Checklist is clean, but 3 unmatched suspense items remain (oldest 47 days).
		$service = $this->buildService($this->period('closing', $checklist), $this->ageingReturning(3, 47));
		$this->groupManager->method('isAdmin')->willReturn(true);

		$this->expectException(PeriodCloseException::class);
		try {
			$service->closePeriod('2026-01', 'adm-1', 'alice@org.nl');
		} catch (PeriodCloseException $e) {
			self::assertSame(PeriodCloseService::ERR_VALIDATION, $e->getStatus());
			self::assertStringContainsString('unmatched bank/suspense', $e->getMessage());
			self::assertNull($this->objectService->saved, 'The period must NOT be persisted as closed');
			throw $e;
		}

	}//end testClosePeriodBlockedWhenSuspenseNonEmpty()

	/**
	 * Closing succeeds once the suspense worklist is empty (REQ-PCG-003).
	 *
	 * @return void
	 */
	public function testClosePeriodAllowedWhenSuspenseEmpty(): void {
		$checklist = [
			['category' => 'ap', 'resolved' => true],
			['category' => 'ar', 'resolved' => true],
		];
		$service = $this->buildService($this->period('closing', $checklist), $this->ageingReturning(0));
		$this->groupManager->method('isAdmin')->willReturn(true);

		$result = $service->closePeriod('2026-01', 'adm-1', 'alice@org.nl');

		self::assertSame('closed', $result['state']);

	}//end testClosePeriodAllowedWhenSuspenseEmpty()

	/**
	 * A user without the period-closer role cannot close (REQ-PC-008).
	 *
	 * @return void
	 */
	public function testClosePeriodForbiddenWithoutRole(): void {
		$service = $this->buildService($this->period('closing'));
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('isInGroup')->willReturn(false);

		$this->expectException(PeriodCloseException::class);
		try {
			$service->closePeriod('2026-01', 'adm-1', 'bob@org.nl');
		} catch (PeriodCloseException $e) {
			self::assertSame(PeriodCloseService::ERR_FORBIDDEN, $e->getStatus());
			throw $e;
		}

	}//end testClosePeriodForbiddenWithoutRole()

	/**
	 * Reopen appends to reopenedHistory and captures the close reason (REQ-PC-006).
	 *
	 * @return void
	 */
	public function testReopenAppendsHistoryAndReason(): void {
		$record = $this->period('closed');
		$record['closedAt'] = '2026-02-05T17:30:00+01:00';
		$record['closedBy'] = 'alice@org.nl';
		$service = $this->buildService($record);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('isInGroup')->willReturn(true);

		$result = $service->reopenPeriod('2026-01', 'adm-1', 'Posted correction for invoice #123', 'alice@org.nl');

		self::assertSame('open', $result['state']);
		self::assertCount(1, $result['reopenedHistory']);
		$entry = $result['reopenedHistory'][0];
		self::assertSame('2026-02-05T17:30:00+01:00', $entry['closedAt']);
		self::assertSame('alice@org.nl', $entry['closedBy']);
		self::assertSame('Posted correction for invoice #123', $entry['closeReason']);
		self::assertNotEmpty($entry['reopenedAt']);
		// The closedAt/closedBy stamps are cleared on reopen.
		self::assertNull($result['closedAt']);

	}//end testReopenAppendsHistoryAndReason()

	/**
	 * Reopen requires a non-empty close reason (REQ-PC-006).
	 *
	 * @return void
	 */
	public function testReopenRequiresCloseReason(): void {
		$service = $this->buildService($this->period('closed'));
		$this->groupManager->method('isAdmin')->willReturn(true);

		$this->expectException(PeriodCloseException::class);
		try {
			$service->reopenPeriod('2026-01', 'adm-1', '   ', 'alice@org.nl');
		} catch (PeriodCloseException $e) {
			self::assertSame(PeriodCloseService::ERR_VALIDATION, $e->getStatus());
			throw $e;
		}

	}//end testReopenRequiresCloseReason()

	/**
	 * Audit-lock requires the auditor role and the closed state (REQ-PC-002, REQ-PC-008).
	 *
	 * @return void
	 */
	public function testLockForAuditTransitionsAndStamps(): void {
		$record = $this->period('closed');
		$record['closedAt'] = '2026-02-05T17:30:00+01:00';
		$record['closedBy'] = 'alice@org.nl';
		$service = $this->buildService($record);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('isInGroup')->willReturn(true);

		$result = $service->lockForAudit('2026-01', 'adm-1', 'auditor@org.nl');

		self::assertSame('audit-locked', $result['state']);
		self::assertSame('auditor@org.nl', $result['auditLockedBy']);
		self::assertNotNull($result['auditLockedAt']);

	}//end testLockForAuditTransitionsAndStamps()

	/**
	 * Audit-lock from a non-closed state is rejected as invalid (REQ-PC-002).
	 *
	 * @return void
	 */
	public function testLockForAuditRejectedWhenNotClosed(): void {
		$service = $this->buildService($this->period('open'));
		$this->groupManager->method('isAdmin')->willReturn(true);

		$this->expectException(PeriodCloseException::class);
		try {
			$service->lockForAudit('2026-01', 'adm-1', 'auditor@org.nl');
		} catch (PeriodCloseException $e) {
			self::assertSame(PeriodCloseService::ERR_INVALID_STATE, $e->getStatus());
			throw $e;
		}

	}//end testLockForAuditRejectedWhenNotClosed()

	/**
	 * A missing period yields a not-found error scoped to the administration (REQ-PC-008).
	 *
	 * @return void
	 */
	public function testNotFoundForUnknownPeriod(): void {
		$service = $this->buildService($this->period('open'));
		$this->groupManager->method('isAdmin')->willReturn(true);

		$this->expectException(PeriodCloseException::class);
		try {
			$service->startClose('9999-99', 'adm-1', 'alice@org.nl');
		} catch (PeriodCloseException $e) {
			self::assertSame(PeriodCloseService::ERR_NOT_FOUND, $e->getStatus());
			throw $e;
		}

	}//end testNotFoundForUnknownPeriod()

	/**
	 * A Nextcloud admin satisfies every role gate (REQ-PC-008).
	 *
	 * @return void
	 */
	public function testAdminSatisfiesRoleGate(): void {
		$service = $this->buildService($this->period('open'));
		$this->groupManager->method('isAdmin')->willReturn(true);

		$result = $service->startClose('2026-01', 'adm-1', 'admin');
		self::assertSame('closing', $result['state']);

	}//end testAdminSatisfiesRoleGate()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
