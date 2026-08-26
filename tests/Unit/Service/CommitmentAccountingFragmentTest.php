<?php

/**
 * Unit tests for the verplichtingen-commitment-accounting delta on the
 * bookkeeping-verplichtingenadministratie register fragment.
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
 * @spec openspec/changes/verplichtingen-commitment-accounting/specs/bookkeeping-verplichtingenadministratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Verifies REQ-VPL-010's bronReferentie field and REQ-VPL-011's declarative
 * committed-vs-realised aggregation, and that no parallel PHP reporting
 * service duplicates the aggregation's figures (ADR-031).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class CommitmentAccountingFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-verplichtingenadministratie.json';

	/**
	 * Load the fragment as an array.
	 *
	 * @return array<mixed>
	 */
	private function fragment(): array {
		return json_decode((string)file_get_contents($this->fragmentPath), true);
	}//end fragment()

	/**
	 * The fragment is present and valid JSON.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());

	}//end testFragmentIsValidJson()

	/**
	 * REQ-VPL-010: Commitment declares an optional bronReferentie provenance field.
	 *
	 * @return void
	 */
	public function testCommitmentDeclaresBronReferentie(): void {
		$commitment = $this->fragment()['components']['schemas']['Commitment'];
		self::assertArrayHasKey('sourceReference', $commitment['properties']);
		self::assertTrue($commitment['properties']['sourceReference']['nullable'] ?? false);
		self::assertArrayNotContains('sourceReference', ($commitment['required'] ?? []));

	}//end testCommitmentDeclaresBronReferentie()

	/**
	 * Custom PHPUnit-style helper: assertArrayNotContains (not a native
	 * assertion in the PHPUnit version pinned here).
	 *
	 * @param mixed $needle Value that must not be present.
	 * @param array<mixed> $haystack Array to search.
	 *
	 * @return void
	 */
	private static function assertArrayNotContains($needle, array $haystack): void {
		self::assertNotContains($needle, $haystack);

	}//end assertArrayNotContains()

	/**
	 * REQ-VPL-011: CommitmentLine declares the committed-vs-realised
	 * per-budget-line aggregation, grouped by the full coderingscombinatie.
	 *
	 * @return void
	 */
	public function testCommitmentLineDeclaresCommittedVsRealisedAggregation(): void {
		$rule = $this->fragment()['components']['schemas']['CommitmentLine'];
		self::assertArrayHasKey('x-openregister-aggregations', $rule);
		self::assertArrayHasKey('committedVsRealisedPerBudgetLine', $rule['x-openregister-aggregations']);

		$agg = $rule['x-openregister-aggregations']['committedVsRealisedPerBudgetLine'];

		// ASSERTS THE ENGINE'S VOCABULARY, NOT AN IMAGINED ONE. This used to
		// pin `source` and a `sum` list. OpenRegister reads neither — the
		// annotation keys are field / filter / from / groupBy / join / metric /
		// metrics / select / where — so the runner recognised the aggregation
		// by NAME and understood none of its body, returning groups:[] against
		// live objects with no error (issue #1216). The test passed throughout,
		// because it only ever compared the declaration to itself.
		self::assertArrayNotHasKey('source', $agg, '`source` is not an engine key; the intra-schema aggregation needs none');
		self::assertArrayNotHasKey('sum', $agg, '`sum` is not an engine key; use metrics[]');

		self::assertSame(
			['programme', 'costCentre', 'financialYear', 'generalLedgerAccount'],
			$agg['groupBy']
		);

		// Two figures over one grouping — the multi-metric spelling
		// (openregister#2849). Each entry names a metric AND its field.
		self::assertSame(
			[
				['metric' => 'sum', 'field' => 'remaining_committed'],
				['metric' => 'sum', 'field' => 'invoiced_amount'],
			],
			$agg['metrics']
		);

		self::assertSame('CommitmentBudget', $agg['join']['through']);

		// The explicit {parentField: joinedField} map, not the string
		// shorthand. The shorthand names only the joined side and leaves the
		// parent side to be INFERRED from the group fields; it happens to infer
		// correctly here, but it is a guess, and only the map can express a
		// composite join key.
		self::assertSame(['programme' => 'programmeCode'], $agg['join']['on']);

		// administrationId must NOT be declared: it differs per caller, and the
		// `@self.administrationId` that used to stand here is not a placeholder
		// OpenRegister implements — it was left literal and filtered every row
		// away. Scoping is supplied by the caller as a NARROWING filter
		// (openregister#2852), which cannot relax what is declared here.
		self::assertArrayNotHasKey('administrationId', $agg['filter']);
		self::assertFalse($agg['filter']['afgesloten']);

	}//end testCommitmentLineDeclaresCommittedVsRealisedAggregation()

	/**
	 * REQ-VPL-011 / ADR-031: no PHP class in lib/Service or lib/Lifecycle
	 * independently computes committed-vs-realised budget-line figures —
	 * the aggregation is the sole source (declarative only).
	 *
	 * @return void
	 */
	public function testNoParallelReportingServiceComputesTheSameFigures(): void {
		$suspects = [];
		foreach (['lib/Service', 'lib/Lifecycle'] as $dir) {
			$absolute = __DIR__ . '/../../../' . $dir;
			$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS));
			foreach ($iterator as $file) {
				if ($file->getExtension() !== 'php') {
					continue;
				}

				$contents = (string)file_get_contents($file->getPathname());
				if (str_contains($contents, 'committedVsRealisedPerBudgetLine') === true) {
					$suspects[] = $file->getPathname();
				}
			}
		}

		self::assertSame(
			[],
			$suspects,
			'No PHP service may reference/recompute the committedVsRealisedPerBudgetLine aggregation name — it must stay declarative-only (ADR-031)'
		);

	}//end testNoParallelReportingServiceComputesTheSameFigures()

	/**
	 * Fragment merges additively onto the monolith without dropping the
	 * existing Commitment/CommitmentLine/Budget schemas.
	 *
	 * @return void
	 */
	public function testFragmentSchemasStillPresent(): void {
		$schemas = $this->fragment()['components']['schemas'];
		foreach (['Commitment', 'CommitmentLine', 'CommitmentMovement', 'Mandate', 'ApprovalStep', 'CommitmentBudget'] as $name) {
			self::assertArrayHasKey($name, $schemas, "Fragment must still declare $name");
		}

	}//end testFragmentSchemasStillPresent()

	/**
	 * Task 6: three auto-materialised-style Commitment seed objects
	 * (purchase_order/frameworkAgreement/grant_decision), each with at
	 * least one CommitmentLine and every regel's coderingscombinatie
	 * covered by a seeded Budget, so the drilldown is populated on a fresh
	 * install.
	 *
	 * @return void
	 */
	public function testSeedDataPopulatesDrilldown(): void {
		$objects = $this->fragment()['objects'];

		$commitments = array_values(array_filter($objects, static fn ($o) => $o['@self']['schema'] === 'Commitment'));
		$rules = array_values(array_filter($objects, static fn ($o) => $o['@self']['schema'] === 'CommitmentLine'));
		$budgets = array_values(array_filter($objects, static fn ($o) => $o['@self']['schema'] === 'CommitmentBudget'));

		self::assertGreaterThanOrEqual(3, count($commitments));
		self::assertGreaterThanOrEqual(1, count($budgets));

		$soorten = array_map(static fn ($v) => $v['kind'], $commitments);
		foreach (['purchase_order', 'frameworkAgreement', 'grant_decision'] as $expected) {
			self::assertContains($expected, $soorten, "Seed must include a $expected Commitment");
		}

		foreach ($commitments as $v) {
			self::assertNotEmpty($v['sourceReference'] ?? '', "Seeded Commitment {$v['commitmentNumber']} must carry a bronReferentie");
			$ownRules = array_filter($rules, static fn ($r) => $r['commitment'] === $v['commitmentNumber']);
			self::assertNotEmpty($ownRules, "Seeded Commitment {$v['commitmentNumber']} must have at least one CommitmentLine");

			foreach ($ownRules as $rule) {
				$matchingBudget = array_filter(
					$budgets,
					static fn ($b) => $b['programmeCode'] === $rule['programme']
						&& $b['financialYear'] === $rule['financialYear']
						&& $b['costCentre'] === $rule['costCentre']
				);
				$slug = $rule['@self']['slug'];
				self::assertNotEmpty($matchingBudget, "Regel $slug must have a matching seeded Budget");
			}
		}//end foreach

	}//end testSeedDataPopulatesDrilldown()
}//end class
