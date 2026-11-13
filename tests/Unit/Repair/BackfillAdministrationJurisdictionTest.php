<?php

/**
 * BackfillAdministrationJurisdiction tests (ledger-foreign-legislation)
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\Shillinq\Repair\BackfillAdministrationJurisdiction;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Administrations saved before jurisdictions existed are Dutch.
 *
 * @covers \OCA\Shillinq\Repair\BackfillAdministrationJurisdiction
 * @uses   \OCA\Shillinq\Service\SettingsService
 *
 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
 */
final class BackfillAdministrationJurisdictionTest extends TestCase {

	/**
	 * An empty jurisdiction becomes NL, a set one is kept, and a rerun changes nothing.
	 *
	 * @return void
	 */
	public function testEmptyJurisdictionsBecomeDutchAndOthersAreKept(): void {
		$store = new InMemoryObjectServiceStub(
			[
				'Administration' => [
					['id' => 'a-1', 'administrationCode' => 'HVD', 'name' => 'Holding Van Dijk B.V.'],
					['id' => 'a-2', 'administrationCode' => 'VDS', 'name' => 'Van Dijk Services BV/SRL', 'jurisdiction' => 'BE'],
					['id' => 'a-3', 'administrationCode' => 'OLD', 'name' => 'Oude administratie', 'jurisdiction' => ''],
				],
			]
		);
		$settings = $this->createMock(RegisterLocation::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$step = new BackfillAdministrationJurisdiction($store, $settings, $this->createMock(LoggerInterface::class));

		$messages = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			static function (string $message) use (&$messages): void {
				$messages[] = $message;
			}
		);
		$step->run($output);
		$step->run($output);

		$this->assertSame(
			['Shillinq: 2 administration(s) given jurisdiction NL.', 'Shillinq: 0 administration(s) given jurisdiction NL.'],
			$messages
		);
		$this->assertSame('NL', $store->find(id: 'a-1', schema: 'Administration')->getObject()['jurisdiction']);
		$this->assertSame('BE', $store->find(id: 'a-2', schema: 'Administration')->getObject()['jurisdiction']);
		$this->assertSame('NL', $store->find(id: 'a-3', schema: 'Administration')->getObject()['jurisdiction']);
		$this->assertStringContainsString('jurisdiction', $step->getName());
	}//end testEmptyJurisdictionsBecomeDutchAndOthersAreKept()

	/**
	 * A failing store is reported as a warning, not thrown.
	 *
	 * @return void
	 */
	public function testAFailingStoreIsReportedNotThrown(): void {
		$settings = $this->createMock(RegisterLocation::class);
		$settings->method('getRegisterSlug')->willThrowException(new \RuntimeException('no register'));
		$step = new BackfillAdministrationJurisdiction(new InMemoryObjectServiceStub([]), $settings, $this->createMock(LoggerInterface::class));
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with('Shillinq: giving administrations a jurisdiction failed: no register');

		$step->run($output);
	}//end testAFailingStoreIsReportedNotThrown()

	/**
	 * The register declares the jurisdiction on Administration: two letters, NL by default.
	 *
	 * @return void
	 */
	public function testTheSchemaDeclaresTheJurisdiction(): void {
		$property = RegisterSchema::schema('Administration')['properties']['jurisdiction'];

		$this->assertSame('NL', $property['default']);
		$this->assertSame('^[A-Z]{2}$', $property['pattern']);
	}//end testTheSchemaDeclaresTheJurisdiction()
}//end class
