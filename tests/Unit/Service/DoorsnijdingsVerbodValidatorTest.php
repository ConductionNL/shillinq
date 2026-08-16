<?php

/**
 * Unit tests for DoorsnijdingsVerbodValidator.
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
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\DoorsnijdingsVerbodValidator;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests the doorsnijdingsverbod duplication detection (REQ-IBA-004).
 *
 * Exercises the pure detectDuplicates() step with plain arrays — no OpenRegister
 * dependency is touched, mirroring the no-mock-fixes rule (real behaviour).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class DoorsnijdingsVerbodValidatorTest extends TestCase {

	/**
	 * The validator under test.
	 *
	 * @var DoorsnijdingsVerbodValidator
	 */
	private DoorsnijdingsVerbodValidator $val;

	/**
	 * Set up test fixtures with mocked DI (unused by detectDuplicates).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->val = new DoorsnijdingsVerbodValidator(
			$this->createMock(IAppConfig::class),
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

	}//end setUp()

	/**
	 * A 60k exclusive allocation on account 4010 that also appears in the GL is
	 * flagged as a duplicate (REQ-IBA-004).
	 *
	 * @return void
	 */
	public function testFlagsDuplicateAccountKostenplaatsPair(): void {
		$allocations = [
			[
				'generalLedgerAccount' => '4010',
				'costCentre' => 'rd-team-1',
				'amount' => 60000.0,
				'excluding_in_profitdetermination' => true,
			],
		];
		$glLines = [
			['accountNumber' => '4010', 'costCentre' => 'rd-team-1', 'amount' => 60000.0],
		];

		$findings = $this->val->detectDuplicates($allocations, $glLines);
		self::assertCount(1, $findings);
		self::assertSame('4010', $findings[0]['generalLedgerAccount']);
		self::assertSame(60000.0, $findings[0]['amount']);
		self::assertStringContainsString('year-end close', $findings[0]['message']);

	}//end testFlagsDuplicateAccountKostenplaatsPair()

	/**
	 * A clean allocation with no GL duplicate produces no findings (REQ-IBA-004).
	 *
	 * @return void
	 */
	public function testCleanCaseHasNoFindings(): void {
		$allocations = [
			[
				'generalLedgerAccount' => '4010',
				'costCentre' => 'rd-team-1',
				'amount' => 60000.0,
				'excluding_in_profitdetermination' => true,
			],
		];
		$glLines = [
			['accountNumber' => '4100', 'costCentre' => 'overige', 'amount' => 5000.0],
		];

		self::assertSame([], $this->val->detectDuplicates($allocations, $glLines));

	}//end testCleanCaseHasNoFindings()

	/**
	 * A non-exclusive allocation is never flagged even on a matching pair.
	 *
	 * @return void
	 */
	public function testNonExclusiveAllocationIsIgnored(): void {
		$allocations = [
			[
				'generalLedgerAccount' => '4010',
				'costCentre' => 'rd-team-1',
				'amount' => 60000.0,
				'excluding_in_profitdetermination' => false,
			],
		];
		$glLines = [['accountNumber' => '4010', 'costCentre' => 'rd-team-1']];

		self::assertSame([], $this->val->detectDuplicates($allocations, $glLines));

	}//end testNonExclusiveAllocationIsIgnored()

	/**
	 * A different kostenplaats on the same account is not a duplicate.
	 *
	 * @return void
	 */
	public function testDifferentKostenplaatsIsNotDuplicate(): void {
		$allocations = [
			[
				'generalLedgerAccount' => '4010',
				'costCentre' => 'rd-team-1',
				'amount' => 60000.0,
				'excluding_in_profitdetermination' => true,
			],
		];
		$glLines = [['accountNumber' => '4010', 'costCentre' => 'sales-team']];

		self::assertSame([], $this->val->detectDuplicates($allocations, $glLines));

	}//end testDifferentKostenplaatsIsNotDuplicate()
}//end class
