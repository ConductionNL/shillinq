<?php

/**
 * Unit tests for the bookkeeping-period-close register fragment.
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
 * @spec openspec/changes/bookkeeping-period-close/specs.md
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
 * Verifies the period-close fragment is valid JSON, declares the PeriodClose
 * schema with its open → closing → closed → audit-locked lifecycle (REQ-PC-001,
 * REQ-PC-002), additively augments the monolith GLTransaction.post precondition
 * without dropping the existing balance guard / allocation action (REQ-PC-003 /
 * ADR-037), and ships consistent seed objects across varied states (REQ-PC-009).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PeriodCloseFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-period-close.json';

	/**
	 * Absolute path to the monolith register file.
	 *
	 * @var string
	 */
	private string $registerPath = __DIR__ . '/../../../lib/Settings/shillinq_register.json';

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
	 * The fragment declares the FiscalPeriod schema with all spec fields (REQ-PC-001, REQ-PC-002).
	 *
	 * @return void
	 */
	public function testDeclaresFiscalPeriodSchema(): void {
		$schema = $this->fragment()['components']['schemas']['FiscalPeriod'];
		self::assertSame('FiscalPeriod', $schema['slug']);

		$expected = [
			'periodId',
			'name',
			'administrationId',
			'startDate',
			'endDate',
			'fiscalYear',
			'state',
			'closedAt',
			'closedBy',
			'auditLockedAt',
			'auditLockedBy',
			'closeReason',
			'reopenedHistory',
			'taskChecklistItems',
			'aiFlags',
		];
		foreach ($expected as $field) {
			self::assertArrayHasKey($field, $schema['properties'], "FiscalPeriod must declare $field");
		}

		// The required set MUST include the human-readable name (REQ-PC-002 add-shillinq-period-close).
		self::assertContains('name', $schema['required']);

	}//end testDeclaresFiscalPeriodSchema()

	/**
	 * The lifecycle declares the four states and the transition role gates (REQ-PC-002, REQ-PC-008).
	 *
	 * @return void
	 */
	public function testDeclaresLifecycleStatesAndRoleGates(): void {
		$lifecycle = $this->fragment()['components']['schemas']['FiscalPeriod']['x-openregister-lifecycle'];
		self::assertSame('open', $lifecycle['initialState']);
		foreach (['open', 'closing', 'closed', 'audit-locked'] as $state) {
			self::assertArrayHasKey($state, $lifecycle['states'], "Lifecycle must declare state $state");
		}

		$transitions = $lifecycle['transitions'];
		self::assertContains('period-closer', $transitions['close']['roles']);
		self::assertContains('period-closer', $transitions['reopen']['roles']);
		self::assertContains('auditor', $transitions['lockForAudit']['roles']);
		// Reopen is gated on a close-reason precondition (REQ-PC-006).
		self::assertContains(
			'OCA\\Shillinq\\Lifecycle\\PeriodCloseGuard::closeReasonSupplied',
			$transitions['reopen']['preconditions']
		);
		// Close is gated on both the mandatory checklist (REQ-PC-002) and the
		// bank-reconciliation suspense worklist being empty (payment-control-guards REQ-PCG-003).
		self::assertContains(
			'OCA\\Shillinq\\Lifecycle\\PeriodCloseGuard::mandatoryChecklistResolved',
			$transitions['close']['preconditions']
		);
		self::assertContains(
			'OCA\\Shillinq\\Lifecycle\\PeriodCloseGuard::suspenseAccountDrained',
			$transitions['close']['preconditions']
		);

	}//end testDeclaresLifecycleStatesAndRoleGates()

	/**
	 * Merging the fragment augments GLTransaction.post additively (REQ-PC-003, ADR-037).
	 *
	 * The closed-period precondition is added while the monolith's existing
	 * balance guard (requires) and allocation action survive the merge.
	 *
	 * @return void
	 */
	public function testAugmentsGlTransactionPostAdditively(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = $this->fragment();

		$merged = $this->merge($base, $frag);
		$post = $merged['components']['schemas']['GLTransaction']['x-openregister-lifecycle']['transitions']['post'];

		// The new closed-period precondition is present.
		self::assertContains(
			'OCA\\Shillinq\\Lifecycle\\PeriodCloseGuard::periodOpen',
			$post['preconditions']
		);
		// The pre-existing balance guard + allocation action are NOT dropped.
		self::assertSame('OCA\\Shillinq\\Lifecycle\\BalanceGuard::isBalanced', $post['requires']);
		self::assertArrayHasKey('actions', $post);

		// FiscalPeriod joins the schema set without disturbing GLTransaction.
		self::assertArrayHasKey('FiscalPeriod', $merged['components']['schemas']);

	}//end testAugmentsGlTransactionPostAdditively()

	/**
	 * Seed objects target the declared schema, use valid states, and cover variety (REQ-PC-009).
	 *
	 * @return void
	 */
	public function testSeedObjectsAreConsistent(): void {
		$frag = $this->fragment();
		$objects = $frag['components']['objects'];
		self::assertNotEmpty($objects);

		$states = [];
		foreach ($objects as $object) {
			self::assertArrayHasKey('@self', $object);
			self::assertSame('shillinq', $object['@self']['register']);
			self::assertSame('FiscalPeriod', $object['@self']['schema']);
			self::assertContains(
				$object['state'],
				['open', 'closing', 'closed', 'audit-locked'],
				'Seed ' . $object['@self']['slug'] . ' has an invalid state'
			);

			// Closed / audit-locked seeds must carry their stamp (REQ-PC-002).
			if (in_array($object['state'], ['closed', 'audit-locked'], true) === true) {
				self::assertNotNull($object['closedAt'], $object['@self']['slug'] . ' closed seed needs closedAt');
				self::assertNotNull($object['closedBy'], $object['@self']['slug'] . ' closed seed needs closedBy');
			}

			if ($object['state'] === 'audit-locked') {
				self::assertNotNull($object['auditLockedAt'], $object['@self']['slug'] . ' needs auditLockedAt');
				self::assertNotNull($object['auditLockedBy'], $object['@self']['slug'] . ' needs auditLockedBy');
			}

			$states[$object['state']] = true;
		}//end foreach

		// The seed set demonstrates the full lifecycle for manual QA (design Seed Data).
		foreach (['open', 'closing', 'closed', 'audit-locked'] as $state) {
			self::assertArrayHasKey($state, $states, "Seed set should include a $state period");
		}

	}//end testSeedObjectsAreConsistent()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
