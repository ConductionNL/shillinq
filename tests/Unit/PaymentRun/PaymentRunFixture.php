<?php

/**
 * The payment run seed of banking-payment-run design.md, as test records.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\PaymentRun
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/banking-payment-run/design.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\PaymentRun;

/**
 * Records keyed by schema, shaped as the register fragment declares them.
 */
final class PaymentRunFixture {

	public const ADMIN = 'adm-voorbeeld';

	/**
	 * Payees, AP transactions and one approved run.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	public static function records(): array {
		return [
			'Payee' => [
				self::payee(id: 'payee-drukkerij', number: 'V-2026-101', name: 'Drukkerij Van der Meer B.V.', iban: 'NL20INGB0001234567'),
				self::payee(id: 'payee-devries', number: 'V-2026-102', name: 'Schoonmaakbedrijf De Vries', iban: 'NL44RABO0123456789')
					+ ['paymentBlocked' => true, 'paymentBlockReason' => 'IBAN change under verification'],
				self::payee(id: 'payee-noiban', number: 'V-2026-103', name: 'Glazenwasser Zonder Rekening', iban: ''),
			],
			'APTransaction' => [
				self::invoice(id: 'ap-0412', number: '2026-0412', vendor: 'payee-drukkerij', due: '2026-10-01', total: 1815.0, state: 'issued'),
				self::invoice(id: 'ap-dv7781', number: 'DV-7781', vendor: 'payee-devries', due: '2026-09-30', total: 2420.0, state: 'overdue'),
				self::invoice(id: 'ap-0419', number: '2026-0419', vendor: 'payee-drukkerij', due: '2026-09-29', total: 605.0, state: 'overdue')
					+ ['paymentBlocked' => true, 'paymentBlockReason' => 'Wacht op creditnota'],
				self::invoice(id: 'ap-0398', number: '2026-0398', vendor: 'payee-drukkerij', due: '2026-09-20', total: 121.0, state: 'issued'),
				self::invoice(id: 'ap-0377', number: '2026-0377', vendor: 'payee-drukkerij', due: '2026-09-15', total: 242.0, state: 'disputed'),
				self::invoice(id: 'ap-glas', number: 'GL-12', vendor: 'payee-noiban', due: '2026-09-25', total: 60.5, state: 'issued'),
				self::invoice(id: 'ap-later', number: '2026-0500', vendor: 'payee-drukkerij', due: '2026-10-20', total: 999.0, state: 'issued'),
				self::invoice(id: 'ap-paid', number: '2026-0300', vendor: 'payee-drukkerij', due: '2026-09-01', total: 50.0, state: 'paid'),
				self::invoice(id: 'ap-part', number: '2026-0450', vendor: 'payee-drukkerij', due: '2026-09-28', total: 1000.0, state: 'partially-paid'),
			],
			'PaymentRun' => [
				[
					'id' => 'pr-approved', 'runNumber' => 'PR-2026-001', 'administrationId' => self::ADMIN, 'executionDate' => '2026-09-25',
					'debtorAccountIban' => 'NL91ABNA0417164300', 'status' => 'approved', 'lifecycleState' => 'approved', 'totalAmount' => 121.0,
					'currency' => 'EUR',
					'paymentLines' => [
						['payeeId' => 'payee-drukkerij', 'creditorIban' => 'NL20INGB0001234567', 'amount' => 121.0, 'apTransactionRef' => 'ap-0398'],
					],
				],
				[
					'id' => 'pr-exported', 'runNumber' => 'PR-2026-002', 'administrationId' => self::ADMIN, 'executionDate' => '2026-09-10',
					'debtorAccountIban' => 'NL91ABNA0417164300', 'status' => 'exported', 'lifecycleState' => 'exported', 'totalAmount' => 400.0,
					'currency' => 'EUR',
					'paymentLines' => [
						['payeeId' => 'payee-drukkerij', 'creditorIban' => 'NL20INGB0001234567', 'amount' => 400.0, 'apTransactionRef' => 'ap-part'],
					],
				],
			],
		];

	}//end records()

	/**
	 * A payee record.
	 *
	 * @param string $id     Its id.
	 * @param string $number Its vendor number.
	 * @param string $name   Its name.
	 * @param string $iban   Its IBAN, '' for none.
	 *
	 * @return array<string, mixed>
	 */
	public static function payee(string $id, string $number, string $name, string $iban): array {
		$payee = [
			'id' => $id, 'vendorNumber' => $number, 'name' => $name, 'paymentTermDays' => 30,
			'administrationId' => self::ADMIN, 'lifecycleState' => 'active',
		];
		if ($iban !== '') {
			$payee['bankAccount'] = ['iban' => $iban, 'accountHolderName' => $name];
		}

		return $payee;

	}//end payee()

	/**
	 * An AP transaction record whose lines and tax add up.
	 *
	 * @param string $id     Its id.
	 * @param string $number Its invoice number.
	 * @param string $vendor The payee id.
	 * @param string $due    The due date.
	 * @param float  $total  The total including tax.
	 * @param string $state  The lifecycle state.
	 *
	 * @return array<string, mixed>
	 */
	public static function invoice(string $id, string $number, string $vendor, string $due, float $total, string $state): array {
		$net = round($total / 1.21, 2);
		return [
			'id' => $id, 'invoiceNumber' => $number, 'vendorId' => $vendor, 'invoiceDate' => '2026-09-01', 'dueDate' => $due,
			'currency' => 'EUR', 'totalAmount' => $total, 'taxAmount' => round($total - $net, 2),
			'lines' => [['description' => 'Levering', 'accountNumber' => '4400', 'amount' => $net]],
			'state' => $state, 'administrationId' => self::ADMIN,
		];

	}//end invoice()
}//end class
