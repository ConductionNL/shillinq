<?php

/**
 * Unit tests for ReconciliationResolutionService.
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
 * @spec openspec/changes/bookkeeping-reconciliation-reports/specs/bookkeeping-reconciliation-reports/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Shillinq\Service\ReconciliationResolutionService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Behaviour holder behind the ObjectServiceInterface mock — a per-schema
 * find() / updateObject() map plus a recording of every update issued.
 *
 * It returns ObjectEntityInterface values, not arrays, because that is what
 * the real contract returns; the service normalises them through its own
 * toArray(). Before ADR-083 this fake was handed to the service through an
 * untyped `ContainerInterface::get()`, so nothing enforced the return shape
 * and the fake could answer with a plain array the real service never emits.
 *
 * @phpstan-type FindMap array<string, array<string, array<string,mixed>|\Throwable|null>>
 */
final class FakeObjectService {

	/**
	 * Map: schema => id => row or Throwable.
	 *
	 * @var array<string, array<string, array<string,mixed>|\Throwable|null>>
	 */
	private array $finds = [];

	/**
	 * Recorded updates.
	 *
	 * @var array<int, array{schema:string,id:string,payload:array<string,mixed>}>
	 */
	public array $updates = [];

	/**
	 * Current schema (mutated by setSchema()).
	 */
	private string $schema = '';

	/**
	 * @param array<string, array<string, array<string,mixed>|\Throwable|null>> $finds Find map.
	 */
	public function __construct(array $finds) {
		$this->finds = $finds;

	}//end __construct()

	public function setRegister(string $r): self {
		return $this;
	}//end setRegister()

	public function setSchema(string $s): self {
		$this->schema = $s;
		return $this;
	}//end setSchema()

	/**
	 * @return ObjectEntityInterface|null
	 */
	public function find(string $id): ?ObjectEntityInterface {
		$rec = $this->finds[$this->schema][$id] ?? null;
		if ($rec instanceof \Throwable) {
			throw $rec;
		}

		if (is_array($rec) === false) {
			return null;
		}

		return (new ObjectEntity())->setObject($rec);
	}//end find()

	/**
	 * @param array<string,mixed> $payload Update payload.
	 *
	 * @return ObjectEntityInterface
	 */
	public function updateObject(string $id, array $payload): ObjectEntityInterface {
		$this->updates[] = ['schema' => $this->schema, 'id' => $id, 'payload' => $payload];

		$existing = $this->finds[$this->schema][$id] ?? [];
		if (is_array($existing) === false) {
			$existing = [];
		}

		return (new ObjectEntity())->setObject(array_merge(['id' => $id], $existing, $payload));
	}//end updateObject()
}//end class

/**
 * Verifies REQ-REC-004 resolution lifecycle:
 * - parent lock guard (closed/cancelled rejected)
 * - 404 on missing parent or match
 * - IDOR guard (match must belong to parent)
 * - successful update + audit-log entry
 *
 * @spec openspec/changes/bookkeeping-reconciliation-reports/specs/bookkeeping-reconciliation-reports/spec.md (REQ-REC-004)
 */
final class ReconciliationResolutionServiceTest extends TestCase {

	/**
	 * Mock app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->appConfig->method('getValueString')->willReturn('shillinq');

	}//end setUp()

	/**
	 * Build the subject with an injected ObjectServiceInterface (ADR-083 rule 1)
	 * that delegates to the FakeObjectService behaviour holder.
	 *
	 * @param FakeObjectService $fake Stand-in behaviour for OR ObjectService.
	 *
	 * @return ReconciliationResolutionService
	 */
	private function svc(FakeObjectService $fake): ReconciliationResolutionService {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnCallback(
			static function (string|int $schema) use ($fake, $objectService): ObjectServiceInterface {
				$fake->setSchema((string)$schema);
				return $objectService;
			}
		);
		$objectService->method('find')->willReturnCallback(
			static fn (int|string $id): ?ObjectEntityInterface => $fake->find((string)$id)
		);
		$objectService->method('updateObject')->willReturnCallback(
			static fn (string $objectId, array $data): ObjectEntityInterface => $fake->updateObject($objectId, $data)
		);

		return new ReconciliationResolutionService($this->appConfig, $this->logger, $objectService);
	}//end svc()

	/**
	 * Happy path: open recon + valid match → match updated with the
	 * resolution classification and an info log line is emitted.
	 *
	 * @return void
	 */
	public function testResolveMatchUpdatesAndLogs(): void {
		$fake = new FakeObjectService([
			'BankReconciliation' => [
				'recon-1' => ['reconciliationStatus' => 'open'],
			],
			'ReconciliationMatch' => [
				'match-1' => ['reconId' => 'recon-1', 'resolutionStatus' => 'pending'],
			],
		]);

		$this->logger->expects(self::once())->method('info')
			->with(
				self::stringContains('REQ-REC-004 resolution applied'),
				self::callback(static function (array $ctx): bool {
					return $ctx['reconId'] === 'recon-1'
						&& $ctx['matchId'] === 'match-1'
						&& $ctx['resolutionStatus'] === 'matched'
						&& $ctx['actor'] === 'alice';
				})
			);

		$result = $this->svc($fake)->resolveMatch('recon-1', 'match-1', 'matched', 'cleared on bank statement', 'alice');

		self::assertSame('matched', $result['resolutionStatus']);
		self::assertSame('cleared on bank statement', $result['resolutionReason']);
		self::assertArrayHasKey('matchedAt', $result);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $result['matchedAt']);

