<?php

/**
 * Register schema tests for abstract-order-primitive.
 *
 * Asserts the shipped `OrderPrimitive` schema against the register JSON:
 *  - exactly ONE `OrderPrimitive` schema definition exists (no slug collision);
 *  - the bare `Order` slug is NEVER used (issue #503 regression guard — a
 *    live, foreign `decidesk` schema (id 1585) already holds slug `order`,
 *    case-insensitively, in the shared organisation; OpenRegister's schema
 *    slug lookup is case-insensitive AND bypasses multitenancy on import, so
 *    a slug is unique instance-wide, not per-app — see
 *    zz-order-primitive.json's _meta description);
 *  - exactly ONE `Grant` schema definition survives — the pre-existing
 *    WBSO/BBV/NSO/Tozo stub — regression guard for the live collision bug
 *    found during this build (the earlier partial work's `Grant` extension
 *    schema in zz-order-extensions.json deep-merged onto it via
 *    SettingsService::deepMergeConfig, corrupting the unrelated schema);
 *  - orderType/direction/state/administrationId/orderNumber are required;
 *  - the `state` lifecycle is type-gated: every `states` and `transitions`
 *    entry carries an `orderType` tag, and no state/transition leaks across
 *    types (REQ-ORD-002);
 *  - the subsidie lifecycle vocabulary is identical to the retired Subsidie
 *    schema's (aanvraag..afgehandeld) and the purchase vocabulary identical
 *    to PurchaseOrder's (draft..cancelled).
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
 * @spec openspec/changes/abstract-order-primitive/specs/order-primitive/spec.md
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
 * Verifies the shipped Order primitive schema + its type-gated lifecycle.
 */
final class OrderPrimitiveSchemaTest extends TestCase {

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
	 * Collect every schema definition (slug => definition) across all
	 * registers, keyed by nothing (a list, so duplicates are visible).
	 *
	 * @return array<int, array{file: string, slug: string, def: array<string,mixed>}>
	 */
	private function allSchemaDefinitions(): array {
		$found = [];
		$files = glob($this->settingsDir . '/{*.json,register.d/*.json}', GLOB_BRACE);
		foreach (($files ?: []) as $file) {
			$data = json_decode((string)file_get_contents($file), true);
			if (is_array($data) === false) {
				continue;
			}

			$schemas = ($data['components']['schemas'] ?? []);
			if (is_array($schemas) === false) {
				continue;
			}

			foreach ($schemas as $slug => $def) {
				if (is_array($def) === false || isset($def['properties']) === false) {
					// Skip annotation-only overlay entries (e.g. the
					// x-openregister-audit-trail opt-in fragment), which are
					// not schema BODIES — only real schema definitions declare
					// `properties`.
					continue;
				}

				$found[] = ['file' => basename($file), 'slug' => (string)$slug, 'def' => $def];
			}
		}//end foreach

		return $found;
	}//end allSchemaDefinitions()

	/**
	 * Exactly one `OrderPrimitive` schema definition exists across every
	 * register file.
	 *
	 * @return void
	 */
	public function testExactlyOneOrderSchemaDefinitionExists(): void {
		$matches = array_values(array_filter($this->allSchemaDefinitions(), static fn (array $row): bool => $row['slug'] === 'OrderPrimitive'));

		self::assertCount(
			1,
			$matches,
			'Expected exactly one OrderPrimitive schema definition; found in: ' . implode(', ', array_column($matches, 'file'))
		);

	}//end testExactlyOneOrderSchemaDefinitionExists()

	/**
	 * Regression guard (issue #503): the bare `Order` slug is NEVER used by
	 * any shillinq schema definition. OpenRegister's schema-import lookup
	 * (`ImportHandler::importSchema()` -> `SchemaMapper::find()`) is
	 * case-insensitive AND passes `_multitenancy: false`, so a slug is
	 * unique INSTANCE-WIDE across every app/organisation, not per-app. Live
	 * verification on 8080 found a `decidesk` schema (id 1585, slug `order`)
	 * already occupying that identifier in the SAME organisation as
	 * shillinq's own schemas — had abstract-order-primitive shipped a schema
	 * literally named `Order`, importing it would have matched and
	 * OVERWRITTEN decidesk's live schema via `SchemaMapper::updateFromArray()`.
	 *
	 * @return void
	 */
	public function testBareOrderSlugIsNeverUsed(): void {
		$slugs = array_column($this->allSchemaDefinitions(), 'slug');

		self::assertNotContains(
			'Order',
			$slugs,
			'The bare "Order" slug must never be reused — it collides (case-insensitively, '
			. 'instance-wide) with a live, foreign schema on shared instances (issue #503). '
			. 'Use a distinctive slug such as OrderPrimitive instead.'
		);

	}//end testBareOrderSlugIsNeverUsed()

