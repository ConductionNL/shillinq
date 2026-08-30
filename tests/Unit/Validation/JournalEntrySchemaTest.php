<?php

/**
 * Unit tests for the JournalEntry register fragment (ADR-037).
 *
 * Locks the declarative contract of the bookkeeping-journal-entries spec:
 * the schema lives in a register.d fragment (not the monolith), declares the
 * REQ-JE-002 field set, a closed REQ-JE-003 journalType enum, and the
 * REQ-JE-008 four-state lifecycle.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Validation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/add-shillinq-bookkeeping-foundation/specs/bookkeeping-journal-entries/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the JournalEntry register fragment shape against the spec.
 */
final class JournalEntrySchemaTest extends TestCase {

	/**
	 * Decoded fragment contents.
	 *
	 * @var array<string,mixed>
	 */
	private array $fragment;

	/**
	 * Load and decode the register fragment once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$path = __DIR__ . '/../../../lib/Settings/register.d/add-shillinq-bookkeeping-foundation.json';
		self::assertFileExists($path, 'JournalEntry register fragment must exist (ADR-037, not the monolith).');
		$raw = file_get_contents($path);
		self::assertIsString($raw);
		$decoded = json_decode($raw, true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), 'Fragment must be valid JSON.');
		$this->fragment = $decoded;

	}//end setUp()

	/**
	 * The fragment declares the JournalEntry schema under components.schemas.
	 *
	 * @return void
	 */
	public function testFragmentDeclaresJournalEntrySchema(): void {
		self::assertArrayHasKey('components', $this->fragment);
		self::assertArrayHasKey('schemas', $this->fragment['components']);
		self::assertArrayHasKey('JournalEntry', $this->fragment['components']['schemas']);

	}//end testFragmentDeclaresJournalEntrySchema()

	/**
	 * REQ-JE-002: the schema declares the required minimum field set.
	 *
	 * @return void
	 */
	public function testSchemaDeclaresRequiredFieldSet(): void {
		$schema = $this->fragment['components']['schemas']['JournalEntry'];
		$expected = [
			'journalNumber',
			'entryDate',
			'description',
			'lines',
			'sourceDocumentUri',
			'sourceDocumentApp',
			'journalType',
			'cadence',
			'reversesOn',
			'glTransactionId',
			'approvalState',
			'administrationId',
			'state',
		];
		foreach ($expected as $field) {
			self::assertArrayHasKey($field, $schema['properties'], "Missing property: $field");
		}

		foreach (['journalNumber', 'entryDate', 'description', 'lines', 'journalType', 'approvalState', 'administrationId', 'state'] as $req) {
			self::assertContains($req, $schema['required'], "Field must be required: $req");
		}

	}//end testSchemaDeclaresRequiredFieldSet()

	/**
	 * REQ-JE-003: journalType is a closed enum of exactly manual/recurring/reversing.
	 *
	 * @return void
	 */
	public function testJournalTypeIsClosedEnum(): void {
		$schema = $this->fragment['components']['schemas']['JournalEntry'];
		self::assertSame(
			['manual', 'recurring', 'reversing'],
			$schema['properties']['journalType']['enum']
		);

	}//end testJournalTypeIsClosedEnum()

	/**
	 * REQ-JE-008: approvalState is the four-value enum.
	 *
	 * @return void
	 */
	public function testApprovalStateEnum(): void {
		$schema = $this->fragment['components']['schemas']['JournalEntry'];
		self::assertSame(
			['not-required', 'pending', 'approved', 'rejected'],
			$schema['properties']['approvalState']['enum']
		);

	}//end testApprovalStateEnum()