		// Exactly one update issued, against ReconciliationMatch.
		self::assertCount(1, $fake->updates);
		self::assertSame('ReconciliationMatch', $fake->updates[0]['schema']);
		self::assertSame('match-1', $fake->updates[0]['id']);
		self::assertSame('matched', $fake->updates[0]['payload']['resolutionStatus']);

	}//end testResolveMatchUpdatesAndLogs()

	/**
	 * Closed parent is rejected with DomainException (REQ-REC-003 lock).
	 *
	 * @return void
	 */
	public function testClosedParentReconciliationIsRejected(): void {
		$fake = new FakeObjectService([
			'BankReconciliation' => [
				'recon-closed' => ['reconciliationStatus' => 'closed'],
			],
			'ReconciliationMatch' => [],
		]);

		$this->expectException(\DomainException::class);
		$this->expectExceptionMessage('closed');

		$this->svc($fake)->resolveMatch('recon-closed', 'm', 'matched', 'r', 'a');

	}//end testClosedParentReconciliationIsRejected()

	/**
	 * Cancelled parent is rejected with DomainException.
	 *
	 * @return void
	 */
	public function testCancelledParentReconciliationIsRejected(): void {
		$fake = new FakeObjectService([
			'BankReconciliation' => [
				'recon-cancelled' => ['reconciliationStatus' => 'cancelled'],
			],
			'ReconciliationMatch' => [],
		]);

		$this->expectException(\DomainException::class);
		$this->expectExceptionMessage('cancelled');

		$this->svc($fake)->resolveMatch('recon-cancelled', 'm', 'matched', 'r', 'a');

	}//end testCancelledParentReconciliationIsRejected()

	/**
	 * Missing parent → OutOfBoundsException ("not found").
	 *
	 * @return void
	 */
	public function testMissingParentThrowsOutOfBounds(): void {
		$fake = new FakeObjectService([
			'BankReconciliation' => ['recon-1' => null],
			'ReconciliationMatch' => [],
		]);

		$this->expectException(\OutOfBoundsException::class);
		$this->expectExceptionMessage('reconciliation recon-1 not found');

		$this->svc($fake)->resolveMatch('recon-1', 'm', 'matched', 'r', 'a');

	}//end testMissingParentThrowsOutOfBounds()

	/**
	 * Missing match → OutOfBoundsException.
	 *
	 * @return void
	 */
	public function testMissingMatchThrowsOutOfBounds(): void {
		$fake = new FakeObjectService([
			'BankReconciliation' => ['recon-1' => ['reconciliationStatus' => 'open']],
			'ReconciliationMatch' => ['match-missing' => null],
		]);

		$this->expectException(\OutOfBoundsException::class);
		$this->expectExceptionMessage('match match-missing not found');

		$this->svc($fake)->resolveMatch('recon-1', 'match-missing', 'matched', 'r', 'a');

	}//end testMissingMatchThrowsOutOfBounds()

	/**
	 * IDOR guard: match's reconId differs from URL recon id → reject.
	 *
	 * @return void
	 */
	public function testMatchBelongingToDifferentReconciliationIsRejected(): void {
		$fake = new FakeObjectService([
			'BankReconciliation' => [
				'recon-1' => ['reconciliationStatus' => 'open'],
			],
			'ReconciliationMatch' => [
				'match-9' => ['reconId' => 'recon-99'],
			],
		]);

		$this->expectException(\OutOfBoundsException::class);
		$this->expectExceptionMessage('does not belong to reconciliation');

		$this->svc($fake)->resolveMatch('recon-1', 'match-9', 'matched', 'r', 'a');

	}//end testMatchBelongingToDifferentReconciliationIsRejected()

	/**
	 * Match with no reconId (legacy seed) is allowed through — the update
	 * itself stamps the reconId, closing the orphan.
	 *
	 * @return void
	 */
	public function testMatchWithoutReconIdAdoptsParentOnUpdate(): void {
		$fake = new FakeObjectService([
			'BankReconciliation' => [
				'recon-1' => ['reconciliationStatus' => 'open'],
			],
			'ReconciliationMatch' => [
				'match-orphan' => ['resolutionStatus' => 'pending'],
			],
		]);

		$result = $this->svc($fake)->resolveMatch('recon-1', 'match-orphan', 'timing', 'awaits clearing', 'bob');

		self::assertSame('recon-1', $fake->updates[0]['payload']['reconId']);
		self::assertSame('timing', $result['resolutionStatus']);

	}//end testMatchWithoutReconIdAdoptsParentOnUpdate()

	/**
	 * A find() that throws is rethrown as OutOfBoundsException (chained).
	 *
	 * @return void
	 */
	public function testFindThrowableIsTranslatedToOutOfBounds(): void {
		$fake = new FakeObjectService([
			'BankReconciliation' => [
				'recon-1' => new \RuntimeException('OR down'),
			],
			'ReconciliationMatch' => [],
		]);

		$this->expectException(\OutOfBoundsException::class);
		$this->expectExceptionMessage('reconciliation recon-1 not found');

		$this->svc($fake)->resolveMatch('recon-1', 'm', 'matched', 'r', 'a');

	}//end testFindThrowableIsTranslatedToOutOfBounds()

}//end class
