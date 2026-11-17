<?php

/**
 * Unit tests for the tax-cbcr-and-pillar2-returns register fragment, merged
 * over the register and every fragment before it as SettingsService does:
 * marking a CbC report or a minimum tax return submitted asks for the
 * Belastingdienst reference (REQ-CBC-011), and the transitions keep their
 * states and guard.
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
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Settings;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The merged CbcrReturn and GlobeInformationReturn schemas.
 */
#[CoversNothing]
final class TaxCbcrPillar2ReturnsFragmentTest extends TestCase {

	/**
	 * The register and every fragment, merged in SettingsService's order.
	 *
	 * @return array<string,mixed> The merged schemas.
	 */
	private function schemas(): array {
		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$merge->setAccessible(true);
		$settings = __DIR__ . '/../../../lib/Settings';
		$config   = json_decode((string)file_get_contents($settings . '/shillinq_register.json'), true);
		$files    = glob($settings . '/register.d/*.json');
		sort($files);
		foreach ($files as $file) {
			$fragment = json_decode((string)file_get_contents($file), true);
			self::assertIsArray($fragment, basename($file));
			unset($fragment['_meta']);
			$config = $merge->invoke(null, $config, $fragment);
		}

		return $config['components']['schemas'];
	}//end schemas()

	/**
	 * Mark submitted on a CbC report asks for the reference and still needs a valid file.
	 *
	 * @return void
	 */
	public function testMarkingACbcReportSubmittedAsksForTheReference(): void {
		$submit = $this->schemas()['CbcrReturn']['x-openregister-lifecycle']['transitions']['submit'];

		self::assertSame('reconciled', $submit['from']);
		self::assertSame('submitted', $submit['to']);
		self::assertSame('OCA\\Shillinq\\Lifecycle\\CbcrPillar2Guard::canSubmitCbcrReturn', $submit['requires']);
		self::assertSame([['field' => 'taxAuthorityReference', 'required' => true]], $submit['inputs']);
	}//end testMarkingACbcReportSubmittedAsksForTheReference()

	/**
	 * A minimum tax return has a reference field, and Mark submitted asks for it.
	 *
	 * @return void
	 */
	public function testMarkingAMinimumTaxReturnSubmittedAsksForTheReference(): void {
		$schema = $this->schemas()['GlobeInformationReturn'];
		$submit = $schema['x-openregister-lifecycle']['transitions']['submit'];

		self::assertSame('string', $schema['properties']['taxAuthorityReference']['type']);
		self::assertSame('approved', $submit['from']);
		self::assertSame('submitted', $submit['to']);
		self::assertSame([['field' => 'taxAuthorityReference', 'required' => true]], $submit['inputs']);
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\CbcrPillar2Guard::canApproveGir',
			$schema['x-openregister-lifecycle']['transitions']['approve']['requires']
		);
	}//end testMarkingAMinimumTaxReturnSubmittedAsksForTheReference()
}//end class
