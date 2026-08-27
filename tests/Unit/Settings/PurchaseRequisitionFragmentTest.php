<?php

/**
 * Unit tests for the purchase-requisition register fragment.
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
 * @spec openspec/specs/purchase-requisition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the Requisition + RequisitionLine schemas, the declarative
 * lifecycle wiring to the reused BudgetBlocker/RequisitionConversionGuard
 * guards, and the seed data.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PurchaseRequisitionFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/purchase-requisition.json';

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
	 * REQ-REQ-001: the fragment declares exactly the two new schemas.
	 *
	 * @return void
	 */
	public function testFragmentDeclaresBothSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];
		self::assertArrayHasKey('Requisition', $schemas);
		self::assertArrayHasKey('RequisitionLine', $schemas);

	}//end testFragmentDeclaresBothSchemas()

	/**
	 * REQ-REQ-001: Requisition carries the field contract BudgetBlocker and
	 * MandateEnforcer already read off a Commitment — programma, boekjaar,
	 * totaalbedrag_excl_btw, soort, administrationId — so the guards run
	 * unmodified against a Requisition object.
	 *
	 * @return void
	 */
	public function testRequisitionCarriesBudgetBlockerFieldContract(): void {
		$requisition = $this->fragment()['components']['schemas']['Requisition'];
		$properties = $requisition['properties'];

		foreach (['programme', 'financialYear', 'total_amount_excl_vat', 'kind', 'administrationId'] as $field) {
			self::assertArrayHasKey($field, $properties, "Requisition must declare $field for BudgetBlocker reuse");
		}

		foreach (['programme', 'financialYear', 'kind'] as $required) {
			self::assertContains($required, $requisition['required']);
		}

	}//end testRequisitionCarriesBudgetBlockerFieldContract()

	/**
	 * REQ-REQ-002: Requisition declares the draft -> submitted -> approved |
	 * rejected -> converted lifecycle.
	 *
	 * @return void
	 */
	public function testRequisitionDeclaresLifecycle(): void {
		$lifecycle = $this->fragment()['components']['schemas']['Requisition']['x-openregister-lifecycle'];

		self::assertSame('statusCode', $lifecycle['field']);
		self::assertSame('draft', $lifecycle['initialState']);

		foreach (['draft', 'submitted', 'approved', 'rejected', 'converted'] as $state) {
			self::assertArrayHasKey($state, $lifecycle['states']);
		}

		self::assertSame('draft', $lifecycle['transitions']['submit']['from']);
		self::assertSame('submitted', $lifecycle['transitions']['submit']['to']);

		self::assertSame('submitted', $lifecycle['transitions']['approve']['from']);
		self::assertSame('approved', $lifecycle['transitions']['approve']['to']);
		self::assertSame('OCA\\Shillinq\\Lifecycle\\BudgetBlocker::canCommit', $lifecycle['transitions']['approve']['requires']);

		self::assertSame('submitted', $lifecycle['transitions']['reject']['from']);
		self::assertSame('rejected', $lifecycle['transitions']['reject']['to']);

		self::assertSame('approved', $lifecycle['transitions']['convertToPO']['from']);
		self::assertSame('converted', $lifecycle['transitions']['convertToPO']['to']);
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\RequisitionConversionGuard::canConvert',
			$lifecycle['transitions']['convertToPO']['requires']
		);

	}//end testRequisitionDeclaresLifecycle()

	/**
	 * REQ-REQ-005: PurchaseOrder is referenced via convertedPurchaseOrderId
	 * and RequisitionLine.glAccountSuggestion carries forward to the PO.
	 *
	 * @return void
	 */
	public function testRequisitionLineDeclaresConversionFields(): void {
		$line = $this->fragment()['components']['schemas']['RequisitionLine'];
		self::assertContains('glAccountSuggestion', $line['required']);
		self::assertArrayHasKey('lineTotal', $line['properties']);
		self::assertArrayHasKey('x-openregister-calculations', $line);
		self::assertSame(
			'round(quantity * unitPrice)',
			$line['x-openregister-calculations']['lineTotal']['expression']
		);

		$requisition = $this->fragment()['components']['schemas']['Requisition'];
		self::assertArrayHasKey('convertedPurchaseOrderId', $requisition['properties']);
		self::assertTrue($requisition['properties']['convertedPurchaseOrderId']['nullable'] ?? false);

	}//end testRequisitionLineDeclaresConversionFields()

	/**
	 * ADR-031: no PHP class independently reimplements the approval-routing
	 * decision the fragment delegates to BudgetBlocker — the reuse is real,
	 * not aspirational.
	 *
	 * @return void
	 */
	public function testNoParallelApprovalServiceImplementsBudgetChecking(): void {
		$suspects = [];
		$absolute = __DIR__ . '/../../../lib/Service';
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}

			if (str_contains($file->getFilename(), 'Requisition') === false) {
				continue;
			}

			$contents = (string)file_get_contents($file->getPathname());
			if (str_contains($contents, 'authorised_amount') === true
				|| str_contains($contents, 'free_capacity') === true
			) {
				$suspects[] = $file->getPathname();
			}
		}

		self::assertSame(
			[],
			$suspects,
			'Requisition services must not reimplement budget-room arithmetic — BudgetBlocker::canCommit is the sole source (ADR-031)'
		);

	}//end testNoParallelApprovalServiceImplementsBudgetChecking()

	/**
	 * Fragment merges additively onto the monolith without dropping any
	 * sibling PO-3-way or verplichtingen schema.
	 *
	 * @return void
	 */
	public function testFragmentDoesNotRedeclareSiblingSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];
		self::assertArrayNotHasKey(
			'PurchaseOrder',
			$schemas,
			'PurchaseOrder is owned by bookkeeping-purchase-order-3way-01; this fragment must not redeclare it'
		);
		self::assertArrayNotHasKey(
			'Commitment',
			$schemas,
			'Commitment is owned by bookkeeping-verplichtingenadministratie; this fragment must not redeclare it'
		);

	}//end testFragmentDoesNotRedeclareSiblingSchemas()

	/**
	 * Seed data: at least one Requisition per lifecycle-reachable status
	 * (draft, submitted, approved), each with a matching RequisitionLine,
	 * and the approved one carries a preferredSupplierId (required to
	 * convert) plus a totaalbedrag_excl_btw that fits the seeded Budget for
	 * programma 5.1 / boekjaar 2026 in bookkeeping-verplichtingenadministratie.json.
	 *
	 * @return void
	 */
	public function testSeedDataCoversLifecycleStates(): void {
		$objects = $this->fragment()['objects'];
		$requisitions = array_values(array_filter($objects, static fn ($o) => $o['@self']['schema'] === 'Requisition'));
		$lines = array_values(array_filter($objects, static fn ($o) => $o['@self']['schema'] === 'RequisitionLine'));

		$statuses = array_map(static fn ($r) => $r['statusCode'], $requisitions);
		foreach (['draft', 'submitted', 'approved'] as $expected) {
			self::assertContains($expected, $statuses, "Seed must include a $expected Requisition");
		}

		foreach ($requisitions as $requisition) {
			$ownLines = array_filter($lines, static fn ($l) => $l['requisitionId'] === $requisition['requisitionNumber']);
			self::assertNotEmpty(
				$ownLines,
				"Seeded Requisition {$requisition['requisitionNumber']} must have at least one RequisitionLine"
			);

			$sum = array_sum(array_map(static fn ($l) => $l['lineTotal'], $ownLines));
			self::assertSame($requisition['total_amount_excl_vat'], $sum, "Seeded totaalbedrag_excl_btw must equal the sum of its lines' lineTotal");

			if ($requisition['statusCode'] === 'approved') {
				self::assertNotEmpty(
					$requisition['preferredSupplierId'] ?? '',
					'An approved seed Requisition must carry a preferredSupplierId so it can be converted'
				);
			}

			self::assertSame('5.1', $requisition['programme']);
			self::assertSame(2026, $requisition['financialYear']);
			// Free room on the seeded Budget (5.1/2026) is 500,000.00 - 25,000.00 =
			// 475,000.00 EUR (47500000 cents); every seed Requisition must fit.
			self::assertLessThanOrEqual(47500000, $requisition['total_amount_excl_vat']);
		}//end foreach

	}//end testSeedDataCoversLifecycleStates()
}//end class
