<?php

/**
 * AdministrationJurisdiction tests (ledger-foreign-legislation)
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Jurisdiction
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

namespace OCA\Shillinq\Tests\Unit\Service\Jurisdiction;

use OCA\Shillinq\Service\Jurisdiction\AdministrationJurisdiction;
use OCA\Shillinq\Service\Jurisdiction\JurisdictionRefusedException;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * An administration's jurisdiction: read by uuid or code, NL by default, and the refusal it drives.
 *
 * @covers \OCA\Shillinq\Service\Jurisdiction\AdministrationJurisdiction
 * @covers \OCA\Shillinq\Service\Jurisdiction\JurisdictionRefusedException
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 *
 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
 */
final class AdministrationJurisdictionTest extends TestCase {

	/**
	 * The lookup over a Dutch and a Belgian administration and their transactions.
	 *
	 * @return AdministrationJurisdiction
	 */
	private function jurisdiction(): AdministrationJurisdiction {
		$store = new InMemoryObjectServiceStub(
			[
				'Administration' => [
					['id' => 'a-nl', 'administrationCode' => 'HVD', 'name' => 'Holding Van Dijk B.V.'],
					['id' => 'a-be', 'administrationCode' => 'VDS', 'name' => 'Van Dijk Services BV/SRL', 'jurisdiction' => 'BE'],
					['id' => 'a-xx', 'administrationCode' => 'BAD', 'name' => 'Malformed', 'jurisdiction' => 'Belgium'],
				],
				'GLTransaction' => [
					['id' => 't-1', 'administrationId' => 'VDS', 'state' => 'draft'],
					['id' => 't-2', 'administrationId' => 'VDS', 'state' => 'posted'],
					['id' => 't-3', 'administrationId' => 'HVD', 'state' => 'draft'],
				],
			]
		);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default=''): string => $default);

		return new AdministrationJurisdiction(objectService: $store, appConfig: $config);
	}//end jurisdiction()

	/**
	 * A stored value is two upper-case letters; anything else is NL.
	 *
	 * @return void
	 */
	public function testAValueIsNormalisedToTwoLetters(): void {
		$this->assertSame('BE', AdministrationJurisdiction::normalise(value: ' be '));
		$this->assertSame('NL', AdministrationJurisdiction::normalise(value: ''));
		$this->assertSame('NL', AdministrationJurisdiction::normalise(value: 'Belgium'));
		$this->assertSame('NL', AdministrationJurisdiction::normalise(value: null));
		$this->assertSame('NL', AdministrationJurisdiction::normalise(value: 32));
	}//end testAValueIsNormalisedToTwoLetters()

	/**
	 * The jurisdiction is read by uuid or by administrationCode; none, unknown or malformed is NL.
	 *
	 * @return void
	 */
	public function testTheJurisdictionIsReadByUuidOrCode(): void {
		$jurisdiction = $this->jurisdiction();

		$this->assertSame('BE', $jurisdiction->of(administrationId: 'a-be'));
		$this->assertSame('BE', $jurisdiction->of(administrationId: 'VDS'));
		$this->assertSame('NL', $jurisdiction->of(administrationId: 'HVD'));
		$this->assertSame('NL', $jurisdiction->of(administrationId: 'BAD'));
		$this->assertSame('NL', $jurisdiction->of(administrationId: 'unknown'));
		$this->assertSame('NL', $jurisdiction->of(administrationId: '  '));
	}//end testTheJurisdictionIsReadByUuidOrCode()

	/**
	 * A Dutch-only output passes for a Dutch administration and is refused for a Belgian one, naming it.
	 *
	 * @return void
	 */
	public function testADutchOnlyOutputIsRefusedForABelgianAdministration(): void {
		$jurisdiction = $this->jurisdiction();
		$jurisdiction->assertIs(administrationId: 'HVD', output: 'Dutch VAT return');

		try {
			$jurisdiction->assertIs(administrationId: 'VDS', output: 'Dutch VAT return');
			$this->fail('A Belgian administration was not refused.');
		} catch (JurisdictionRefusedException $e) {
			$this->assertSame(
				'A Dutch VAT return is only produced for an administration under NL rules; this administration is kept under BE rules.',
				$e->getMessage()
			);
			$this->assertSame('Dutch VAT return', $e->getOutput());
			$this->assertSame('BE', $e->getJurisdiction());
			$this->assertSame('NL', $e->getRequired());
		}
	}//end testADutchOnlyOutputIsRefusedForABelgianAdministration()

	/**
	 * Only a posted or reversed transaction counts as a posting.
	 *
	 * @return void
	 */
	public function testOnlyAPostedTransactionCountsAsAPosting(): void {
		$jurisdiction = $this->jurisdiction();

		$this->assertTrue($jurisdiction->hasPostings(administrationId: 'VDS'));
		$this->assertFalse($jurisdiction->hasPostings(administrationId: 'HVD'));
		$this->assertFalse($jurisdiction->hasPostings(administrationId: ''));
	}//end testOnlyAPostedTransactionCountsAsAPosting()
}//end class
