<?php

/**
 * Unit tests for ConsolidationGuard's lookups, period close and edge cases.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\ConsolidationGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * ConsolidationGuardTest pins the rules on a handed-over object. This file
 * pins what happens when only an id is handed over (the object is read from
 * the register; a missing one denies), the period close (every elimination
 * entry approved, and at least one), the method/ownership boundaries, and
 * that every check fails closed when the read throws.
 *
 * @covers \OCA\Shillinq\Lifecycle\ConsolidationGuard
 * @uses \OCA\Shillinq\Lifecycle\GuardObjectLookup
 * @uses \OCA\Shillinq\Lifecycle\GuardSubject
 */
final class ConsolidationGuardLookupTest extends TestCase {

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The schemas the guard read from, in order.
	 *
	 * @var array<int, string>
	 */
	private array $schemas = [];

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnCallback(
			function (string $schema): ObjectServiceInterface {
				$this->schemas[] = $schema;

				return $this->objects;
			}
		);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The guard.
	 *
	 * @return ConsolidationGuard The guard.
	 */
	private function guard(): ConsolidationGuard {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');

		return new ConsolidationGuard($config, $this->logger, $this->objects);
	}//end guard()

	/**
	 * The register answers these rows for every read.
	 *
	 * @param array<int, mixed> $rows The rows.
	 *
	 * @return void
	 */
	private function registerHolds(array $rows): void {
		$this->objects->method('findAll')->willReturn($rows);
	}//end registerHolds()

	/**
	 * Given only an id, each check reads its own schema and judges what it finds.
	 *
	 * @return void
	 */
	public function testAnIdIsReadFromTheChecksSchema(): void {
		$this->objects->expects($this->exactly(2))
			->method('findAll')
			->with(['filters' => ['id' => 'x-1']])
			->willReturnOnConsecutiveCalls(
				['not a row', ['parentAdministrationId' => 'adm-1']],
				[['consolidationMethod' => 'integral', 'ownershipPercentage' => 80]]
			);
		$guard = $this->guard();

		$this->assertTrue($guard->canActivateGroup('x-1'));
		$this->assertTrue($guard->canActivateEntity('x-1'));
		$this->assertSame(['ConsolidationGroup', 'GroupEntity'], $this->schemas);
	}//end testAnIdIsReadFromTheChecksSchema()

	/**
	 * A missing object, or a blank id, denies every check.
	 *
	 * @return void
	 */
	public function testAMissingObjectDeniesEveryCheck(): void {
		$this->registerHolds([]);
		$guard = $this->guard();

		foreach (['canActivateGroup', 'canActivateEntity', 'canStartElimination', 'canSubmitForReview', 'canApproveElimination', 'canRejectElimination', 'canFinalizeBalance', 'canFinalizeIncomeStatement'] as $check) {
			$this->assertFalse($guard->$check('x-1'), $check . ' on a missing object');
			$this->assertFalse($guard->$check(''), $check . ' on a blank id');
		}
	}//end testAMissingObjectDeniesEveryCheck()

	/**
	 * A read that throws denies every check and logs which one.
	 *
	 * @return void
	 */
	public function testEveryCheckFailsClosed(): void {
		$this->objects->method('findAll')->willThrowException(new RuntimeException('register offline'));
		$messages = [];
		$this->logger->method('error')->willReturnCallback(
			function (string $message, array $context) use (&$messages): void {
				$messages[] = $message;
				$this->assertSame('register offline', $context['exception']);
				$this->assertContains('x-1', $context);
			}
		);
		$guard = $this->guard();

		$checks = [
			'canActivateGroup' => 'group activate',
			'canActivateEntity' => 'entity activate',
			'canStartElimination' => 'start-elimination',
			'canSubmitForReview' => 'submit-for-review',
			'canClosePeriod' => 'close-period',
			'canApproveElimination' => 'approve-elimination',
			'canRejectElimination' => 'reject-elimination',
			'canFinalizeBalance' => 'finalize-balance',
			'canFinalizeIncomeStatement' => 'finalize-income-statement',
		];
		foreach (array_keys($checks) as $check) {
			$this->assertFalse($guard->$check('x-1'), $check);
		}

		$expected = array_map(
			static fn (string $what): string => 'ConsolidationGuard: ' . $what . ' check failed — denying transition (fail-closed)',
			array_values($checks)
		);
		$this->assertSame($expected, $messages);
	}//end testEveryCheckFailsClosed()

