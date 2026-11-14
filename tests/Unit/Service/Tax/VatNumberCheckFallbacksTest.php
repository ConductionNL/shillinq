<?php

/**
 * Unit tests for VatNumberCheck's arrival check and last-valid fallbacks.
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Service\Tax
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

namespace OCA\Shillinq\Tests\Unit\Service\Tax;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Service\Tax\VatNumberCheck;
use OCA\Shillinq\Service\ViesService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An arrived invoice without an id is not checked, a VIES failure never
 * reaches the arrival, and the last valid date falls back as documented.
 *
 * @covers \OCA\Shillinq\Service\Tax\VatNumberCheck
 */
class VatNumberCheckFallbacksTest extends TestCase {

	private ViesService&MockObject $vies;

	private ObjectServiceInterface&MockObject $objects;

	private LoggerInterface&MockObject $logger;

	/**
	 * The check over mocked VIES and object service.
	 *
	 * @return VatNumberCheck
	 */
	private function check(): VatNumberCheck {
		$this->vies = $this->createMock(ViesService::class);
		$this->vies->method('canonicalVatId')->willReturnCallback(static fn (string $vatId): string => strtoupper(str_replace(' ', '', $vatId)));
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$location = $this->createMock(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');

		return new VatNumberCheck($this->vies, $this->objects, $location, $this->logger);
	}//end check()

	/**
	 * A VIES answer.
	 *
	 * @param bool $valid  Valid.
	 * @param bool $outage Outage.
	 *
	 * @return array<string, mixed>
	 */
	private static function answer(bool $valid, bool $outage): array {
		return ['valid' => $valid, 'outage' => $outage, 'vatId' => 'BE0000000000', 'validationTimestamp' => '2026-11-13T10:00:00+00:00', 'validUntil' => '2026-12-13', 'name' => '', 'address' => ''];
	}//end answer()

	/**
	 * An arrived invoice without an id is not checked at all.
	 *
	 * @return void
	 */
	public function testAnInvoiceWithoutAnIdIsNotChecked(): void {
		$check = $this->check();
		$this->vies->expects($this->never())->method('validate');

		$this->assertNull($check->checkSellerOnArrival(['sellerVatId' => 'BE0000000000']));
	}//end testAnInvoiceWithoutAnIdIsNotChecked()

	/**
	 * A failing VIES call is logged and answered with null; nothing is written.
	 *
	 * @return void
	 */
	public function testAFailingCheckOnArrivalIsLoggedAndSwallowed(): void {
		$check = $this->check();
		$this->vies->method('validate')->willThrowException(new RuntimeException('VIES timeout'));
		$this->objects->expects($this->never())->method('patchObject');
		$this->logger->expects($this->once())->method('warning')->with(
			'VatNumberCheck: the seller VAT number of an arrived invoice was not checked',
			['invoiceId' => 'bill-1', 'exception' => 'VIES timeout']
		);

		$this->assertNull($check->checkSellerOnArrival(['id' => 'bill-1', 'sellerVatId' => 'BE0000000000', 'administrationId' => 'adm-1']));
	}//end testAFailingCheckOnArrivalIsLoggedAndSwallowed()

	/**
	 * An invalid number with no earlier valid date records no last valid date.
	 *
	 * @return void
	 */
	public function testAnInvalidNumberWithoutHistoryHasNoLastValidDate(): void {
		$check = $this->check();
		$this->vies->method('validate')->willReturn(self::answer(valid: false, outage: false));
		$this->vies->expects($this->never())->method('findRecentValid');
		$this->objects->expects($this->once())->method('patchObject')->with(
			'bill-1',
			['sellerVatIdValidationStatus' => 'invalid', 'sellerVatIdValidatedAt' => null],
			'shillinq',
			'SupplierInvoice'
		);

		$this->assertSame(
			['sellerVatIdValidationStatus' => 'invalid', 'sellerVatIdValidatedAt' => null],
			$check->checkSellerOnArrival(['id' => 'bill-1', 'sellerVatId' => 'BE0000000000', 'administrationId' => 'adm-1'])
		);
	}//end testAnInvalidNumberWithoutHistoryHasNoLastValidDate()

	/**
	 * During an outage with no recent valid check, there is no last valid date;
	 * with one, its timestamp is kept.
	 *
	 * @return void
	 */
	public function testAnOutageFallsBackToARecentValidCheck(): void {
		$check = $this->check();
		$this->vies->method('validate')->willReturn(self::answer(valid: false, outage: true));
		$this->vies->expects($this->exactly(2))->method('findRecentValid')->with('adm-1', 'BE0000000000')
			->willReturnOnConsecutiveCalls(null, ['validationTimestamp' => '2026-11-01T09:00:00+00:00']);

		$invoice = ['id' => 'bill-1', 'sellerVatId' => 'BE0000000000', 'administrationId' => 'adm-1'];

		$this->assertSame(['sellerVatIdValidationStatus' => 'vies_outage', 'sellerVatIdValidatedAt' => null], $check->checkSellerOnArrival($invoice));
		$this->assertSame(['sellerVatIdValidationStatus' => 'vies_outage', 'sellerVatIdValidatedAt' => '2026-11-01T09:00:00+00:00'], $check->checkSellerOnArrival($invoice));
	}//end testAnOutageFallsBackToARecentValidCheck()
}//end class
