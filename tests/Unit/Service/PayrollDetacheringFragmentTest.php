<?php

/**
 * Unit tests for the bookkeeping-detachering-payroll-administratie register fragment.
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
 * @spec openspec/changes/bookkeeping-detachering-payroll-administratie/specs.md
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
 * Verifies the payroll + detachering fragment is valid JSON, declares the four
 * registers with their lifecycle/aggregations, and merges additively onto the
 * monolith without dropping existing schemas (ADR-037 / REQ-PAY-002/004/005).
 */
final class PayrollDetacheringFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-detachering-payroll-administratie.json';

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
	 * Load and decode the fragment file.
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
	 * The fragment file is present and valid JSON with a schemas block.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		$data = $this->fragment();
		self::assertArrayHasKey('schemas', $data['components']);
	}//end testFragmentIsValidJson()

	/**
	 * The fragment declares the four payroll registers.
	 *
	 * @return void
	 */
	public function testFragmentDeclaresFourSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];
		foreach (['payrollEmployee', 'Payroll', 'Deduction', 'DeterminationLetter'] as $name) {
			self::assertArrayHasKey($name, $schemas, "Fragment must declare $name");
		}
	}//end testFragmentDeclaresFourSchemas()

	/**
	 * The Payroll schema declares the draft->calculated->issued->paid lifecycle
	 * with guard-gated calculate and issue transitions (REQ-PAY-004).
	 *
	 * @return void
	 */
	public function testPayrollLifecycleAndGuards(): void {
		$payroll = $this->fragment()['components']['schemas']['Payroll'];
		$lifecycle = $payroll['x-openregister-lifecycle'];

		self::assertSame('status', $lifecycle['field']);
		self::assertSame('draft', $lifecycle['initialState']);
		foreach (['draft', 'calculated', 'issued', 'paid'] as $state) {
			self::assertArrayHasKey($state, $lifecycle['states'], "Payroll must declare state $state");
		}

		$transitions = $lifecycle['transitions'];
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\PayrollGuard::canCalculate',
			$transitions['calculate']['requires']
		);
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\PayrollGuard::canIssue',
			$transitions['issue']['requires']
		);

		// The issue transition materialises a GLTransaction (REQ-PAY-008).
		$issueActions = array_column($transitions['issue']['actions'], 'action');
		self::assertContains('materialise-gl-transaction', $issueActions);
		self::assertContains('generate-determination-letter', $issueActions);
		self::assertContains('publish-cloudevent', $issueActions);
	}//end testPayrollLifecycleAndGuards()

	/**
	 * The Payroll schema declares the net-amount and annual-deduction
	 * aggregations (REQ-PAY-002 / REQ-PAY-005).
	 *
	 * @return void
	 */
	public function testPayrollAggregations(): void {
		$payroll = $this->fragment()['components']['schemas']['Payroll'];
		$aggregations = $payroll['x-openregister-aggregations'];

		// `from`, not `source`. AggregationRunner reads `from` and nothing else —
		// `source` was an inert key it never consulted, so these aggregated the
		// DECLARING schema instead of Deduction.
		self::assertArrayHasKey('netAmount', $aggregations);
		self::assertSame('Deduction', $aggregations['netAmount']['from']);
		self::assertArrayNotHasKey('source', $aggregations['netAmount'], '`source` is not an engine key');

		self::assertArrayHasKey('annualEmployeeDeductions', $aggregations);
		self::assertSame('Deduction', $aggregations['annualEmployeeDeductions']['from']);
		self::assertContains('deductionType', $aggregations['annualEmployeeDeductions']['groupBy']);

		// The `@self` correlation became a groupBy DIMENSION. No caller supplies a
		// parent row, so `payrollId: "@self.id"` stayed a literal string and matched
		// nothing — an empty result under HTTP 200. Grouping by the same field needs
		// no parent row and is narrowed per record through extraFilter.
		self::assertContains('payrollId', $aggregations['netAmount']['groupBy']);
		self::assertArrayNotHasKey('payrollId', ($aggregations['netAmount']['filter'] ?? []));
	}//end testPayrollAggregations()

	/**
	 * BSN is flagged as PII and the Employee declares its lifecycle (REQ-PAY-012).
	 *
	 * @return void
	 */
	public function testEmployeeBsnIsPiiFlagged(): void {
		$employee = $this->fragment()['components']['schemas']['payrollEmployee'];
		$bsn = $employee['properties']['bsn'];

		self::assertTrue(($bsn['pii'] ?? false), 'BSN must be flagged pii');
		self::assertTrue(($bsn['nullable'] ?? false), 'BSN must be nullable (B2B contractors have none)');
		self::assertSame('active', $employee['x-openregister-lifecycle']['initialState']);
	}//end testEmployeeBsnIsPiiFlagged()

	/**
	 * Merging the fragment onto the monolith adds the four schemas and the seed
	 * objects without dropping any existing schema (ADR-037).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = $this->fragment();

		$existingSchemas = array_keys($base['components']['schemas']);
		$baseObjectCount = count($base['objects'] ?? []);

		$merged = $this->merge($base, $frag);
		$schemas = $merged['components']['schemas'];

		foreach (['payrollEmployee', 'Payroll', 'Deduction', 'DeterminationLetter'] as $name) {
			self::assertArrayHasKey($name, $schemas, "$name must be present after merge");
		}

		// No pre-existing schema is dropped.
		foreach ($existingSchemas as $name) {
			self::assertArrayHasKey($name, $schemas, "Pre-existing schema $name must survive merge");
		}

		// Seed objects are unioned onto the monolith objects list.
		self::assertGreaterThan(
			$baseObjectCount,
			count($merged['objects']),
			'Fragment seed objects must be appended, not replace the monolith objects'
		);
	}//end testFragmentMergesAdditivelyOntoMonolith()

	/**
	 * Every fragment seed object references one of the four payroll schemas with
	 * a slug (so OpenRegister import is idempotent by slug, REQ-PAY-022/029).
	 *
	 * @return void
	 */
	public function testSeedObjectsAreWellFormed(): void {
		$objects = $this->fragment()['objects'];
		self::assertNotEmpty($objects);
		$allowed = ['payrollEmployee', 'Payroll', 'Deduction', 'DeterminationLetter'];

		foreach ($objects as $object) {
			self::assertArrayHasKey('@self', $object);
			self::assertSame('shillinq', $object['@self']['register']);
			self::assertContains($object['@self']['schema'], $allowed);
			self::assertNotEmpty($object['@self']['slug'] ?? '');
		}
	}//end testSeedObjectsAreWellFormed()
}//end class
