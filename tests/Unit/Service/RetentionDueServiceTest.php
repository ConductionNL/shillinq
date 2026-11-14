<?php

/**
 * Unit tests for the list of records whose retention period has ended.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @spec openspec/changes/ledger-retention-due-records/tasks.md#1-retention-end-date
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\Relation\RelationRecords;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Service\RetentionDueService;
use PHPUnit\Framework\TestCase;

/**
 * REQ-RET-015: a record is due when its reference date plus its rule's
 * period lies before today, and never while it is under a legal hold.
 *
 * @covers \OCA\Shillinq\Service\RetentionDueService
 *
 * @spec openspec/changes/ledger-retention-due-records/specs/bookkeeping-archiefwet-retention/spec.md#requirement-a-list-shows-the-records-whose-retention-period-has-ended-req-ret-015
 */
final class RetentionDueServiceTest extends TestCase {

	/**
	 * Rule 5.1.2 as seeded: seven years, destroy.
	 */
	private const RULE = ['selectionListCode' => '5.1.2', 'retentionYears' => 7, 'disposition' => 'destroy'];

	/**
	 * The day of the closing round.
	 *
	 * @return DateTimeImmutable 12 November 2026.
	 */
	private static function today(): DateTimeImmutable {
		return new DateTimeImmutable('2026-11-12T10:00:00+01:00');
	}//end today()

	/**
	 * A transaction posted on 15 March 2018 is due with end date 15 March 2025 and disposition destroy.
	 *
	 * @return void
	 */
	public function testAPostedTransactionFrom2018IsDue(): void {
		$due = RetentionDueService::due(
			records: [['id' => 'tx-2018', 'postingDate' => '2018-03-15']],
			schema: 'GLTransaction',
			dateField: 'postingDate',
			rule: self::RULE,
			heldIds: [],
			today: self::today()
		);

		$this->assertSame(
			[['register' => 'GLTransaction', 'recordId' => 'tx-2018', 'ruleCode' => '5.1.2', 'endDate' => '2025-03-15', 'disposition' => 'destroy']],
			$due
		);
	}//end testAPostedTransactionFrom2018IsDue()

	/**
	 * A transaction posted on 1 February 2022 stays off the list, and so does one whose end date is today.
	 *
	 * @return void
	 */
	public function testARecentTransactionStaysOffTheList(): void {
		$due = RetentionDueService::due(
			records: [['id' => 'tx-2022', 'postingDate' => '2022-02-01'], ['id' => 'tx-today', 'postingDate' => '2019-11-12']],
			schema: 'GLTransaction',
			dateField: 'postingDate',
			rule: self::RULE,
			heldIds: [],
			today: self::today()
		);

		$this->assertSame([], $due);
	}//end testARecentTransactionStaysOffTheList()

	/**
	 * A record under a legal hold stays off the list, whether the hold is on the record or on its retention document.
	 *
	 * @return void
	 */
	public function testARecordUnderALegalHoldStaysOffTheList(): void {
		$due = RetentionDueService::due(
			records: [
				['id' => 'inv-held', 'invoiceDate' => '2017-01-10', 'legalHold' => true],
				['id' => 'inv-doc-held', 'invoiceDate' => '2017-01-10'],
				['id' => 'inv-free', 'invoiceDate' => '2017-01-10'],
			],
			schema: 'APInvoice',
			dateField: 'invoiceDate',
			rule: self::RULE,
			heldIds: ['inv-doc-held'],
			today: self::today()
		);

		$this->assertSame(['inv-free'], array_column($due, 'recordId'));
	}//end testARecordUnderALegalHoldStaysOffTheList()

