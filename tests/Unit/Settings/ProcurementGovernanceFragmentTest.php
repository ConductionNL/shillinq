<?php

/**
 * Unit tests for the procurement-governance register fragment.
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
 * @spec openspec/specs/procurement-governance/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the SupplierQualification + FrameworkAgreement schemas and that the
 * seed data covers the two demonstrable block paths (REQ-PG-002 / REQ-PG-004).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ProcurementGovernanceFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/procurement-governance.json';

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
	 * REQ-PG-002 / REQ-PG-004: the fragment declares exactly the two schemas.
	 *
	 * @return void
	 */
	public function testFragmentDeclaresBothSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];
		self::assertArrayHasKey('SupplierQualification', $schemas);
		self::assertArrayHasKey('FrameworkAgreement', $schemas);
	}//end testFragmentDeclaresBothSchemas()

	/**
	 * REQ-PG-002: SupplierQualification declares the fields the guard reads.
	 *
	 * @return void
	 */
	public function testSupplierQualificationDeclaresRequiredFields(): void {
		$schema = $this->fragment()['components']['schemas']['SupplierQualification'];
		foreach (['supplierId', 'statusCode', 'requiredDocuments', 'administrationId'] as $field) {
			self::assertArrayHasKey($field, $schema['properties'], $field . ' must be declared');
		}

		self::assertContains('qualified', $schema['properties']['statusCode']['enum']);
	}//end testSupplierQualificationDeclaresRequiredFields()

	/**
	 * REQ-PG-004: FrameworkAgreement declares the ceiling/drawdown fields.
	 *
	 * @return void
	 */
	public function testFrameworkAgreementDeclaresCeilingFields(): void {
		$schema = $this->fragment()['components']['schemas']['FrameworkAgreement'];
		foreach (['ceilingAmount', 'drawnAmount', 'supplierId', 'statusCode'] as $field) {
			self::assertArrayHasKey($field, $schema['properties'], $field . ' must be declared');
		}

		self::assertSame('integer', $schema['properties']['ceilingAmount']['type']);
	}//end testFrameworkAgreementDeclaresCeilingFields()

	/**
	 * The seed data demonstrates both block paths: an expired-document supplier
	 * and a near-ceiling active framework agreement.
	 *
	 * @return void
	 */
	public function testSeedCoversBlockPaths(): void {
		$objects = $this->fragment()['objects'];

		$expiredSupplier = null;
		$agreement = null;
		foreach ($objects as $object) {
			$schema = ($object['@self']['schema'] ?? '');
			if ($schema === 'SupplierQualification' && ($object['statusCode'] ?? '') === 'draft') {
				$expiredSupplier = $object;
			}

			if ($schema === 'FrameworkAgreement' && ($object['statusCode'] ?? '') === 'active') {
				$agreement = $object;
			}
		}

		self::assertNotNull($expiredSupplier, 'A draft (un-qualified) supplier seed is expected.');
		$today = date('Y-m-d');
		$expired = false;
		foreach (($expiredSupplier['requiredDocuments'] ?? []) as $document) {
			if (($document['expiresAt'] ?? '') !== '' && $document['expiresAt'] < $today) {
				$expired = true;
			}
		}

		self::assertTrue($expired, 'The draft supplier seed should carry an expired document.');

		self::assertNotNull($agreement, 'An active FrameworkAgreement seed is expected.');
		self::assertGreaterThan(
			($agreement['ceilingAmount'] - $agreement['drawnAmount']),
			300000,
			'The seed agreement should be near its ceiling so a €3 000 call-off blows it.'
		);
	}//end testSeedCoversBlockPaths()
}//end class
