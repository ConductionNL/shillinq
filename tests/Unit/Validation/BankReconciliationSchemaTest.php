<?php

/**
 * Unit tests for the BankReconciliation + BankReconciliationMatch register fragment (ADR-037).
 *
 * Locks the declarative contract of the bookkeeping-bank-reconciliation spec:
 * the two schemas live in a register.d fragment (not the monolith), declare the
 * REQ-BBR-001..006 field set, the closed status / matchType enums, the four-state
 * BankReconciliation lifecycle with PHP-guard preconditions, the four-state
 * BankReconciliationMatch lifecycle, and the cross-object relations + aggregations
 * that the engine and the BankReconciliationGuard rely on.
 *
 * Fixture-shape coverage for Task 42 (auto-matching engine is deferred to
 * shillinq-integrations, but the data-model contract that the engine will
 * consume is locked here).
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
 * @spec openspec/changes/bookkeeping-bank-reconciliation/specs/bookkeeping-bank-reconciliation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the BankReconciliation register fragment shape against the spec.
 */
final class BankReconciliationSchemaTest extends TestCase {

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
		$path = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-bank-reconciliation.json';
		self::assertFileExists($path, 'BankReconciliation register fragment must exist (ADR-037, not the monolith).');
		$raw = file_get_contents($path);
		self::assertIsString($raw);
		$decoded = json_decode($raw, true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), 'Fragment must be valid JSON.');
		$this->fragment = $decoded;

	}//end setUp()

	/**
	 * The fragment declares both BankReconciliation and BankReconciliationMatch schemas.
	 *
	 * @return void
	 */
	public function testFragmentDeclaresBothSchemas(): void {
		self::assertArrayHasKey('components', $this->fragment);
		self::assertArrayHasKey('schemas', $this->fragment['components']);
		self::assertArrayHasKey('BankReconciliation', $this->fragment['components']['schemas']);
		self::assertArrayHasKey('BankReconciliationMatch', $this->fragment['components']['schemas']);

	}//end testFragmentDeclaresBothSchemas()

	/**
	 * REQ-BBR-001: BankReconciliation declares the required minimum field set.
	 *
	 * @return void
	 */
	public function testReconciliationDeclaresRequiredFieldSet(): void {
		$schema = $this->fragment['components']['schemas']['BankReconciliation'];

		$expectedProperties = [
			'name',
			'bankAccountId',
			'administrationId',
			'statementStartDate',
			'statementEndDate',
			'openingBalance',
			'closingBalance',
			'reconciledBalance',
			'variance',
			'matchedCount',
			'unmatchedBankCount',
			'unmatchedJournalCount',
			'status',
			'approvedBy',
			'approvedAt',
			'varianceReason',
			'notes',
		];
		foreach ($expectedProperties as $field) {
			self::assertArrayHasKey($field, $schema['properties'], "Missing property: $field");
		}

		$expectedRequired = [
			'name',
			'bankAccountId',
			'statementStartDate',
			'statementEndDate',
			'openingBalance',
			'closingBalance',
			'status',
		];
		foreach ($expectedRequired as $field) {
			self::assertContains($field, $schema['required'], "Field must be required: $field");
		}

	}//end testReconciliationDeclaresRequiredFieldSet()

	/**
	 * REQ-BBR-006 / Task 3: BankReconciliation status is a closed four-value enum
	 * that drives the lock-on-approve immutability.
	 *
	 * @return void
	 */
	public function testReconciliationStatusIsClosedEnum(): void {
		$schema = $this->fragment['components']['schemas']['BankReconciliation'];
		self::assertSame(
			['draft', 'in-progress', 'reconciled', 'archived'],
			$schema['properties']['status']['enum']
		);
		self::assertSame('draft', $schema['properties']['status']['default']);

	}//end testReconciliationStatusIsClosedEnum()

	/**
	 * REQ-BBR-006 / Tasks 17, 29, 30: the BankReconciliation lifecycle declares
	 * draft/in-progress/reconciled/archived and the startReconciling/approve/
	 * approveFromDraft/archive transitions, with the requireResolvedMatches +
	 * requireUnlockedAndValidDates PHP guards bound by FQCN.
	 *
	 * @return void
	 */
	public function testReconciliationLifecycleStatesAndTransitions(): void {
		$schema = $this->fragment['components']['schemas']['BankReconciliation'];
		$lifecycle = $schema['x-openregister-lifecycle'];
		self::assertSame('status', $lifecycle['field']);
		self::assertSame('draft', $lifecycle['initialState']);
		self::assertTrue($lifecycle['audit'] ?? false, 'BankReconciliation lifecycle must audit-trail every transition (Task 25).');

		foreach (['draft', 'in-progress', 'reconciled', 'archived'] as $state) {
			self::assertArrayHasKey($state, $lifecycle['states'], "Missing state: $state");
		}

		foreach (['startReconciling', 'approve', 'approveFromDraft', 'archive'] as $transition) {
			self::assertArrayHasKey($transition, $lifecycle['transitions'], "Missing transition: $transition");
		}

		// The approve paths must invoke the resolved-matches precondition that
		// recomputes server-authoritative balance and locks the session.
		self::assertSame(
			'OCA\\Shillinq\\Guard\\BankReconciliationGuard::requireResolvedMatches',
			$lifecycle['transitions']['approve']['requires']
		);
		self::assertSame(
			'OCA\\Shillinq\\Guard\\BankReconciliationGuard::requireResolvedMatches',
			$lifecycle['transitions']['approveFromDraft']['requires']
		);

		// The save precondition enforces the lock + valid statement period.
		self::assertSame(
			'OCA\\Shillinq\\Guard\\BankReconciliationGuard::requireUnlockedAndValidDates',
			$lifecycle['preconditions']['save']
		);

	}//end testReconciliationLifecycleStatesAndTransitions()

	/**
	 * REQ-BBR-005: derived monetary fields are nullable on the schema (the guard
	 * writes them server-side; the client must never supply them).
	 *
	 * @return void
	 */
	public function testServerAuthoritativeDerivedFieldsAreNullable(): void {
		$schema = $this->fragment['components']['schemas']['BankReconciliation'];

		foreach (['reconciledBalance', 'variance', 'matchedCount', 'unmatchedBankCount', 'unmatchedJournalCount'] as $derived) {
			self::assertTrue(
				($schema['properties'][$derived]['nullable'] ?? false),
				"$derived MUST be nullable so the guard can recompute it server-side (ADR-005)."
			);
			self::assertNotContains(
				$derived,
				$schema['required'],
				"$derived MUST NOT be in `required` — client-supplied values are ignored."
			);
		}

	}//end testServerAuthoritativeDerivedFieldsAreNullable()

	/**
	 * BankReconciliation declares the countByStatus aggregation that the
	 * manifest index filters on.
	 *
	 * WITHDRAWN ASSERTION — the `matches` relation (BankReconciliation ->
	 * BankReconciliationMatch). It was declared in the per-schema
	 * `x-openregister-relations` block, which ADR-062 rule 7 retired on
	 * 2026-07-08 in favour of a property-level `$ref`. This relation is NOT
	 * expressible in the canonical dialect and was therefore removed rather
	 * than migrated: it is the INVERSE side. Its `localField` was `id` — this
	 * schema's own object identity, not a property — and the foreign key
	 * actually lives on the other schema, as `BankReconciliationMatch.
	 * reconciliationId`. A `$ref` rides on the property that HOLDS the
	 * reference, so the only place this relationship can be declared is the
	 * child, where testMatchReconciliationRelation() now asserts it. Asserting
	 * it here as well would be asserting something the register deliberately no
	 * longer declares on either side of the pair.
	 *
	 * @return void
	 */
	public function testReconciliationAggregations(): void {
		$schema = $this->fragment['components']['schemas']['BankReconciliation'];

		// `metric`, not `operation`, and `groupBy` as an ARRAY — the engine's
		// vocabulary. `operation` is read by nothing, and a string `groupBy` is
		// silently dropped, so the old spelling returned no value and then, with
		// `metric` alone, an ungrouped total. See #1261.
		$agg = $schema['x-openregister-aggregations']['countByStatus'];
		self::assertSame('status', $agg['field']);
		self::assertSame('count', $agg['metric']);
		self::assertArrayNotHasKey('operation', $agg);
		self::assertSame(['status'], $agg['groupBy']);

	}//end testReconciliationAggregations()

	/**
	 * BankReconciliation declares the bookkeeper/auditor RBAC roles per ADR-005.
	 *
	 * @return void
	 */
	public function testReconciliationRbacRoles(): void {
		$schema = $this->fragment['components']['schemas']['BankReconciliation'];

		$roles = $schema['x-openregister-rbac']['roles'];
		self::assertEqualsCanonicalizing(['create', 'read', 'update'], $roles['bookkeeper']['permissions']);
		self::assertEqualsCanonicalizing(['read'], $roles['auditor']['permissions']);

	}//end testReconciliationRbacRoles()

	/**
	 * REQ-BBR-002 / Task 4: BankReconciliationMatch declares the required minimum
	 * field set including the deduplicating bankTransactionRef key (design D3).
	 *
	 * @return void
	 */
	public function testMatchDeclaresRequiredFieldSet(): void {
		$schema = $this->fragment['components']['schemas']['BankReconciliationMatch'];

		$expectedProperties = [
			'reconciliationId',
			'bankTransactionRef',
			'bankTransactionAmount',
			'journalEntryId',
			'journalEntryDescription',
			'matchType',
			'confidenceScore',
			'operatorNotes',
			'createdBy',
			'approvedAt',
			'approvedBy',
		];
		foreach ($expectedProperties as $field) {
			self::assertArrayHasKey($field, $schema['properties'], "Missing property: $field");
		}

		$expectedRequired = [
			'reconciliationId',
			'bankTransactionRef',
			'bankTransactionAmount',
			'matchType',
			'createdBy',
		];
		foreach ($expectedRequired as $field) {
			self::assertContains($field, $schema['required'], "Field must be required: $field");
		}

	}//end testMatchDeclaresRequiredFieldSet()

	/**
	 * Task 4 + REQ-BBR-002/003/004: matchType is a closed four-value enum and
	 * defaults to pending-review so operator review is the safe default.
	 *
	 * @return void
	 */
	public function testMatchTypeIsClosedEnum(): void {
		$schema = $this->fragment['components']['schemas']['BankReconciliationMatch'];
		self::assertSame(
			['auto-matched', 'pending-review', 'approved', 'rejected'],
			$schema['properties']['matchType']['enum']
		);
		self::assertSame('pending-review', $schema['properties']['matchType']['default']);

	}//end testMatchTypeIsClosedEnum()

	/**
	 * Task 42 / engine-ready fixture shape: confidenceScore is a bounded
	 * integer 0..100 so the auto-matching engine's score band thresholds
	 * (≥70 auto-matched, 30..69 pending-review, <30 orphaned) are well-defined.
	 *
	 * @return void
	 */
	public function testConfidenceScoreIsBoundedInteger(): void {
		$schema = $this->fragment['components']['schemas']['BankReconciliationMatch'];
		$score = $schema['properties']['confidenceScore'];

		self::assertSame('integer', $score['type']);
		self::assertSame(0, $score['minimum']);
		self::assertSame(100, $score['maximum']);
		self::assertTrue(($score['nullable'] ?? false), 'confidenceScore MUST be nullable for operator-only matches.');

	}//end testConfidenceScoreIsBoundedInteger()

	/**
	 * Tasks 14, 15, 16: BankReconciliationMatch lifecycle declares
	 * auto-matched/pending-review/approved/rejected with the
	 * approve/approvePending/rejectFromPending/rejectFromAuto/unmatch transitions
	 * and the requireParentUnlocked save precondition that blocks any match
	 * mutation while the parent reconciliation is locked.
	 *
	 * @return void
	 */
	public function testMatchLifecycleStatesAndTransitions(): void {
		$schema = $this->fragment['components']['schemas']['BankReconciliationMatch'];
		$lifecycle = $schema['x-openregister-lifecycle'];
		self::assertSame('matchType', $lifecycle['field']);
		self::assertSame('pending-review', $lifecycle['initialState']);
		self::assertTrue($lifecycle['audit'] ?? false, 'BankReconciliationMatch lifecycle must audit-trail every transition (Task 25).');

		foreach (['auto-matched', 'pending-review', 'approved', 'rejected'] as $state) {
			self::assertArrayHasKey($state, $lifecycle['states'], "Missing state: $state");
		}

		$transitions = [
			'approve' => ['from' => 'auto-matched',   'to' => 'approved'],
			'approvePending' => ['from' => 'pending-review', 'to' => 'approved'],
			'rejectFromPending' => ['from' => 'pending-review', 'to' => 'rejected'],
			'rejectFromAuto' => ['from' => 'auto-matched',   'to' => 'rejected'],
			'unmatch' => ['from' => 'approved',       'to' => 'pending-review'],
		];
		foreach ($transitions as $key => $expected) {
			self::assertArrayHasKey($key, $lifecycle['transitions'], "Missing transition: $key");
			self::assertSame($expected['from'], $lifecycle['transitions'][$key]['from']);
			self::assertSame($expected['to'], $lifecycle['transitions'][$key]['to']);
		}

		self::assertSame(
			'OCA\\Shillinq\\Guard\\BankReconciliationGuard::requireParentUnlocked',
			$lifecycle['preconditions']['save']
		);

	}//end testMatchLifecycleStatesAndTransitions()

	/**
	 * REQ-BBR-005 / Task 27: BankReconciliationMatch declares the approvedAmountTotal
	 * aggregation that is the declarative basis for the server-recomputed
	 * reconciledBalance.
	 *
	 * @return void
	 */
	public function testApprovedAmountTotalAggregation(): void {
		$schema = $this->fragment['components']['schemas']['BankReconciliationMatch'];
		$agg = $schema['x-openregister-aggregations']['approvedAmountTotal'];

		// `approvedAmountTotal` is NOT translated: it uses an `operations` dict
		// whose custom alias (`approvedTotal`) the engine cannot express — its
		// `metrics` form emits `sum_<field>` keys, so translating it changes
		// what every CONSUMER must read, not just the declaration. Its `filter`
		// is also a SQL-ish string the engine does not parse. Both are tracked
		// in #1261, and this test pins the shape as it stands so the debt stays
		// visible rather than looking intentional.
		self::assertSame("matchType = 'approved'", $agg['filter']);
		self::assertSame('reconciliationId', $agg['groupBy']);
		self::assertSame('bankTransactionAmount', $agg['operations']['approvedTotal']['field']);
		self::assertSame('sum', $agg['operations']['approvedTotal']['operation']);

		// `countByType` IS translated — a plain count over its own schema.
		$countByType = $schema['x-openregister-aggregations']['countByType'];
		self::assertSame('matchType', $countByType['field']);
		self::assertSame('count', $countByType['metric']);
		self::assertArrayNotHasKey('operation', $countByType);
		self::assertSame(['matchType'], $countByType['groupBy']);

	}//end testApprovedAmountTotalAggregation()

	/**
	 * BankReconciliationMatch declares the many-to-one relation back to its parent
	 * reconciliation, which the related-list on the detail page (Task 7/10) renders.
	 *
	 * The relation is asserted in the CANONICAL dialect (ADR-062 rule 7): a
	 * property-level `$ref` on `reconciliationId`, resolving case-exactly to a
	 * schema key in the same register set. It was previously read from the
	 * per-schema `x-openregister-relations` block, which was retired
	 * 2026-07-08. This side of the pair is expressible because the old block's
	 * `relatedField` was `id` — the parent's object identity — so the foreign
	 * key genuinely holds what a `$ref` resolves against. Cardinality is no
	 * longer declared anywhere: a scalar (non-array) `$ref` IS the many-to-one
	 * form, so the shape of the property carries it.
	 *
	 * @return void
	 */
	public function testMatchReconciliationRelation(): void {
		$schema = $this->fragment['components']['schemas']['BankReconciliationMatch'];

		self::assertArrayHasKey(
			key: 'reconciliationId',
			array: $schema['properties'],
			message: 'BankReconciliationMatch must declare the reconciliationId foreign key.'
		);
		$prop = $schema['properties']['reconciliationId'];
		self::assertSame(
			expected: 'BankReconciliation',
			actual: ($prop['$ref'] ?? null),
			message: 'reconciliationId must carry the canonical property-level $ref to BankReconciliation (ADR-062 rule 7).'
		);
		self::assertSame(
			expected: 'string',
			actual: ($prop['type'] ?? null),
			message: 'A scalar (non-array) $ref is the many-to-one form; an array would mean one-to-many.'
		);

	}//end testMatchReconciliationRelation()

	/**
	 * Design D3 / Task 41 dedupe contract: the bankTransactionRef field carries the
	 * documented composite key shape so re-imports of the same statement collide
	 * deterministically — locks the engine-ready dedupe key contract (Task 42).
	 *
	 * @return void
	 */
	public function testBankTransactionRefDocumentsCompositeKey(): void {
		$schema = $this->fragment['components']['schemas']['BankReconciliationMatch'];
		$ref = $schema['properties']['bankTransactionRef'];

		self::assertSame('string', $ref['type']);
		// The composite key shape lives in the property description so the
		// ingest engine (deferred to shillinq-integrations) has a single source
		// of truth and re-imports dedupe deterministically per design D3.
		self::assertStringContainsString('{bankAccountId}', $ref['description']);
		self::assertStringContainsString('{statementDate}', $ref['description']);
		self::assertStringContainsString('{amount}', $ref['description']);
		self::assertStringContainsString('{externalId}', $ref['description']);

	}//end testBankTransactionRefDocumentsCompositeKey()

	/**
	 * Task 46 input-validation contract: free-text fields cap at 500 characters so
	 * audit-trail rows stay bounded.
	 *
	 * @return void
	 */
	public function testFreeTextFieldsHaveMaxLength(): void {
		$reconciliation = $this->fragment['components']['schemas']['BankReconciliation'];
		self::assertSame(500, $reconciliation['properties']['notes']['maxLength']);
		self::assertSame(500, $reconciliation['properties']['varianceReason']['maxLength']);

		$match = $this->fragment['components']['schemas']['BankReconciliationMatch'];
		self::assertSame(500, $match['properties']['operatorNotes']['maxLength']);

	}//end testFreeTextFieldsHaveMaxLength()

}//end class
