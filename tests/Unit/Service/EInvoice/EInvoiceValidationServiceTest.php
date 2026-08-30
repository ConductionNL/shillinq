<?php

/**
 * Unit tests for EInvoiceValidationService (REQ-EINV-003).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\EInvoice
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/add-invoice-pdf-export-with-ubl-peppol-support/specs/bookkeeping-einvoicing-ubl-peppol/spec.md#req-einv-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\EInvoice;

use OCA\Shillinq\Service\EInvoice\EInvoiceValidationService;
use OCA\Shillinq\Service\Peppol\PeppolTransmissionPortInterface;
use OCA\Shillinq\Service\ViesService;
use PHPUnit\Framework\TestCase;

/**
 * Covers REQ-EINV-003 scenarios: malformed BTW-nummer blocks send; unknown
 * Peppol participant falls back gracefully; VIES outage degrades to a warning.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class EInvoiceValidationServiceTest extends TestCase {
	/**
	 * Build a stub ViesService that returns a fixed outcome regardless of input.
	 *
	 * @param array<string,mixed> $outcome The validate() return value.
	 *
	 * @return ViesService
	 */
	private function stubVies(array $outcome): ViesService {
		$vies = $this->createMock(ViesService::class);
		$vies->method('validate')->willReturn($outcome);

		return $vies;
	}//end stubVies()

	/**
	 * Build a stub Peppol port with a fixed lookupParticipant() return value.
	 *
	 * @param string|null $participantId Lookup return value.
	 *
	 * @return PeppolTransmissionPortInterface
	 */
	private function stubPort(?string $participantId): PeppolTransmissionPortInterface {
		$port = $this->createMock(PeppolTransmissionPortInterface::class);
		$port->method('lookupParticipant')->willReturn($participantId);

		return $port;
	}//end stubPort()

	/**
	 * A valid ARInvoice with a resolvable participant passes validation.
	 *
	 * @return array<string,mixed>
	 */
	private function validInvoice(): array {
		return [
			'customerId' => 'DEB-0001',
			'buyerLegalRegId' => '12340001',
			'buyerVatId' => 'NL001234567B01',
		];

	}//end validInvoice()

	/**
	 * REQ-EINV-003 scenario 1: malformed BTW-nummer blocks send.
	 *
	 * @return void
	 */
	public function testMalformedVatIdBlocksSend(): void {
		$invoice = $this->validInvoice();
		$invoice['buyerVatId'] = 'NL123';

		$service = new EInvoiceValidationService(
			vies: $this->stubVies(['valid' => false, 'outage' => false]),
			peppolPort: $this->stubPort('0106:00000000')
		);

		$result = $service->validate(administrationId: 'adm-1', arInvoice: $invoice);

		self::assertFalse($result['valid']);
		self::assertNotEmpty($result['errors']);
		self::assertSame('vat_format_invalid', $result['errors'][0]['code']);
		self::assertNull($result['peppolParticipantId'], 'no Peppol lookup should run once BTW format fails');

	}//end testMalformedVatIdBlocksSend()

	/**
	 * KvK numbers that are not exactly 8 digits are rejected.
	 *
	 * @return void
	 */
	public function testMalformedKvkBlocksSend(): void {
		$invoice = $this->validInvoice();
		$invoice['buyerLegalRegId'] = '123';

		$service = new EInvoiceValidationService(
			vies: $this->stubVies(['valid' => true, 'outage' => false]),
			peppolPort: $this->stubPort('0106:00000000')
		);

		$result = $service->validate(administrationId: 'adm-1', arInvoice: $invoice);

		self::assertFalse($result['valid']);
		$codes = array_column($result['errors'], 'code');
		self::assertContains('kvk_invalid', $codes);

	}//end testMalformedKvkBlocksSend()

	/**
	 * REQ-EINV-003 scenario 2: unknown Peppol participant falls back gracefully
	 * (valid stays true — this is not a hard validation error).
	 *
	 * @return void
	 */
	public function testUnknownPeppolParticipantIsAGracefulFallback(): void {
		$service = new EInvoiceValidationService(
			vies: $this->stubVies(['valid' => true, 'outage' => false]),
			peppolPort: $this->stubPort(null)
		);

		$result = $service->validate(administrationId: 'adm-1', arInvoice: $this->validInvoice());

		self::assertTrue($result['valid']);
		self::assertNull($result['peppolParticipantId']);
		$codes = array_column($result['warnings'], 'code');
		self::assertContains('peppol_participant_not_found', $codes);

	}//end testUnknownPeppolParticipantIsAGracefulFallback()

	/**
	 * REQ-EINV-003 scenario 3: a VIES outage degrades to a non-blocking warning
	 * on a syntactically valid BTW-nummer, and the operator may still proceed.
	 *
	 * @return void
	 */
	public function testViesOutageDegradesToWarning(): void {
		$service = new EInvoiceValidationService(
			vies: $this->stubVies(['valid' => false, 'outage' => true]),
			peppolPort: $this->stubPort('0106:00000000')
		);

		$result = $service->validate(administrationId: 'adm-1', arInvoice: $this->validInvoice());

		self::assertTrue($result['valid'], 'a VIES outage must not hard-block a syntactically valid BTW-nummer');
		$codes = array_column($result['warnings'], 'code');
		self::assertContains('vies_outage', $codes);
		self::assertNotNull($result['peppolParticipantId']);

	}//end testViesOutageDegradesToWarning()

	/**
	 * A fully valid invoice with a resolvable participant passes clean.
	 *
	 * @return void
	 */
	public function testFullyValidInvoicePassesWithParticipant(): void {
		$service = new EInvoiceValidationService(
			vies: $this->stubVies(['valid' => true, 'outage' => false]),
			peppolPort: $this->stubPort('0106:00000000')
		);

		$result = $service->validate(administrationId: 'adm-1', arInvoice: $this->validInvoice());

		self::assertTrue($result['valid']);
		self::assertSame([], $result['errors']);
		self::assertSame('0106:00000000', $result['peppolParticipantId']);

	}//end testFullyValidInvoicePassesWithParticipant()
}//end class
