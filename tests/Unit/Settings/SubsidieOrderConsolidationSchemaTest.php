<?php

/**
 * Register schema-diff tests for consolidate-order-subsidie-collisions.
 *
 * Asserts the non-destructive schema consolidation against the shipped register
 * JSON:
 *  - exactly ONE `Subsidie` schema definition survives (no duplicate blocks);
 *  - the canonical `Subsidie` carries the UNION of every source definition's
 *    fields — no regulatory field dropped (regeling/beschikking/vaststelling,
 *    the five state-amounts, prestatie-verantwoording, repayment) AND the
 *    English operations fields + subsidieRegeling folded in;
 *  - the generic `Order` slug was freed (renamed to `BookingOrder`) while
 *    `SalesOrder` / `PurchaseOrder` remain their own schemas. UPDATE (issue
 *    #503, 2026-07-23): abstract-order-primitive does NOT reclaim the bare
 *    `Order` slug after all — live-verification on 8080 found it collides
 *    (case-insensitively, instance-wide — OpenRegister's schema-import lookup
 *    bypasses multitenancy) with a live, foreign `decidesk` schema (id 1585)
 *    in the same organisation. The freed `Order` slug therefore stays
 *    UNCLAIMED; the primitive ships under the distinct slug `OrderPrimitive`
 *    instead. See zz-order-primitive.json's _meta description for the full
 *    account.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/schema-consolidation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the consolidated Subsidie union + freed Order slug in the registers.
 */
final class SubsidieOrderConsolidationSchemaTest extends TestCase {

	/**
	 * Absolute path to lib/Settings.
	 *
	 * @var string
	 */
	private string $settingsDir;

	/**
	 * Set up the settings directory path.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settingsDir = dirname(__DIR__, 3) . '/lib/Settings';

	}//end setUp()

	/**
	 * Collect every schema definition (slug => properties) across all registers.
	 *
	 * @return array<int, array{file: string, slug: string, props: array<int, string>}>
	 */
	private function allSchemaDefinitions(): array {
		$found = [];
		$files = glob($this->settingsDir . '/{*.json,register.d/*.json}', GLOB_BRACE);
		foreach (($files ?: []) as $file) {
			$data = json_decode((string)file_get_contents($file), true);
			if (is_array($data) === false) {
				continue;
			}

			$this->walk($data, $file, $found);
		}

		return $found;
	}//end allSchemaDefinitions()

	/**
	 * Recursively collect schema-like dicts (have properties + slug/title).
	 *
	 * @param mixed $node The current node.
	 * @param string $file The source file.
	 * @param array<int, array{file: string, slug: string, props: array<int, string>}> $found Accumulator (by reference).
	 *
	 * @return void
	 */
	private function walk($node, string $file, array &$found): void {
		if (is_array($node) === false) {
			return;
		}

		if (isset($node['properties']) === true && is_array($node['properties']) === true
			&& (isset($node['slug']) === true || isset($node['title']) === true)
		) {
			$found[] = [
				'file' => $file,
				'slug' => (string)($node['slug'] ?? $node['title']),
				'props' => array_keys($node['properties']),
			];
		}

		foreach ($node as $value) {
			$this->walk($value, $file, $found);
		}

	}//end walk()

	/**
	 * Exactly one Subsidie schema definition survives, and no fragment redefines it.
	 *
	 * @return void
	 */
	public function testExactlyOneSubsidieDefinition(): void {
		$subsidy = array_values(
			array_filter($this->allSchemaDefinitions(), static fn ($s) => $s['slug'] === 'Subsidie')
		);

		$this->assertCount(
			1,
			$subsidy,
			'Expected exactly one Subsidie schema; found: ' . implode(', ', array_column($subsidy, 'file'))
		);
		$this->assertStringEndsWith('shillinq_register.json', $subsidy[0]['file']);

	}//end testExactlyOneSubsidieDefinition()

	/**
	 * The canonical Subsidie is the field UNION — no regulatory field dropped.
	 *
	 * @return void
	 */
	public function testCanonicalSubsidieIsTheFieldUnion(): void {
		$subsidy = array_values(
			array_filter($this->allSchemaDefinitions(), static fn ($s) => $s['slug'] === 'Subsidie')
		);
		$props = $subsidy[0]['props'];

		// Regulatory fields (Dutch ASV-model) MUST all survive.
		$regulatory = [
			'schemeName', 'schemeArticle', 'subsidyScheme',
			'decisionDate', 'decisionUri',
			'determinationDate', 'determinationUri',
			'requestedAmount', 'grantedAmount', 'determinedAmount', 'paidOutAmount', 'reclaimedAmount',
			'prestatieverantwoording', 'repaymentPlanId',
		];
		foreach ($regulatory as $field) {
			$this->assertContains($field, $props, 'Regulatory field dropped from Subsidie: ' . $field);
		}

		// The English operations vocabulary MUST also survive (union, no data loss).
		$englishUnion = [
			'awardAmount', 'awardDate', 'subsidyName', 'grantProgram', 'granteeOrganization',
			'approvingAuthority', 'attachmentUri', 'budgetYear', 'currency',
			'disbursementDate', 'hasRepaymentPlan', 'notes', 'purposeDescription', 'settlementDate',
		];
		foreach ($englishUnion as $field) {
			$this->assertContains($field, $props, 'Operations field dropped from Subsidie union: ' . $field);
		}

	}//end testCanonicalSubsidieIsTheFieldUnion()

	/**
	 * The generic Order slug is freed and stays UNCLAIMED; BookingOrder +
	 * SalesOrder/PurchaseOrder exist; the abstract-order-primitive ships
	 * under the distinct `OrderPrimitive` slug instead (issue #503).
	 *
	 * @return void
	 */
	public function testOrderSlugFreedAndSiblingsIntact(): void {
		$slugs = array_column($this->allSchemaDefinitions(), 'slug');

		// UPDATE (issue #503, 2026-07-23): the freed slug is NOT reclaimed.
		// Live-verification on 8080 found the bare `Order` slug collides
		// (case-insensitively, instance-wide) with a live, foreign `decidesk`
		// schema (id 1585) in the same organisation — reclaiming it would
		// have overwritten that schema on import. abstract-order-primitive
		// ships under `OrderPrimitive` instead, so `Order` stays free (0
		// definitions), not "claimed exactly once" as originally planned.
		$this->assertSame(
			0,
			count(array_filter($slugs, static fn (string $slug): bool => $slug === 'Order')),
			'Order slug must stay unclaimed — it collides instance-wide with a live foreign schema (issue #503)'
		);
		$this->assertContains(
			'OrderPrimitive',
			$slugs,
			'abstract-order-primitive must ship under the distinct OrderPrimitive slug, not the freed (collision-prone) Order slug'
		);
		$this->assertContains('BookingOrder', $slugs, 'Booking order must be renamed to BookingOrder');
		$this->assertContains('SalesOrder', $slugs, 'SalesOrder must remain its own schema');
		$this->assertContains('PurchaseOrder', $slugs, 'PurchaseOrder must remain its own schema');

	}//end testOrderSlugFreedAndSiblingsIntact()
}//end class