	/**
	 * An unreadable date, a missing id, a rule without whole years and a keep-forever rule list nothing; a longer operator period wins.
	 *
	 * @return void
	 */
	public function testWhatCannotBeComputedIsNeverDue(): void {
		$old = [['id' => 'a', 'postingDate' => '2010-01-01'], ['id' => 'b', 'postingDate' => 'not a date'], ['postingDate' => '2010-01-01'], ['id' => 'c']];

		$listed = RetentionDueService::due($old, 'GLTransaction', 'postingDate', self::RULE, [], self::today());
		$this->assertSame(['a'], array_column($listed, 'recordId'));
		$byTrigger = ['retentionTrigger' => '10 years after x', 'disposition' => 'destroy'];
		$forever   = ['retentionYears' => 7, 'disposition' => 'keep_indefinite'];
		$this->assertSame([], RetentionDueService::due($old, 'GLTransaction', 'postingDate', $byTrigger, [], self::today()));
		$this->assertSame([], RetentionDueService::due($old, 'GLTransaction', 'postingDate', $forever, [], self::today()));

		$extended = RetentionDueService::due(
			[['id' => 'tx-2018', 'postingDate' => '2018-03-15']],
			'GLTransaction',
			'postingDate',
			self::RULE + ['customRetentionYears' => 10],
			[],
			self::today()
		);
		$this->assertSame([], $extended, 'an operator period of ten years keeps a 2018 posting until 2028');
	}//end testWhatCannotBeComputedIsNeverDue()

	/**
	 * The administration's own override wins over the statutory rule; another administration's override is ignored.
	 *
	 * @return void
	 */
	public function testTheAdministrationsOwnRuleWins(): void {
		$statutory = self::RULE;
		$own       = self::RULE + ['administrationId' => 'adm-1', 'customRetentionYears' => 10];
		$other     = self::RULE + ['administrationId' => 'adm-2', 'customRetentionYears' => 20];

		$this->assertSame($own, RetentionDueService::ruleFor([$own, $statutory, $other], '5.1.2', 'adm-1'));
		$this->assertSame($statutory, RetentionDueService::ruleFor([$other, $statutory], '5.1.2', 'adm-1'));
		$this->assertNull(RetentionDueService::ruleFor([$other], '5.1.2', 'adm-1'));
		$this->assertNull(RetentionDueService::ruleFor([$statutory], '3.5.1', 'adm-1'));
	}//end testTheAdministrationsOwnRuleWins()

	/**
	 * The list reads each bound schema and the holds for the administration only, and sorts by end date.
	 *
	 * @return void
	 */
	public function testTheListReadsTheAdministrationsRecordsAndHolds(): void {
		$asked   = [];
		$records = $this->createMock(RelationRecords::class);
		$records->method('rows')->willReturnCallback(
			static function (string $schema, string $administrationId, array $filters = []) use (&$asked): array {
				$asked[] = [$schema, $administrationId, $filters];
				return match ($schema) {
					'GLTransaction'     => [['id' => 'tx-2018', 'postingDate' => '2018-03-15'], ['id' => 'tx-held', 'postingDate' => '2016-01-01']],
					'APInvoice'         => [['id' => 'inv-2017', 'invoiceDate' => '2017-06-30']],
					'DocumentRetention' => [['documentId' => 'tx-held', 'legalHold' => true]],
					default             => [],
				};
			}
		);
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->with('shillinq')->willReturnSelf();
		$objects->method('setSchema')->with('RetentionRule')->willReturnSelf();
		$objects->method('findAll')->with(['limit' => 200, 'filters' => ['selectionListCode' => '5.1.2']])->willReturn([self::RULE]);
		$settings = $this->createMock(RegisterLocation::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		$due = (new RetentionDueService($records, $objects, $settings))->dueFor('adm-1', self::today());

		$this->assertSame(
			[
				['register' => 'APInvoice', 'recordId' => 'inv-2017', 'ruleCode' => '5.1.2', 'endDate' => '2024-06-30', 'disposition' => 'destroy'],
				['register' => 'GLTransaction', 'recordId' => 'tx-2018', 'ruleCode' => '5.1.2', 'endDate' => '2025-03-15', 'disposition' => 'destroy'],
			],
			$due
		);
		$this->assertSame(
			[['DocumentRetention', 'adm-1', ['legalHold' => true]], ['GLTransaction', 'adm-1', []], ['APInvoice', 'adm-1', []]],
			$asked
		);
		$this->assertSame([], (new RetentionDueService($records, $objects, $settings))->dueFor('', self::today()));
	}//end testTheListReadsTheAdministrationsRecordsAndHolds()
}//end class
