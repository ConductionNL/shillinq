<?php

/**
 * Unit tests for the administration-import-migration register fragment.
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
 * @spec openspec/changes/administration-import-migration/tasks.md#task-5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the import fragment declares both schemas with audit trail, the
 * fail-closed lifecycle (no validated→posting edge), and the five
 * canonical-dialect notification rules (REQ-AIM-001/002/004/010).
 */
final class AdministrationImportFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/administration-import-migration.json';

	/**
	 * Decode the fragment.
	 *
	 * @return array<string,mixed>
	 */
	private function fragment(): array {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertIsArray($data);
		return $data;
	}//end fragment()

	/**
	 * The fragment is valid JSON and declares both schemas with audit trail.
	 *
	 * @return void
	 */
	public function testSchemasPresentWithAuditTrail(): void {
		$schemas = $this->fragment()['components']['schemas'];

		foreach (['ImportBatch', 'ImportMapping'] as $name) {
			self::assertArrayHasKey($name, $schemas, "Fragment must declare $name");
			self::assertArrayHasKey('x-openregister-audit-trail', $schemas[$name]);
			self::assertTrue($schemas[$name]['x-openregister-audit-trail']['enabled']);
		}
	}//end testSchemasPresentWithAuditTrail()

	/**
	 * ImportBatch declares all 11 lifecycle states.
	 *
	 * @return void
	 */
	public function testLifecycleHasAllStates(): void {
		$lifecycle = $this->fragment()['components']['schemas']['ImportBatch']['x-openregister-lifecycle'];
		self::assertSame('status', $lifecycle['field']);
		self::assertSame('draft', $lifecycle['initialState']);

		$expected = [
			'draft',
			'parsing',
			'staged',
			'mapping',
			'validated',
			'validation_failed',
			'dry_run_complete',
			'posting',
			'posted',
			'posting_failed',
			'reversed',
		];
		foreach ($expected as $state) {
			self::assertArrayHasKey($state, $lifecycle['states'], "Missing state $state");
		}
	}//end testLifecycleHasAllStates()

	/**
	 * There is NO direct validated → posting edge: a dry-run is mandatory.
	 *
	 * @return void
	 */
	public function testNoDirectValidatedToPostingEdge(): void {
		$transitions = $this->fragment()['components']['schemas']['ImportBatch']['x-openregister-lifecycle']['transitions'];

		foreach ($transitions as $name => $transition) {
			$from = $transition['from'];
			$from = (is_array($from) === true ? $from : [$from]);
			if (in_array('validated', $from, true) === true) {
				self::assertNotSame('posting', $transition['to'], "Transition $name must not go validated→posting (dry-run mandatory)");
			}
		}

		// The only legal post predecessor is dry_run_complete.
		self::assertSame('dry_run_complete', $transitions['post']['from']);
		self::assertSame('posting', $transitions['post']['to']);

		// Dry-run is the only edge out of validated towards posting.
		self::assertSame('validated', $transitions['dryRun']['from']);
		self::assertSame('dry_run_complete', $transitions['dryRun']['to']);
	}//end testNoDirectValidatedToPostingEdge()

	/**
	 * Reversal is guarded by the open-period guard (REQ-AIM-009).
	 *
	 * @return void
	 */
	public function testReverseHasOpenPeriodGuard(): void {
		$transitions = $this->fragment()['components']['schemas']['ImportBatch']['x-openregister-lifecycle']['transitions'];
		self::assertSame('posted', $transitions['reverse']['from']);
		self::assertSame('reversed', $transitions['reverse']['to']);
		self::assertSame('OCA\\Shillinq\\Lifecycle\\ImportBatchGuard::canReverse', $transitions['reverse']['requires']);
	}//end testReverseHasOpenPeriodGuard()

	/**
	 * The five notification rules use the canonical dialect (REQ-AIM-010).
	 *
	 * @return void
	 */
	public function testFiveCanonicalNotificationRules(): void {
		$notifications = $this->fragment()['components']['schemas']['ImportBatch']['x-openregister-notifications'];

		$expected = [
			'validationFailed' => 'validation_failed',
			'dryRunComplete' => 'dry_run_complete',
			'posted' => 'posted',
			'postingFailed' => 'posting_failed',
			'reversed' => 'reversed',
		];

		self::assertCount(5, $notifications);

		foreach ($expected as $rule => $statusValue) {
			self::assertArrayHasKey($rule, $notifications, "Missing notification rule $rule");
			$n = $notifications[$rule];

			// Canonical trigger dialect: updated + condition on status.
			self::assertSame('updated', $n['trigger']['type']);
			self::assertSame('status', $n['trigger']['condition']['field']);
			self::assertSame('equals', $n['trigger']['condition']['operator']);
			self::assertSame($statusValue, $n['trigger']['condition']['value']);

			// Channels + recipients.
			self::assertSame(['nc-notification'], $n['channels']);
			self::assertContains(['kind' => 'field', 'field' => 'owner'], $n['recipients']);
			self::assertContains(['kind' => 'object-acl', 'permission' => 'manage'], $n['recipients']);

			// Bilingual metadata-only subjects.
			self::assertArrayHasKey('nl', $n['subject']);
			self::assertArrayHasKey('en', $n['subject']);
		}
	}//end testFiveCanonicalNotificationRules()

	/**
	 * No legacy notification dialect (object.create / title / message) is present.
	 *
	 * @return void
	 */
	public function testNoLegacyNotificationDialect(): void {
		$raw = (string)file_get_contents($this->fragmentPath);
		// Legacy dialect markers (ADR-031 gate-18): never object.create triggers
		// nor title/message-keyed notification bodies. ("title" legitimately
		// appears as schema metadata, so we assert the legacy notification
		// structures specifically.)
		self::assertStringNotContainsString('object.create', $raw);

		$notifications = $this->fragment()['components']['schemas']['ImportBatch']['x-openregister-notifications'];
		foreach ($notifications as $rule) {
			self::assertArrayNotHasKey('title', $rule, 'Legacy notification dialect: "title" key forbidden');
			self::assertArrayNotHasKey('message', $rule, 'Legacy notification dialect: "message" key forbidden');
			self::assertArrayHasKey('subject', $rule, 'Canonical dialect uses subject{nl,en}');
		}
	}//end testNoLegacyNotificationDialect()

	/**
	 * ImportMapping declares the mappingSource enum and required fields (REQ-AIM-004).
	 *
	 * @return void
	 */
	public function testImportMappingShape(): void {
		$schema = $this->fragment()['components']['schemas']['ImportMapping'];
		self::assertSame(['batchReference', 'sourceCode', 'mappingSource'], $schema['required']);
		$enum = $schema['properties']['mappingSource']['enum'];
		self::assertSame(['rgs-auto', 'profile-default', 'manual', 'unmapped'], $enum);
	}//end testImportMappingShape()

}//end class