	/**
	 * Regression guard: exactly one `Grant` schema definition survives (the
	 * pre-existing WBSO/BBV/NSO/Tozo stub). The abstract-order-primitive
	 * change's earlier partial build added a SECOND, colliding `Grant`
	 * definition (folded-Subsidie fields) in zz-order-extensions.json, which
	 * would deep-merge onto this one via SettingsService::deepMergeConfig and
	 * silently corrupt it. That file has been removed; this guards against
	 * it ever coming back.
	 *
	 * @return void
	 */
	public function testExactlyOneGrantSchemaDefinitionSurvives(): void {
		$matches = array_values(array_filter($this->allSchemaDefinitions(), static fn (array $row): bool => $row['slug'] === 'Grant'));

		self::assertCount(
			1,
			$matches,
			'Expected exactly one Grant schema definition (the WBSO/BBV/NSO/Tozo stub); found in: ' . implode(', ', array_column($matches, 'file'))
		);

		// It must be the WBSO stub, not a folded-Subsidie shape.
		self::assertArrayHasKey('grantId', $matches[0]['def']['properties'] ?? []);
		self::assertArrayNotHasKey('schemeArticle', $matches[0]['def']['properties'] ?? [], 'the Grant stub must not carry folded-Subsidie fields (collision regression)');

	}//end testExactlyOneGrantSchemaDefinitionSurvives()

	/**
	 * The Order schema's shared core matches REQ-ORD-001/002/003.
	 *
	 * @return void
	 */
	public function testOrderSchemaSharedCore(): void {
		$order = $this->orderSchema();

		self::assertContains('administrationId', $order['required']);
		self::assertContains('orderType', $order['required']);
		self::assertContains('direction', $order['required']);
		self::assertContains('orderNumber', $order['required']);
		self::assertContains('state', $order['required']);

		$orderTypeEnum = $order['properties']['orderType']['enum'];
		self::assertContains('purchase', $orderTypeEnum);
		self::assertContains('subsidy', $orderTypeEnum);
		self::assertContains('engagement', $orderTypeEnum);

		self::assertArrayHasKey('subsidy', $order['properties']);
		self::assertArrayHasKey('purchase', $order['properties']);
		self::assertArrayHasKey('engagement', $order['properties']);

	}//end testOrderSchemaSharedCore()

	/**
	 * The subsidie lifecycle vocabulary is identical to the retired Subsidie
	 * schema's (REQ-ORD-002: "identical to the retired Subsidie schema").
	 *
	 * @return void
	 */
	public function testSubsidieLifecycleVocabularyIdenticalToRetiredSchema(): void {
		$order = $this->orderSchema();
		$lifecycle = $order['x-openregister-lifecycle'];
		$subsidyStates = $this->statesForType($lifecycle, 'subsidy');

		self::assertSame(
			['request', 'granted', 'determined', 'disbursed', 'reclaimed', 'handled'],
			$subsidyStates
		);

	}//end testSubsidieLifecycleVocabularyIdenticalToRetiredSchema()

	/**
	 * The purchase lifecycle vocabulary is identical to PurchaseOrder's.
	 *
	 * @return void
	 */
	public function testPurchaseLifecycleVocabularyIdenticalToPurchaseOrderSchema(): void {
		$order = $this->orderSchema();
		$lifecycle = $order['x-openregister-lifecycle'];
		$purchaseStates = $this->statesForType($lifecycle, 'purchase');

		self::assertSame(
			['draft', 'approved', 'sent', 'partial_received', 'fully_received', 'invoiced', 'closed', 'cancelled'],
			$purchaseStates
		);

	}//end testPurchaseLifecycleVocabularyIdenticalToPurchaseOrderSchema()

