<?php

/**
 * Unit tests for the bookkeeping-soft-close-flux register fragment.
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
 * @spec openspec/changes/bookkeeping-soft-close-flux/specs/bookkeeping-continuous-close/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the soft-close-flux fragment ships the 11 schemas, additively
 * augments GLTransaction.post with the PeriodStatusGuard precondition,
 * declares the three target lifecycles, and carries audit-trail metadata on
 * every new schema (REQ-CLS-001..010, ADR-022, ADR-037).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SoftCloseFluxFragmentTest extends TestCase {
	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-soft-close-flux.json';

	/**
	 * Absolute path to the monolith register file.
	 *
	 * @var string
	 */
	private string $registerPath = __DIR__ . '/../../../lib/Settings/shillinq_register.json';

	/**
	 * Schemas that REQ-CLS-001..010 mandate.
	 *
	 * @var array<int,string>
	 */
	private const NEW_SCHEMAS = [
		'PeriodStatus',
		'AutoAccrualRule',
		'AutoAccrualPosting',
		'CloseChecklistTemplate',
		'CloseChecklistInstance',
		'FluxRun',
		'FluxItem',
		'FluxAttribution',
		'MaterialityPolicy',
		'ContinuousCloseAlert',
		'CloseMetrics',
	];

	/**
	 * Invoke the private static SettingsService::deepMergeConfig().
	 *
	 * @param array<mixed> $base Base config.
	 * @param array<mixed> $overlay Fragment.
	 *
	 * @return array<mixed> Merged config.
	 */
	private function merge(array $base, array $overlay): array {
		$m = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$m->setAccessible(true);
		return $m->invoke(null, $base, $overlay);
	}//end merge()

	/**
	 * Load the fragment as an array.
	 *
	 * @return array<mixed>
	 */
	private function fragment(): array {
		return json_decode((string)file_get_contents($this->fragmentPath), true);
	}//end fragment()

	/**
	 * The fragment is present and valid JSON with the expected sections.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertArrayHasKey('schemas', $data['components']);
		self::assertArrayHasKey('objects', $data['components']);

	}//end testFragmentIsValidJson()

	/**
	 * Every required schema is declared (REQ-CLS-001..010).
	 *
	 * @return void
	 */
	public function testAllElevenSchemasDeclared(): void {
		$schemas = $this->fragment()['components']['schemas'];
		foreach (self::NEW_SCHEMAS as $name) {
			self::assertArrayHasKey($name, $schemas, "Fragment must declare schema $name");
			self::assertSame($name, $schemas[$name]['slug'], 'Schema slug must match name');
		}

	}//end testAllElevenSchemasDeclared()

	/**
	 * Every new schema carries audit-trail metadata (REQ-AT-001, ADR-022).
	 *
	 * @return void
	 */
	public function testEverySchemaHasAuditTrailEnabled(): void {
		$schemas = $this->fragment()['components']['schemas'];
		foreach (self::NEW_SCHEMAS as $name) {
			$schema = $schemas[$name];
			self::assertArrayHasKey('x-openregister-audit-trail', $schema, "$name must declare x-openregister-audit-trail");
			self::assertTrue($schema['x-openregister-audit-trail']['enabled'], "$name audit-trail must be enabled");
		}

	}//end testEverySchemaHasAuditTrailEnabled()

	/**
	 * PeriodStatus declares the 5-stage lifecycle with the four transitions (REQ-CLS-001).
	 *
	 * @return void
	 */
	public function testPeriodStatusLifecycleHasFiveStagesAndFourTransitions(): void {
		$lifecycle = $this->fragment()['components']['schemas']['PeriodStatus']['x-openregister-lifecycle'];
		self::assertSame('open', $lifecycle['initialState']);
		foreach (['open', 'soft-closed', 'hard-closed', 'audited', 'locked'] as $stage) {
			self::assertArrayHasKey($stage, $lifecycle['states'], "PeriodStatus lifecycle must declare $stage");
		}

		foreach (['softClose', 'hardClose', 'audit', 'lock'] as $transition) {
			self::assertArrayHasKey($transition, $lifecycle['transitions'], "PeriodStatus lifecycle must declare $transition");
		}

	}//end testPeriodStatusLifecycleHasFiveStagesAndFourTransitions()

	/**
	 * AutoAccrualPosting lifecycle is posted → reversed (REQ-CLS-010).
	 *
	 * @return void
	 */
	public function testAutoAccrualPostingLifecycle(): void {
		$lifecycle = $this->fragment()['components']['schemas']['AutoAccrualPosting']['x-openregister-lifecycle'];
		self::assertSame('posted', $lifecycle['initialState']);
		self::assertArrayHasKey('posted', $lifecycle['states']);
		self::assertArrayHasKey('reversed', $lifecycle['states']);
		self::assertArrayHasKey('reverse', $lifecycle['transitions']);

	}//end testAutoAccrualPostingLifecycle()

	/**
	 * CloseChecklistInstance lifecycle is pending → in-progress → completed (REQ-CLS-004).
	 *
	 * @return void
	 */
	public function testCloseChecklistInstanceLifecycle(): void {
		$lifecycle = $this->fragment()['components']['schemas']['CloseChecklistInstance']['x-openregister-lifecycle'];
		self::assertSame('pending', $lifecycle['initialState']);
		foreach (['pending', 'in-progress', 'completed'] as $state) {
			self::assertArrayHasKey($state, $lifecycle['states']);
		}

	}//end testCloseChecklistInstanceLifecycle()

	/**
	 * Merging the fragment augments GLTransaction.post additively (REQ-CLS-001, ADR-037).
	 *
	 * The PeriodStatusGuard precondition is appended without dropping any
	 * existing precondition / requires / action on the post transition.
	 *
	 * @return void
	 */
	public function testAugmentsGlTransactionPostAdditively(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = $this->fragment();

		$merged = $this->merge($base, $frag);
		$post = $merged['components']['schemas']['GLTransaction']['x-openregister-lifecycle']['transitions']['post'];

		self::assertContains(
			'OCA\\Shillinq\\Lifecycle\\PeriodStatusGuard::postingAllowed',
			$post['preconditions']
		);

		// BalanceGuard requires + actions survive the merge.
		self::assertSame('OCA\\Shillinq\\Lifecycle\\BalanceGuard::isBalanced', $post['requires']);
		self::assertArrayHasKey('actions', $post);

	}//end testAugmentsGlTransactionPostAdditively()

	/**
	 * Seed AutoAccrualRule objects cover the five calculation methods spec'd in REQ-CLS-003.
	 *
	 * @return void
	 */
	public function testSeedRulesCoverCalculationMethods(): void {
		$objects = $this->fragment()['components']['objects'];
		$methods = [];
		foreach ($objects as $object) {
			if (($object['@self']['schema'] ?? '') !== 'AutoAccrualRule') {
				continue;
			}
			$methods[] = $object['calculationMethod'] ?? '';
		}
		// Five rules, five methods represented (rent fixed, utilities percentage, interest
		// straight-line, salaries external-lookup, depreciation straight-line).
		self::assertContains('fixed-amount', $methods);
		self::assertContains('percentage-of-revenue', $methods);
		self::assertContains('straight-line-from-contract', $methods);
		self::assertContains('external-lookup', $methods);

	}//end testSeedRulesCoverCalculationMethods()

	/**
	 * Default CloseChecklistTemplate ships task-dependency edges (REQ-CLS-004).
	 *
	 * @return void
	 */
	public function testSeedChecklistTemplateHasDependencies(): void {
		$objects = $this->fragment()['components']['objects'];
		foreach ($objects as $object) {
			if (($object['@self']['schema'] ?? '') !== 'CloseChecklistTemplate') {
				continue;
			}
			$tasks = $object['tasks'];
			$hasDeps = false;
			foreach ($tasks as $task) {
				if (!empty($task['dependsOn'])) {
					$hasDeps = true;
					break;
				}
			}
			self::assertTrue($hasDeps, 'Default checklist template must carry at least one task dependency.');
			return;
		}
		self::fail('No CloseChecklistTemplate seed object found.');

	}//end testSeedChecklistTemplateHasDependencies()

	/**
	 * MaterialityPolicy seed ships special-rule overrides for cash + tax + revenue (REQ-CLS-005).
	 *
	 * @return void
	 */
	public function testSeedMaterialityPolicyHasSpecialRules(): void {
		$objects = $this->fragment()['components']['objects'];
		foreach ($objects as $object) {
			if (($object['@self']['schema'] ?? '') !== 'MaterialityPolicy') {
				continue;
			}
			self::assertArrayHasKey('cash', $object['specialRules']);
			self::assertArrayHasKey('tax', $object['specialRules']);
			self::assertArrayHasKey('revenue', $object['specialRules']);
			return;
		}
		self::fail('No MaterialityPolicy seed object found.');

	}//end testSeedMaterialityPolicyHasSpecialRules()
}
