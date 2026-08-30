<?php

/**
 * Unit tests for the bookkeeping-subsidie-verantwoording register fragment.
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
 * @spec openspec/changes/bookkeeping-subsidie-verantwoording/specs.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the T3 governance fragment is valid JSON, declares the two new
 * registers plus the audit-finding template, wires their declarative lifecycles
 * and notifications, and merges additively onto the monolith (ADR-037).
 */
final class SubsidieVerantwoordingFragmentTest extends TestCase {
	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-subsidie-verantwoording.json';

	/**
	 * Absolute path to the monolith register file.
	 *
	 * @var string
	 */
	private string $registerPath = __DIR__ . '/../../../lib/Settings/shillinq_register.json';

	/**
	 * Absolute path to the audit-finding template seed.
	 *
	 * @var string
	 */
	private string $seedPath = __DIR__ . '/../../../lib/Settings/seeds/audit-finding-templates.json';

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
	 * Decode the fragment file.
	 *
	 * @return array<mixed>
	 */
	private function fragment(): array {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertIsArray($data);
		return $data;
	}//end fragment()

	/**
	 * The fragment file is present and valid JSON with components.schemas.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		$data = $this->fragment();
		self::assertArrayHasKey('schemas', $data['components']);
	}//end testFragmentIsValidJson()

	/**
	 * The fragment declares the two governance registers plus the template schema.
	 *
	 * @return void
	 */
	public function testFragmentDeclaresGovernanceSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];
		foreach (['SubsidieVerantwoording', 'AuditorStatement', 'AuditFindingTemplate'] as $name) {
			self::assertArrayHasKey($name, $schemas, "Fragment must declare $name");
		}
	}//end testFragmentDeclaresGovernanceSchemas()

	/**
	 * Both state-bearing schemas declare a status-driven lifecycle with the
	 * required transitions per REQ-SUBV-003 / REQ-SUBV-005.
	 *
	 * @return void
	 */
	public function testLifecyclesDeclareRequiredTransitions(): void {
		$schemas = $this->fragment()['components']['schemas'];

		$sv = $schemas['SubsidieVerantwoording']['x-openregister-lifecycle'];
		self::assertSame('status', $sv['field']);
		self::assertSame('draft', $sv['initialState']);
		foreach (['submit', 'approve', 'finalize', 'resubmit'] as $t) {
			self::assertArrayHasKey($t, $sv['transitions'], "SubsidieVerantwoording must declare transition $t");
		}
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\SubsidieVerantwoordingGuard::canApprove',
			$sv['transitions']['approve']['requires']
		);

		$as = $schemas['AuditorStatement']['x-openregister-lifecycle'];
		self::assertSame('status', $as['field']);
		self::assertSame('pending', $as['initialState']);
		foreach (['accept', 'approve', 'reject', 'conditional'] as $t) {
			self::assertArrayHasKey($t, $as['transitions'], "AuditorStatement must declare transition $t");
		}
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\AuditorStatementGuard::canApprove',
			$as['transitions']['approve']['requires']
		);
	}//end testLifecyclesDeclareRequiredTransitions()

	/**
	 * Both registers declare state-change notifications per REQ-SUBV-003/005 + ADR-031.
	 *
	 * @return void
	 */
	public function testSchemasDeclareNotifications(): void {
		$schemas = $this->fragment()['components']['schemas'];
		self::assertArrayHasKey('x-openregister-notifications', $schemas['SubsidieVerantwoording']);
		self::assertArrayHasKey('x-openregister-notifications', $schemas['AuditorStatement']);
		self::assertArrayHasKey('onSubmitted', $schemas['SubsidieVerantwoording']['x-openregister-notifications']);
		self::assertArrayHasKey('onConditional', $schemas['AuditorStatement']['x-openregister-notifications']);
	}//end testSchemasDeclareNotifications()

	/**
	 * Merging the fragment onto the monolith adds the new schemas without
	 * dropping the existing Subsidie schema (ADR-037 additive union).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = $this->fragment();

		$baseSchemaCount = count($base['components']['schemas']);

		$merged = $this->merge($base, $frag);
		$schemas = $merged['components']['schemas'];

		self::assertArrayHasKey('SubsidieVerantwoording', $schemas);
		self::assertArrayHasKey('AuditorStatement', $schemas);
		self::assertArrayHasKey('AuditFindingTemplate', $schemas);

		// The merge is purely additive: three new schemas, none dropped.
		self::assertSame($baseSchemaCount + 3, count($schemas));
		// Every pre-existing monolith schema survives the merge.
		foreach (array_keys($base['components']['schemas']) as $name) {
			self::assertArrayHasKey($name, $schemas, "Monolith schema $name must survive merge");
		}
	}//end testFragmentMergesAdditivelyOntoMonolith()

	/**
	 * The audit-finding template seed is valid JSON, ships >= 6 categories with
	 * the Awb 4.2 + VNG source, and every entry has the required template fields
	 * (REQ-SUBV-007).
	 *
	 * @return void
	 */
	public function testAuditFindingSeedShape(): void {
		self::assertFileExists($this->seedPath);
		$data = json_decode((string)file_get_contents($this->seedPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());

		self::assertSame('Awb 4.2 + VNG guidelines', $data['_meta']['source']);
		$templates = $data['auditFindingTemplates'];
		self::assertGreaterThanOrEqual(6, count($templates), 'Seed must ship at least 6 categories');

		$expectedCategories = ['eligibility', 'documentation', 'financial-control', 'tax', 'compliance', 'other'];
		$seenCategories = array_column($templates, 'categoryId');
		foreach ($expectedCategories as $cat) {
			self::assertContains($cat, $seenCategories, "Seed must include category $cat");
		}

		foreach ($templates as $tpl) {
			self::assertArrayHasKey('categoryId', $tpl);
			self::assertArrayHasKey('categoryName', $tpl);
			self::assertContains($tpl['severity'], ['critical', 'high', 'medium', 'low']);
		}
	}//end testAuditFindingSeedShape()
}//end class