	/**
	 * REQ-JE-008: the lifecycle declares draft/pending/posted/voided and the
	 * submit/post/postDirect/reject/void transitions, with PHP guards on post paths.
	 *
	 * @return void
	 */
	public function testLifecycleStatesAndTransitions(): void {
		$schema = $this->fragment['components']['schemas']['JournalEntry'];
		$lifecycle = $schema['x-openregister-lifecycle'];
		self::assertSame('state', $lifecycle['field']);
		self::assertSame('draft', $lifecycle['initialState']);

		foreach (['draft', 'pending', 'posted', 'voided'] as $state) {
			self::assertArrayHasKey($state, $lifecycle['states'], "Missing state: $state");
		}

		foreach (['submit', 'post', 'postDirect', 'reject', 'void'] as $transition) {
			self::assertArrayHasKey($transition, $lifecycle['transitions'], "Missing transition: $transition");
		}

		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\JournalEntryGuard::canPost',
			$lifecycle['transitions']['post']['requires']
		);
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\JournalEntryGuard::canPost',
			$lifecycle['transitions']['postDirect']['requires']
		);
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\JournalEntryGuard::canVoid',
			$lifecycle['transitions']['void']['requires']
		);

	}//end testLifecycleStatesAndTransitions()

	/**
	 * REQ-JE-002 / REQ-JE-006: the schema carries the Schema.org annotation and
	 * the docudesk source-document URI is a string field (no embedded blob).
	 *
	 * @return void
	 */
	public function testSchemaOrgAndSourceDocumentByReference(): void {
		$schema = $this->fragment['components']['schemas']['JournalEntry'];
		self::assertSame('schema:AccountingTransaction', $schema['x-schema-org']);
		self::assertSame('string', $schema['properties']['sourceDocumentUri']['type']);
		self::assertSame(['docudesk', 'external'], $schema['properties']['sourceDocumentApp']['enum']);

	}//end testSchemaOrgAndSourceDocumentByReference()

	/**
	 * Seed objects in the fragment conform to the JournalEntry schema essentials
	 * (balanced lines, valid journalType).
	 *
	 * @return void
	 */
	public function testSeedObjectsAreWellFormed(): void {
		self::assertArrayHasKey('objects', $this->fragment);
		$journalSeeds = array_filter(
			$this->fragment['objects'],
			static fn (array $o): bool => ($o['@self']['schema'] ?? '') === 'JournalEntry'
		);
		self::assertNotEmpty($journalSeeds, 'At least one JournalEntry seed object expected.');

		foreach ($journalSeeds as $seed) {
			self::assertContains($seed['journalType'], ['manual', 'recurring', 'reversing']);
			$debit = 0;
			$credit = 0;
			foreach ($seed['lines'] as $line) {
				if ($line['side'] === 'debit') {
					$debit += (int)round(((float)$line['amount']) * 100);
				} else {
					$credit += (int)round(((float)$line['amount']) * 100);
				}
			}

			self::assertSame($debit, $credit, 'Seed journal lines must balance.');
		}

	}//end testSeedObjectsAreWellFormed()

	/**
	 * REQ-JE-005: the recurring `cadence` object declares the shape required by
	 * OR's `ScheduledWorkflow` primitive (interval enum, anchor date, optional
	 * endsOn / count bounds). T1 contract only — wiring `ScheduledWorkflow` to
	 * fire on the cadence is the deferred integration task (Task 7.4).
	 *
	 * @return void
	 */
	public function testCadenceShapeReadyForScheduledWorkflow(): void {
		$schema = $this->fragment['components']['schemas']['JournalEntry'];
		$cadence = $schema['properties']['cadence'];

		self::assertSame('object', $cadence['type'], 'cadence MUST be an object.');
		self::assertArrayHasKey('interval', $cadence['properties']);
		self::assertSame(
			['daily', 'weekly', 'monthly', 'yearly'],
			$cadence['properties']['interval']['enum'],
			'cadence.interval enum MUST match REQ-JE-005.'
		);
		self::assertArrayHasKey('anchor', $cadence['properties']);
		self::assertSame('date', $cadence['properties']['anchor']['format']);
		// endsOn + count are the bounded-recurrence inputs.
		self::assertArrayHasKey('endsOn', $cadence['properties']);
		self::assertArrayHasKey('count', $cadence['properties']);

	}//end testCadenceShapeReadyForScheduledWorkflow()

	/**
	 * REQ-JE-004: the `reversesOn` field is declared as the period-id pointer
	 * that drives the inverse-posting trigger. T1 contract only — actual
	 * period-boundary firing is owned by T3 period-close (Task 7.5 deferral).
	 *
	 * @return void
	 */
	public function testReversesOnReadyForPeriodBoundaryTrigger(): void {
		$schema = $this->fragment['components']['schemas']['JournalEntry'];
		$reverses = $schema['properties']['reversesOn'];

		self::assertSame('string', $reverses['type'], 'reversesOn MUST be a string period reference.');
		self::assertTrue(($reverses['nullable'] ?? false), 'reversesOn MUST be nullable (only required when journalType=reversing).');
		// reversing belongs to the closed enum so the T3 trigger can resolve type.
		self::assertContains('reversing', $schema['properties']['journalType']['enum']);

	}//end testReversesOnReadyForPeriodBoundaryTrigger()

}//end class