	/**
	 * A period closes only when it has entries and every one is approved.
	 *
	 * @return void
	 */
	public function testAPeriodClosesOnlyWithEveryEntryApproved(): void {
		$this->objects->expects($this->exactly(4))
			->method('findAll')
			->with(['filters' => ['consolidationPeriodId' => 'per-1']])
			->willReturnOnConsecutiveCalls(
				[['reviewStatus' => 'approved'], ['reviewStatus' => 'approved']],
				[],
				[['reviewStatus' => 'approved'], ['reviewStatus' => 'pending']],
				[['reviewStatus' => 'approved'], 'not a row']
			);
		$guard = $this->guard();

		$this->assertTrue($guard->canClosePeriod('per-1'));
		$this->assertFalse($guard->canClosePeriod('per-1'), 'no entries');
		$this->assertFalse($guard->canClosePeriod('per-1'), 'a pending entry');
		$this->assertFalse($guard->canClosePeriod('per-1'), 'an unreadable entry');
		$this->assertFalse($guard->canClosePeriod(''), 'a blank period is never read');
		$this->assertSame(['EliminationEntry', 'EliminationEntry', 'EliminationEntry', 'EliminationEntry'], $this->schemas);
	}//end testAPeriodClosesOnlyWithEveryEntryApproved()

	/**
	 * The method/ownership rules at their boundaries.
	 *
	 * @return void
	 */
	public function testMethodAndOwnershipBoundaries(): void {
		$guard = $this->guard();
		$cases = [
			['integral', 50.0, false],
			['integral', 50.01, true],
			['proportional', 50.0, true],
			['proportional', 0.0, false],
			['equity', 49.99, true],
			['equity', 50.0, false],
			['integral', 100.01, false],
			['integral', -1.0, false],
			['', 60.0, false],
			['fair-value', 60.0, false],
		];
		foreach ($cases as [$method, $ownership, $allowed]) {
			$this->assertSame(
				$allowed,
				$guard->canActivateEntity('e-1', ['consolidationMethod' => $method, 'ownershipPercentage' => $ownership]),
				$method . ' at ' . $ownership
			);
		}
	}//end testMethodAndOwnershipBoundaries()

	/**
	 * Review submission refuses a non-list of mismatches but ignores non-row entries.
	 *
	 * @return void
	 */
	public function testSubmissionMismatchShapes(): void {
		$guard = $this->guard();

		$this->assertFalse($guard->canSubmitForReview('p-1', ['totalEliminationCount' => 2, 'mismatches' => 'none']));
		$this->assertTrue($guard->canSubmitForReview('p-1', ['totalEliminationCount' => 2, 'mismatches' => ['note', ['status' => 'accepted']]]));
		$this->assertFalse($guard->canSubmitForReview('p-1', ['totalEliminationCount' => 2, 'mismatches' => [[]]]), 'a mismatch without status is pending');
	}//end testSubmissionMismatchShapes()

	/**
	 * Elimination lines must be a non-empty list of rows to balance.
	 *
	 * @return void
	 */
	public function testEliminationLinesMustBeRows(): void {
		$guard = $this->guard();

		$this->assertFalse($guard->canApproveElimination('e-1', ['reviewedBy' => 'r', 'lines' => []]));
		$this->assertFalse($guard->canApproveElimination('e-1', ['reviewedBy' => 'r', 'lines' => 'x']));
		$this->assertFalse($guard->canApproveElimination('e-1', ['reviewedBy' => 'r', 'lines' => [['debit' => 5], 'x']]));
		$this->assertTrue($guard->canApproveElimination('e-1', ['reviewedBy' => 'r', 'lines' => [['debit' => 5], ['credit' => 5.005]]]));
	}//end testEliminationLinesMustBeRows()
}//end class