	/**
	 * The engagement lifecycle vocabulary is identical to DBAOpdracht's
	 * intakeStatus vocabulary.
	 *
	 * @return void
	 */
	public function testEngagementLifecycleVocabularyIdenticalToDbaOpdrachtSchema(): void {
		$order = $this->orderSchema();
		$lifecycle = $order['x-openregister-lifecycle'];
		$engagementStates = $this->statesForType($lifecycle, 'engagement');

		self::assertSame(
			['DRAFT', 'INTAKE_REQUIRED', 'INTAKE_COMPLETED', 'ACTIVE', 'ENDED'],
			$engagementStates
		);

	}//end testEngagementLifecycleVocabularyIdenticalToDbaOpdrachtSchema()

	/**
	 * Every transition is scoped to exactly one orderType, and its `from`/`to`
	 * states must ALL belong to that same orderType's own state set — a
	 * subsidie Order can never attempt a purchase transition and vice-versa
	 * (REQ-ORD-002 lifecycle gating).
	 *
	 * @return void
	 */
	public function testEveryTransitionIsGatedToItsOwnOrderTypeStates(): void {
		$order = $this->orderSchema();
		$lifecycle = $order['x-openregister-lifecycle'];

		$statesByType = [
			'subsidy' => $this->statesForType($lifecycle, 'subsidy'),
			'purchase' => $this->statesForType($lifecycle, 'purchase'),
			'engagement' => $this->statesForType($lifecycle, 'engagement'),
		];

		foreach ($lifecycle['transitions'] as $action => $spec) {
			$orderType = ($spec['orderType'] ?? null);
			self::assertIsString($orderType, "transition \"{$action}\" must declare an orderType gate");
			self::assertArrayHasKey($orderType, $statesByType, "transition \"{$action}\" has an unknown orderType \"{$orderType}\"");

			$legalStates = $statesByType[$orderType];

			$from = (is_array($spec['from']) === true ? $spec['from'] : [$spec['from']]);
			foreach ($from as $fromState) {
				self::assertContains(
					$fromState,
					$legalStates,
					"transition \"{$action}\" (orderType={$orderType}) has `from` state \"{$fromState}\" outside its own vocabulary"
				);
			}

			self::assertContains(
				$spec['to'],
				$legalStates,
				"transition \"{$action}\" (orderType={$orderType}) has `to` state \"{$spec['to']}\" outside its own vocabulary"
			);
		}//end foreach

	}//end testEveryTransitionIsGatedToItsOwnOrderTypeStates()

	/**
	 * RBAC roles are declared for every domain the Order primitive folds.
	 *
	 * @return void
	 */
	public function testOrderSchemaDeclaresRbacRoles(): void {
		$order = $this->orderSchema();
		$roles = array_keys($order['x-openregister-rbac']['roles']);

		self::assertContains('subsidie-coordinator', $roles);
		self::assertContains('bookkeeper', $roles);
		self::assertContains('ondernemer', $roles);
		self::assertContains('auditor', $roles);

	}//end testOrderSchemaDeclaresRbacRoles()

	/**
	 * Locate the canonical OrderPrimitive schema definition.
	 *
	 * @return array<string,mixed>
	 */
	private function orderSchema(): array {
		$matches = array_values(array_filter($this->allSchemaDefinitions(), static fn (array $row): bool => $row['slug'] === 'OrderPrimitive'));
		self::assertNotEmpty($matches, 'OrderPrimitive schema definition not found');

		return $matches[0]['def'];
	}//end orderSchema()

	/**
	 * Every state name tagged with the given orderType, in declaration order.
	 *
	 * @param array<string,mixed> $lifecycle The x-openregister-lifecycle block.
	 * @param string $orderType The orderType to filter states by.
	 *
	 * @return array<int,string>
	 */
	private function statesForType(array $lifecycle, string $orderType): array {
		$names = [];
		foreach ($lifecycle['states'] as $name => $def) {
			if (($def['orderType'] ?? null) === $orderType) {
				$names[] = (string)$name;
			}
		}

		return $names;
	}//end statesForType()
}//end class
