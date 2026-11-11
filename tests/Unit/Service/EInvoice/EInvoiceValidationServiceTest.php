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

	/**
	 * Pin the full verdict and every outbound check over each identifier and
	 * VIES outcome.
	 *
	 * @return void
	 */
	public function testEveryVerdictAndCheckIsExact(): void {
		$calls = [];
		$run = function (array $invoice, array $vies, ?string $participant) use (&$calls): array {
			$viesMock = $this->createMock(ViesService::class);
			$viesMock->method('validate')->willReturnCallback(
				function (string $administrationId, string $vatId) use (&$calls, $vies): array {
					$calls[] = ['vies', $administrationId, $vatId];
					return $vies;
				}
			);
			$port = $this->createMock(PeppolTransmissionPortInterface::class);
			$port->method('lookupParticipant')->willReturnCallback(
				function (string $administrationId, string $partyId) use (&$calls, $participant): ?string {
					$calls[] = ['peppol', $administrationId, $partyId];
					return $participant;
				}
			);
			return (new EInvoiceValidationService($viesMock, $port))->validate('adm', $invoice);
		};
		$ok = ['valid' => true, 'outage' => false];
		$invoice = ['customerId' => ' DEB-1 ', 'buyerLegalRegId' => ' 12345678 ', 'buyerVatId' => ' NL123456789B01 '];
		$cases = [
			'allValid' => [$invoice, $ok, '0106:1'],
			'noParticipant' => [$invoice, $ok, null],
			'outage' => [$invoice, ['valid' => false, 'outage' => true], '0106:1'],
			'outageButValid' => [$invoice, ['valid' => true, 'outage' => true], '0106:1'],
			'viesRejects' => [$invoice, ['valid' => false, 'outage' => false], '0106:1'],
			'badKvk' => [['buyerLegalRegId' => '1234567'] + $invoice, $ok, '0106:1'],
			'badVat' => [['buyerVatId' => 'BE0123456789'] + $invoice, $ok, '0106:1'],
			'bothBad' => [[], ['valid' => false, 'outage' => false], '0106:1'],
		];
		$actual = [];
		foreach ($cases as $label => [$case, $vies, $participant]) {
			$calls = [];
			$actual[$label] = ['verdict' => $run($case, $vies, $participant), 'calls' => $calls];
		}

		$kvk = ['field' => 'buyerLegalRegId', 'code' => 'kvk_invalid', 'message' => 'KvK number must be exactly 8 digits'];
		$vatFormat = ['field' => 'buyerVatId', 'code' => 'vat_format_invalid', 'message' => 'BTW-nummer must match the NL pattern (NL999999999B99)'];
		$viesInvalid = ['field' => 'buyerVatId', 'code' => 'vat_vies_invalid', 'message' => 'BTW-nummer failed VIES verification'];
		$outage = ['field' => 'buyerVatId', 'code' => 'vies_outage', 'message' => 'VIES is temporarily unavailable — BTW-nummer format is valid; proceeding without live confirmation'];
		$noPeppol = ['field' => 'buyerPeppolParticipantId', 'code' => 'peppol_participant_not_found', 'message' => 'No Peppol participant found for this debtor — falling back to PDF + email'];
		$vies = ['vies', 'adm', 'NL123456789B01'];
		$peppol = ['peppol', 'adm', 'DEB-1'];
		$verdict = static fn (array $errors, array $warnings, ?string $id): array => ['valid' => ($errors === []), 'errors' => $errors, 'warnings' => $warnings, 'peppolParticipantId' => $id];
		self::assertSame(
			[
				'allValid' => ['verdict' => $verdict([], [], '0106:1'), 'calls' => [$vies, $peppol]],
				'noParticipant' => ['verdict' => $verdict([], [$noPeppol], null), 'calls' => [$vies, $peppol]],
				'outage' => ['verdict' => $verdict([], [$outage], '0106:1'), 'calls' => [$vies, $peppol]],
				'outageButValid' => ['verdict' => $verdict([], [], '0106:1'), 'calls' => [$vies, $peppol]],
				'viesRejects' => ['verdict' => $verdict([$viesInvalid], [], null), 'calls' => [$vies]],
				'badKvk' => ['verdict' => $verdict([$kvk], [], null), 'calls' => [$vies]],
				'badVat' => ['verdict' => $verdict([$vatFormat], [], null), 'calls' => []],
				'bothBad' => ['verdict' => $verdict([$kvk, $vatFormat], [], null), 'calls' => []],
			],
			$actual
		);

	}//end testEveryVerdictAndCheckIsExact()
}//end class
