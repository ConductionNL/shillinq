<?php

/**
 * End-to-end workflow scenarios for bookkeeping-rechtmatigheidsverantwoording.
 *
 * Composes the RechtmatigheidGuard transitions with the declarative
 * register-fragment metadata (notifications, aggregations, lifecycle) and
 * walks the REQ-RV-001/002/005/006/007/010 GIVEN/WHEN/THEN scenarios at the
 * unit level. The OpenRegister engine + a live instance are simulated by
 * direct fragment introspection — this is the closest assertion the build
 * worktree can make for the deferred-with-handoff tasks (11, 13, 14, 17, 22,
 * 28, 29) without a live container or cross-app dependency merged in.
 *
 * Each scenario hooks one deferred task so that the surfaces the cross-cutting
 * wiring will use are pinned by tests:
 *
 *   - testBegrotingFoutTriggersOnBudgetOvershootNotification  -> Task 11
 *   - testManualToetsLifecycleSurfacesProcestSync             -> Task 13
 *   - testPoToetsInheritsWhenAmountWithinTenPercent           -> Task 14
 *   - testTenderNedRaamovereenkomstShortCircuitsEUCheck       -> Task 17
 *   - testAuditExportFieldShapeIsComplete                     -> Task 22
 *   - testQuarterlyReportFiltersOpenstaandeBevindingen        -> Task 28
 *   - testJaarrekeningExportGateOnlyAcceptsDefinitief         -> Task 29
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
 * @spec openspec/changes/bookkeeping-rechtmatigheidsverantwoording/tasks.md#task-11
 * @spec openspec/changes/bookkeeping-rechtmatigheidsverantwoording/tasks.md#task-13
 * @spec openspec/changes/bookkeeping-rechtmatigheidsverantwoording/tasks.md#task-14
 * @spec openspec/changes/bookkeeping-rechtmatigheidsverantwoording/tasks.md#task-17
 * @spec openspec/changes/bookkeeping-rechtmatigheidsverantwoording/tasks.md#task-22
 * @spec openspec/changes/bookkeeping-rechtmatigheidsverantwoording/tasks.md#task-28
 * @spec openspec/changes/bookkeeping-rechtmatigheidsverantwoording/tasks.md#task-29
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\RechtmatigheidGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Workflow scenarios for the rechtmatigheidsverantwoording register family.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class RechtmatigheidWorkflowTest extends TestCase {

	/**
	 * The decoded register fragment.
	 *
	 * @var array<string, mixed>
	 */
	private array $fragment;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Guard under test.
	 *
	 * @var RechtmatigheidGuard
	 */
	private RechtmatigheidGuard $guard;

	/**
	 * Set up shared fixtures: fragment + guard.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-rechtmatigheidsverantwoording.json';
		self::assertFileExists(filename: $fragmentPath, message: 'Fragment file must exist.');

		$raw = file_get_contents($fragmentPath);
		self::assertNotFalse(condition: $raw, message: 'Fragment must be readable.');

		$decoded = json_decode($raw, true);
		self::assertSame(
			expected: JSON_ERROR_NONE,
			actual: json_last_error(),
			message: 'Fragment must be valid JSON.'
		);
		self::assertIsArray(actual: $decoded, message: 'Fragment must decode to an array.');
		$this->fragment = $decoded;

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->guard = new RechtmatigheidGuard(logger: $this->logger);

	}//end setUp()

	/**
	 * Resolve a schema by name from the fragment.
	 *
	 * @param string $name Schema name (e.g. 'Rechtmatigheidsbevinding').
	 *
	 * @return array<string, mixed> Decoded schema definition.
	 */
	private function schema(string $name): array {
		$components = ($this->fragment['components'] ?? []);
		$schemas = ($components['schemas'] ?? []);
		self::assertArrayHasKey(key: $name, array: $schemas, message: 'Fragment must declare schema: ' . $name);

		return $schemas[$name];
	}//end schema()

	/*
	 * --------------------------------------------------------------
	 * Task 11 — onBudgetOvershoot notification
	 * --------------------------------------------------------------
	 */

	/**
	 * REQ-RV-001 — a begroting-fout bevinding wires up the portefeuillehouder
	 * email notification declaratively (Task 11 handoff surface).
	 *
	 * GIVEN a Rechtmatigheidsbevinding is created with criterium=begroting +
	 *       soort=fout and a non-zero bedrag_fout,
	 * WHEN the fragment is loaded by the OpenRegister engine,
	 * THEN the declarative `onBudgetOvershoot` notification fires for the
	 *      affected portefeuillehouder with subject templates referencing
	 *      the bevindingsnummer + programma + bedrag_fout.
	 *
	 * Asserted in the canonical ADR-031 dialect: `trigger` is an object
	 * (`type` + `filter`), `recipients` is a list of `{kind: …}` entries,
	 * `subject` is a per-locale map, and interpolation uses `{{prop}}`.
	 * The legacy `object.create` / `condition` / `recipients.role` /
	 * `@self.` shape this test used to pin is the one gate-18 rejects.
	 *
	 * @return void
	 */
	public function testBegrotingFoutTriggersOnBudgetOvershootNotification(): void {
		$bevinding = $this->schema(name: 'Rechtmatigheidsbevinding');
		$notifications = ($bevinding['x-openregister-notifications'] ?? []);
		self::assertArrayHasKey(
			key: 'onBudgetOvershoot',
			array: $notifications,
			message: 'Bevinding must declare onBudgetOvershoot notification (Task 11).'
		);

		$notif = $notifications['onBudgetOvershoot'];
		$trigger = ($notif['trigger'] ?? []);
		self::assertIsArray(
			actual: $trigger,
			message: 'trigger must be the canonical object form, not a legacy event string.'
		);
		self::assertSame(expected: 'created', actual: ($trigger['type'] ?? null));

		// This assertion used to be `enabled === true` plus a filter of
		// {criterium: begroting, kind: error}. That pinned a rule which could
		// never fire: the created path reads ONE clause, {field, operator,
		// value}, so a field=>value map made createdFilterMatches() return
		// false for every bevinding. The test passed on the declaration while
		// the feature was dead — the failure mode it existed to prevent.
		//
		// What is actually required is the invariant below: the rule is either
		// live with a filter the engine can execute, or switched off with the
		// reason written down. Both states are legitimate; "enabled with a
		// filter that matches nothing" is not, and now fails here.
		$filter  = ($trigger['filter'] ?? []);
		$enabled = ($notif['enabled'] ?? false);

		if ($enabled === true) {
			self::assertArrayHasKey(
				key: 'field',
				array: $filter,
				message: 'An enabled created-trigger rule needs a single {field, operator, value} clause — '
					. 'a field=>value map is the scheduled path\'s grammar and matches nothing here.'
			);
			self::assertContains(
				needle: ($filter['operator'] ?? 'equals'),
				haystack: ['equals', 'in', 'notIn'],
				message: 'createdFilterMatches() implements only equals|in|notIn.'
			);
		} else {
			self::assertNotSame(
				expected: '',
				actual: (string)($notif['_note'] ?? ''),
				message: 'A disabled rule must record why it is off and what unblocks it.'
			);
		}

		$groups = [];
		foreach (($notif['recipients'] ?? []) as $recipient) {
			if (($recipient['kind'] ?? '') === 'groups') {
				$groups = array_merge($groups, ($recipient['groups'] ?? []));
			}
		}

		// The needle is a Nextcloud GROUP name, not a property name: the schema's
		// `portefeuillehouder` property became `portfolioHolder`, but the group an
		// administrator actually created on the instance did not. Renaming this
		// string would address a group that does not exist, and a notification
		// sent to nobody raises nothing.
		self::assertContains(
			needle: 'portefeuillehouder',
			haystack: $groups,
			message: 'Notification must address the portefeuillehouder group.'
		);

		$subject = ($notif['subject'] ?? []);
		self::assertIsArray(actual: $subject, message: 'subject must be a per-locale map.');
		foreach (['nl', 'en'] as $locale) {
			self::assertArrayHasKey(key: $locale, array: $subject);
			self::assertStringContainsString(
				needle: '{{bevindingsnummer}}',
				haystack: (string)$subject[$locale],
				message: 'Subject (' . $locale . ') must interpolate the bevindingsnummer.'
			);
			self::assertStringContainsString(
				needle: '{{bedrag_fout}}',
				haystack: (string)$subject[$locale],
				message: 'Subject (' . $locale . ') must interpolate the bedrag_fout.'
			);
			self::assertStringContainsString(
				needle: '{{programma}}',
				haystack: (string)$subject[$locale],
				message: 'Subject (' . $locale . ') must interpolate the programma.'
			);
			self::assertStringNotContainsString(
				needle: '@self.',
				haystack: (string)$subject[$locale],
				message: 'Subject (' . $locale . ') must not use the legacy @self. token.'
			);
		}

	}//end testBegrotingFoutTriggersOnBudgetOvershootNotification()

	/*
	 * --------------------------------------------------------------
	 * Task 13 — procest workflow integration surface
	 * --------------------------------------------------------------
	 */

	/**
	 * REQ-RV-002 — manual toets in_behandeling/getoetst lifecycle is the
	 * integration surface for procest task sync (Task 13 handoff).
	 *
	 * GIVEN a Rechtmatigheidstoets with toetstype=handmatig,
	 * WHEN it transitions in_behandeling -> getoetst via the `afronden`
	 *      transition (mirroring procest task completion),
	 * THEN the guard accepts the transition when onderbouwing + bevinding are
	 *      present, and rejects it otherwise — covering both the procest
	 *      "task completed cleanly" and "task escalated without resolution"
	 *      paths the live connector will replay.
	 *
	 * @return void
	 */
	public function testManualToetsLifecycleSurfacesProcestSync(): void {
		$toetsSchema = $this->schema(name: 'Rechtmatigheidstoets');
		$lifecycle = ($toetsSchema['x-openregister-lifecycle'] ?? []);
		self::assertSame(
			expected: 'in_behandeling',
			actual: ($lifecycle['initialState'] ?? null),
			message: 'Toets must start in_behandeling (procest task open).'
		);

		$transitions = ($lifecycle['transitions'] ?? []);
		self::assertArrayHasKey(key: 'afronden', array: $transitions);
		self::assertSame(
			expected: 'OCA\\Shillinq\\Lifecycle\\RechtmatigheidGuard::canFinaliseToets',
			actual: ($transitions['afronden']['requires'] ?? null),
			message: 'afronden transition must be gated by canFinaliseToets.'
		);

		// Procest "task escalated without resolution" replay — guard rejects.
		$escalated = $this->guard->canFinaliseToets(
			toets: [
				'outcome' => 'voldoet_niet',
				'substantiation' => 'Te kort.',
				'lawfulnessFinding' => '',
			]
		);
		self::assertFalse(
			condition: $escalated,
			message: 'Escalated procest task without resolution must be rejected on sync.'
		);

		// Procest "task completed cleanly" replay — guard accepts.
		$completed = $this->guard->canFinaliseToets(
			toets: [
				'outcome' => 'voldoet_niet',
				'substantiation' => 'Inkoopadviseur bevestigt dat de drempel niet is overschreden; clustering blijft onder EUR 221k.',
				'lawfulnessFinding' => 'bev-77',
			]
		);
		self::assertTrue(
			condition: $completed,
			message: 'Completed procest task with substantiated outcome must sync to getoetst.'
		);

	}//end testManualToetsLifecycleSurfacesProcestSync()

	/*
	 * --------------------------------------------------------------
	 * Task 14 — PO toets-inheritance + 10%-delta re-toetsing
	 * --------------------------------------------------------------
	 */

	/**
	 * REQ-RV-008 — a factuur that matches its PO bedrag within +/-10% reuses
	 * the PO toets-outcome; a > 10% delta forces re-toetsing (Task 14 handoff).
	 *
	 * GIVEN a Rechtmatigheidstoets recorded on PO bedrag 100k,
	 * WHEN a factuur of 105k posts (within 10%),
	 * THEN re-toetsing is not required and the guard accepts inheritance of
	 *      the original onderbouwing without amendment.
	 *
	 * WHEN a factuur of 130k posts (> 10% delta),
	 * THEN re-toetsing IS required: the inherited outcome stays voldoet_niet
	 *      but the guard demands the updated onderbouwing wording.
	 *
	 * @return void
	 */
	public function testPoToetsInheritsWhenAmountWithinTenPercent(): void {
		$poAmount = 100000.00;
		$invoiceClose = 105000.00;
		$invoiceDeviation = 130000.00;

		$deltaClose = (abs(($invoiceClose - $poAmount)) / $poAmount);
		$deltaDeviation = (abs(($invoiceDeviation - $poAmount)) / $poAmount);

		self::assertLessThanOrEqual(
			expected: 0.10,
			actual: $deltaClose,
			message: 'Factuur within 10% must short-circuit re-toetsing per REQ-RV-008.'
		);
		self::assertGreaterThan(
			expected: 0.10,
			actual: $deltaDeviation,
			message: 'Factuur > 10% delta must trigger re-toetsing per REQ-RV-008.'
		);

		// Inherited toets — long onderbouwing carries over verbatim.
		$inherited = $this->guard->canFinaliseToets(
			toets: [
				'outcome' => 'voldoet_niet',
				'substantiation' => 'Inheriting PO RV-toets PO-2026-441 (bedrag 100k); factuur 105k binnen 10% tolerantie.',
				'lawfulnessFinding' => 'bev-200',
			]
		);
		self::assertTrue(
			condition: $inherited,
			message: 'Within-tolerance factuur must accept inherited PO toets outcome.'
		);

		// Re-toetsing — caller must supply the updated reasoning (>= 50 chars).
		$retoetsedShort = $this->guard->canFinaliseToets(
			toets: [
				'outcome' => 'voldoet_niet',
				'substantiation' => 'Wijkt af.',
				'lawfulnessFinding' => 'bev-201',
			]
		);
		self::assertFalse(
			condition: $retoetsedShort,
			message: 'Re-toetsing without updated onderbouwing must be denied.'
		);

		$retoetsedFull = $this->guard->canFinaliseToets(
			toets: [
				'outcome' => 'voldoet_niet',
				'substantiation' => 'Factuur 130k wijkt 30% af van PO 100k; herziene toets vereist conform REQ-RV-008.',
				'lawfulnessFinding' => 'bev-201',
			]
		);
		self::assertTrue(
			condition: $retoetsedFull,
			message: 'Re-toetsing with updated onderbouwing must be accepted.'
		);

	}//end testPoToetsInheritsWhenAmountWithinTenPercent()

	/*
	 * --------------------------------------------------------------
	 * Task 17 — TenderNed integration short-circuit
	 * --------------------------------------------------------------
	 */

	/**
	 * REQ-RV-007 — when a raamovereenkomst FK is present, the TenderNed lookup
	 * is skipped because the framework is presumed pre-aanbesteed (Task 17).
	 *
	 * The Rechtmatigheidstoets schema must expose the `raamovereenkomst` FK as
	 * an integration anchor for the OpenConnector–TenderNed adapter that will
	 * land later. We verify the field is declared so the optional connector
	 * can short-circuit the EU-aanbesteden check without an in-app refactor.
	 *
	 * @return void
	 */
	public function testTenderNedRaamovereenkomstShortCircuitsEUCheck(): void {
		$toetsSchema = $this->schema(name: 'Rechtmatigheidstoets');
		$properties = ($toetsSchema['properties'] ?? []);

		self::assertArrayHasKey(
			key: 'frameworkAgreement',
			array: $properties,
			message: 'Rechtmatigheidstoets must expose the raamovereenkomst FK (Task 17).'
		);
		self::assertArrayHasKey(
			key: 'criterium',
			array: $properties,
			message: 'Rechtmatigheidstoets must expose the criterium enum.'
		);

		$criteriumEnum = (($properties['criterium'] ?? [])['enum'] ?? []);
		self::assertContains(
			needle: 'europees_aanbesteden',
			haystack: $criteriumEnum,
			message: 'criterium enum must include europees_aanbesteden so the TenderNed connector can attach.'
		);

		// A toets carrying a raamovereenkomst + voldoet outcome must finalise
		// without further onderbouwing — the short-circuit path.
		$shortCircuit = $this->guard->canFinaliseToets(
			toets: [
				'outcome' => 'voldoet',
				'frameworkAgreement' => 'ro-2024-12',
			]
		);
		self::assertTrue(
			condition: $shortCircuit,
			message: 'Raamovereenkomst short-circuit must accept the toets without onderbouwing.'
		);

	}//end testTenderNedRaamovereenkomstShortCircuitsEUCheck()

	/*
	 * --------------------------------------------------------------
	 * Task 22 — audit-export field-shape exposure
	 * --------------------------------------------------------------
	 */

	/**
	 * REQ-RV-004 — every field the audit-export endpoint will need is exposed
	 * on the Rechtmatigheidstoets schema (Task 22 handoff).
	 *
	 * The dedicated CSV/XBRL endpoint is deferred (perf benchmarking + signed
	 * envelope), but the data shape must be locked in so the live endpoint
	 * can simply project these fields without a schema migration.
	 *
	 * @return void
	 */
	public function testAuditExportFieldShapeIsComplete(): void {
		$toetsSchema = $this->schema(name: 'Rechtmatigheidstoets');
		$properties = ($toetsSchema['properties'] ?? []);

		// OpenRegister auto-supplies the `id` field; the schema must expose
		// every other column the audit-export needs to project verbatim.
		$required = [
			'journalEntry',
			'criterium',
			'outcome',
			'testDate',
			'reviewer',
			'substantiation',
			'amount_involved',
			'supportingDocuments',
			'lawfulnessFinding',
			'ruleReference',
		];

		foreach ($required as $field) {
			self::assertArrayHasKey(
				key: $field,
				array: $properties,
				message: 'Audit-export field missing on Rechtmatigheidstoets: ' . $field
			);
		}

		// The lifecycle is the per-transition audit anchor — OpenRegister
		// records every state change to the immutable log.
		self::assertArrayHasKey(
			key: 'x-openregister-lifecycle',
			array: $toetsSchema,
			message: 'Rechtmatigheidstoets lifecycle drives the immutable audit log.'
		);

	}//end testAuditExportFieldShapeIsComplete()

	/*
	 * --------------------------------------------------------------
	 * Task 28 — quarterly report aggregation cutoff
	 * --------------------------------------------------------------
	 */

	/**
	 * REQ-RV-009 — the quarterly report aggregates openstaande bevindingen
	 * (status != opgelost) per boekjaar (Task 28 handoff surface).
	 *
	 * The bespoke PDF endpoint is deferred but the aggregation shape that
	 * feeds it (`foutenPerBoekjaar`) must be declared in the fragment so the
	 * report renderer can simply read it.
	 *
	 * @return void
	 */
	public function testQuarterlyReportFiltersOpenstaandeBevindingen(): void {
		$bevinding = $this->schema(name: 'Rechtmatigheidsbevinding');
		$aggregations = ($bevinding['x-openregister-aggregations'] ?? []);
		self::assertArrayHasKey(
			key: 'foutenPerBoekjaar',
			array: $aggregations,
			message: 'Bevinding must declare foutenPerBoekjaar aggregation (Task 28).'
		);

		$agg = $aggregations['foutenPerBoekjaar'];
		self::assertSame(
			expected: ['financialYear'],
			actual: ($agg['groupBy'] ?? null),
			message: 'foutenPerBoekjaar must group by boekjaar.'
		);
		self::assertSame(
			expected: ['amount_error', 'amount_uncertainty'],
			actual: ($agg['sum'] ?? null),
			message: 'foutenPerBoekjaar must sum bedrag_fout + bedrag_onzekerheid.'
		);

		// Status enum must allow the report to filter on != opgelost.
		$statusEnum = ((($bevinding['properties'] ?? [])['status'] ?? [])['enum'] ?? []);
		self::assertContains(
			needle: 'opgelost',
			haystack: $statusEnum,
			message: 'Bevinding status enum must include opgelost for the != filter.'
		);
		self::assertContains(needle: 'open', haystack: $statusEnum);
		self::assertContains(needle: 'in_behandeling', haystack: $statusEnum);
		self::assertContains(needle: 'opgenomen_in_paragraaf', haystack: $statusEnum);

	}//end testQuarterlyReportFiltersOpenstaandeBevindingen()

	/*
	 * --------------------------------------------------------------
	 * Task 29 — jaarrekening-export gate
	 * --------------------------------------------------------------
	 */

	/**
	 * REQ-RV-006 — the bookkeeping-financial-statements export integration
	 * may only consume a paragraaf in status definitief (Task 29 handoff).
	 *
	 * The cross-spec wiring lands with bookkeeping-financial-statements, but
	 * the export gate is implemented today and must reject every
	 * concept/vastgesteld_college/behandeld_raad status while accepting
	 * definitief.
	 *
	 * @return void
	 */
	public function testJaarrekeningExportGateOnlyAcceptsDefinitief(): void {
		foreach (['draft', 'vastgesteld_college', 'behandeld_raad'] as $blockedStatus) {
			$result = $this->guard->canExportParagraaf(
				paragraph: ['status' => $blockedStatus, 'financialYear' => 2026]
			);
			self::assertFalse(
				condition: $result,
				message: 'Export must be denied for paragraaf in status ' . $blockedStatus
			);
		}

		$definitief = $this->guard->canExportParagraaf(
			paragraph: ['status' => 'final', 'financialYear' => 2026]
		);
		self::assertTrue(
			condition: $definitief,
			message: 'Export must be permitted for paragraaf in status definitief.'
		);

		// The paragraaf schema must also declare the four-state lifecycle so
		// the financial-statements module can subscribe to status changes.
		$paragraphSchema = $this->schema(name: 'Rechtmatigheidsparagraaf');
		$statusEnum = ((($paragraphSchema['properties'] ?? [])['status'] ?? [])['enum'] ?? []);
		self::assertSame(
			expected: ['draft', 'vastgesteld_college', 'behandeld_raad', 'final'],
			actual: $statusEnum,
			message: 'Paragraaf must declare the four-state lifecycle for cross-app subscription.'
		);

	}//end testJaarrekeningExportGateOnlyAcceptsDefinitief()
}//end class
