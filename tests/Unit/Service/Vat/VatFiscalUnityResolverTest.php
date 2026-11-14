<?php

/**
 * Unit tests for VatFiscalUnityResolver.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Vat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-5.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Vat;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\Vat\VatFiscalUnityResolver;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Which VAT fiscal unity covers an administration in a period, and in what role.
 *
 * @covers \OCA\Shillinq\Service\Vat\VatFiscalUnityResolver
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class VatFiscalUnityResolverTest extends TestCase {

	/**
	 * A unity of a holding and two members from 2026-01-01, and an ended one.
	 *
	 * @var list<array<string,mixed>>
	 */
	private const UNITIES = [
		[
			'id' => 'vfu-ended', 'name' => 'Oude eenheid', 'administrationId' => 'adm-old', 'memberAdministrationIds' => ['adm-bouw'],
			'vatNumber' => 'NL009999999B01', 'startDate' => '2024-01-01', 'endDate' => '2025-12-31',
		],
		[
			'id' => 'vfu-1', 'name' => 'Fiscale eenheid Holding Voorbeeld', 'administrationId' => 'adm-holding',
			'memberAdministrationIds' => ['adm-bouw', 'adm-installatie', '', 7], 'vatNumber' => 'NL001234567B01',
			'startDate' => '2026-01-01', 'endDate' => null,
		],
	];

	/**
	 * The representative files for the unity over itself and every member.
	 *
	 * @return void
	 */
	public function testTheRepresentativeReadsItselfAndEveryMember(): void {
		$covering = $this->resolver()->covering(administrationId: 'adm-holding', startDate: '2026-07-01', endDate: '2026-09-30');

		$this->assertSame('vfu-1', $covering['unity']['id']);
		$this->assertSame(VatFiscalUnityResolver::ROLE_REPRESENTATIVE, $covering['role']);
		$this->assertSame(['adm-holding', 'adm-bouw', 'adm-installatie'], $covering['administrationIds']);
	}//end testTheRepresentativeReadsItselfAndEveryMember()

	/**
	 * A member is a member of the unity that runs in the period, not of the
	 * one that ended before it.
	 *
	 * @return void
	 */
	public function testAMemberIsCoveredByTheUnityThatRunsInThePeriod(): void {
		$resolver = $this->resolver();

		$now = $resolver->covering(administrationId: 'adm-bouw', startDate: '2026-07-01', endDate: '2026-09-30');
		$this->assertSame('vfu-1', $now['unity']['id']);
		$this->assertSame(VatFiscalUnityResolver::ROLE_MEMBER, $now['role']);

		$before = $resolver->covering(administrationId: 'adm-bouw', startDate: '2025-10-01', endDate: '2025-12-31');
		$this->assertSame('vfu-ended', $before['unity']['id']);
	}//end testAMemberIsCoveredByTheUnityThatRunsInThePeriod()

	/**
	 * A period before the unity starts, an administration outside it and an
	 * empty administration are covered by none.
	 *
	 * @return void
	 */
	public function testOutsideAUnityNothingCovers(): void {
		$resolver = $this->resolver();

		$this->assertNull($resolver->covering(administrationId: 'adm-installatie', startDate: '2025-10-01', endDate: '2025-12-31'));
		$this->assertNull($resolver->covering(administrationId: 'adm-other', startDate: '2026-07-01', endDate: '2026-09-30'));
		$this->assertNull($resolver->covering(administrationId: '', startDate: '2026-07-01', endDate: '2026-09-30'));
	}//end testOutsideAUnityNothingCovers()

	/**
	 * A period the unity's dates only touch is covered: it starts on the
	 * period's last day, or ends on its first.
	 *
	 * @return void
	 */
	public function testAUnityTouchingThePeriodCoversIt(): void {
		$resolver = $this->resolver();

		$this->assertNotNull($resolver->covering(administrationId: 'adm-holding', startDate: '2025-12-01', endDate: '2026-01-01'));
		$this->assertNotNull($resolver->covering(administrationId: 'adm-old', startDate: '2025-12-31', endDate: '2026-03-31'));
		$this->assertNull($resolver->covering(administrationId: 'adm-old', startDate: '2026-01-01', endDate: '2026-03-31'));
	}//end testAUnityTouchingThePeriodCoversIt()

	/**
	 * Unities that cannot be read are not taken as none: the caller sees the failure.
	 *
	 * @return void
	 */
	public function testAFailedReadIsNotSwallowed(): void {
		$service = $this->createMock(ObjectServiceInterface::class);
		$service->method('setRegister')->with('shillinq')->willReturnSelf();
		$service->method('setSchema')->with('VatFiscalUnity')->willReturnSelf();
		$service->method('findAll')->willThrowException(new RuntimeException('db down'));

		$this->expectException(RuntimeException::class);
		(new VatFiscalUnityResolver(objectService: $service, register: 'shillinq'))->covering(
			administrationId: 'adm-holding',
			startDate: '2026-07-01',
			endDate: '2026-09-30'
		);
	}//end testAFailedReadIsNotSwallowed()

	/**
	 * A unity is named by its name, else its VAT number, else its id.
	 *
	 * @return void
	 */
	public function testTheNameOfAUnity(): void {
		$this->assertSame('Fiscale eenheid Holding Voorbeeld', VatFiscalUnityResolver::nameOf(unity: self::UNITIES[1]));
		$this->assertSame('NL001234567B01', VatFiscalUnityResolver::nameOf(unity: ['name' => ' ', 'vatNumber' => 'NL001234567B01', 'id' => 'vfu-1']));
		$this->assertSame('vfu-1', VatFiscalUnityResolver::nameOf(unity: ['id' => 'vfu-1']));
	}//end testTheNameOfAUnity()

	/**
	 * A resolver over the in-memory unities.
	 *
	 * @return VatFiscalUnityResolver
	 */
	private function resolver(): VatFiscalUnityResolver {
		return new VatFiscalUnityResolver(
			objectService: new InMemoryObjectServiceStub(data: ['VatFiscalUnity' => self::UNITIES], findAllRendersEntities: true),
			register: 'shillinq'
		);
	}//end resolver()
}//end class
