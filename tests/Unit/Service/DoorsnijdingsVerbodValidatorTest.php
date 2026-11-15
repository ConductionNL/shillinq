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
use OCA\Shillinq\Service\InnovatieboxAuditEventLogger;
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

	/**
	 * Pin the findings over account aliases, cost-centre matching, flags,
	 * missing accounts and message formatting.
	 *
	 * @return void
	 */
	public function testDetectDuplicatesIsExact(): void {
		$glLines = [
			['accountNumber' => '4000', 'costCentre' => 'KP1'],
			['generalLedgerAccount' => '4100'],
			['accountNumber' => '', 'costCentre' => 'KP9'],
			['costCentre' => 'KP9'],
		];
		$allocations = [
			['generalLedgerAccount' => '4000', 'costCentre' => 'KP1', 'amount' => 1234567.89, 'excluding_in_profitdetermination' => true],
			['generalLedgerAccount' => '4000', 'costCentre' => 'KP2', 'amount' => 1, 'excluding_in_profitdetermination' => true],
			['generalLedgerAccount' => '4100', 'amount' => '99.5', 'excluding_in_profitdetermination' => true],
			['generalLedgerAccount' => '4100', 'amount' => 5, 'excluding_in_profitdetermination' => 'yes'],
			['generalLedgerAccount' => '', 'costCentre' => 'KP9', 'excluding_in_profitdetermination' => true],
			['costCentre' => 'KP9', 'excluding_in_profitdetermination' => true],
		];

		self::assertSame(
			[
				[
					'generalLedgerAccount' => '4000',
					'costCentre' => 'KP1',
					'amount' => 1234567.89,
					'message' => 'EUR 1.234.568 (account 4000, kostenplaats KP1) appears in both innovatiebox allocation AND GL regular deduction. Resolve conflict before year-end close.',
				],
				[
					'generalLedgerAccount' => '4100',
					'costCentre' => '',
					'amount' => 99.5,
					'message' => 'EUR 100 (account 4100, kostenplaats -) appears in both innovatiebox allocation AND GL regular deduction. Resolve conflict before year-end close.',
				],
			],
			$this->val->detectDuplicates($allocations, $glLines)
		);

	}//end testDetectDuplicatesIsExact()

	/**
	 * Pin the queries, the result and the audit event of a validation run,
	 * with and without findings, and without a logger.
	 *
	 * @return void
	 */
	public function testValidationQueriesResultAndAuditEventAreExact(): void {
		$queries = [];
		$events = [];
		$store = $this->createMock(ObjectServiceInterface::class);
		$state = ['schema' => ''];
		$store->method('setRegister')->willReturnSelf();
		$store->method('setSchema')->willReturnCallback(
			function (string $schema) use (&$state, $store): ObjectServiceInterface {
				$state['schema'] = $schema;
				return $store;
			}
		);
		$store->method('findAll')->willReturnCallback(
			function (array $config) use (&$state, &$queries): array {
				$queries[] = [$state['schema'], $config];
				if ($state['schema'] === 'GLLine') {
					return [['accountNumber' => '4000', 'costCentre' => 'KP1']];
				}
				return ($config['filters']['financialYear'] === 2026)
					? [['generalLedgerAccount' => '4000', 'costCentre' => 'KP1', 'amount' => 10, 'excluding_in_profitdetermination' => true], ['generalLedgerAccount' => '4000', 'costCentre' => 'KP1', 'amount' => '2.5', 'excluding_in_profitdetermination' => true]]
					: [];
			}
		);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$logger = $this->createMock(InnovatieboxAuditEventLogger::class);
		$logger->method('record')->willReturnCallback(
			function (array $options) use (&$events): bool {
				$events[] = $options;
				return true;
			}
		);
		$validator = new DoorsnijdingsVerbodValidator($config, $store, $logger);

		$dirty = $validator->validateNoDuplication('adm', 2026);
		$clean = $validator->validateNoDuplication('adm', 2025);
		$silent = (new DoorsnijdingsVerbodValidator($config, $store))->validateNoDuplication('adm', 2026);

		self::assertSame([true, 2, false, 0], [$dirty['blocking'], $dirty['total'], $clean['blocking'], $clean['total']]);
		self::assertSame($dirty, $silent);
		self::assertSame(
			[
				['IBExpenseAllocation', ['filters' => ['administrationId' => 'adm', 'financialYear' => 2026, 'excluding_in_profitdetermination' => true]]],
				['GLLine', ['filters' => ['administrationId' => 'adm', 'financialYear' => 2026]]],
			],
			array_slice($queries, 0, 2)
		);
		self::assertSame(
			[
				[
					'event_type' => InnovatieboxAuditEventLogger::EVENT_DOORSNIJDINGSVERBOD_CHECK_RUN,
					'administrationId' => 'adm',
					'financialYear' => 2026,
					'reason' => 'doorsnijdingsverbod_duplicate',
					'details' => ['findings' => $dirty['findings'], 'total_pairs' => 2, 'total_bedrag' => 12.5, 'blocking' => true],
				],
				[
					'event_type' => InnovatieboxAuditEventLogger::EVENT_DOORSNIJDINGSVERBOD_CHECK_RUN,
					'administrationId' => 'adm',
					'financialYear' => 2025,
					'reason' => null,
					'details' => ['findings' => [], 'total_pairs' => 0, 'total_bedrag' => 0.0, 'blocking' => false],
				],
			],
			$events
		);

	}//end testValidationQueriesResultAndAuditEventAreExact()
}//end class
