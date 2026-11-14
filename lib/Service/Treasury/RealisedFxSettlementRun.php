<?php

/**
 * Realised FX Settlement Run
 *
 * The state of one RealisedFxSettlementService::postRealisedFxOnSettlement()
 * call: the settled invoice and its administration, the currencies and amount,
 * the two rates once resolved, and the realised difference, direction and GL
 * accounts once they are known.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Treasury
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-multi-currency/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Treasury;

use DateTimeImmutable;

/**
 * Per-call state of a realised FX settlement posting.
 *
 * @spec openspec/specs/bookkeeping-multi-currency/spec.md
 */
final class RealisedFxSettlementRun {

	/**
	 * Invoice (foreign) currency, upper-cased.
	 *
	 * @var string
	 */
	public string $foreignCurrency = '';

	/**
	 * The administration's functional currency.
	 *
	 * @var string
	 */
	public string $functionalCurrency = '';

	/**
	 * ISO invoice date.
	 *
	 * @var string
	 */
	public string $invoiceDate = '';

	/**
	 * Foreign-currency gross amount settled.
	 *
	 * @var float
	 */
	public float $grossAmount = 0.0;

	/**
	 * The settlement date the caller passed, null for today.
	 *
	 * @var string|null
	 */
	public ?string $requestedSettlementDate = null;

	/**
	 * ISO settlement date, once resolved.
	 *
	 * @var string
	 */
	public string $settlementDate = '';

	/**
	 * Rate the receivable was booked at, once resolved.
	 *
	 * @var float|null
	 */
	public ?float $invoiceRate = null;

	/**
	 * Rate applied at settlement, once resolved.
	 *
	 * @var float|null
	 */
	public ?float $paymentRate = null;

	/**
	 * Signed realised difference in functional cents.
	 *
	 * @var int
	 */
	public int $diffCents = 0;

	/**
	 * 'gain' or 'loss', once a posting is made.
	 *
	 * @var string|null
	 */
	public ?string $direction = null;

	/**
	 * The realised cents reported, once a posting is made.
	 *
	 * @var int
	 */
	public int $postedCents = 0;

	/**
	 * Realised gain (gain) or loss (loss) account code.
	 *
	 * @var string
	 */
	public string $realisedGainLossAccount = '';

	/**
	 * AR-control clearing account code.
	 *
	 * @var string
	 */
	public string $arControlAccount = '';

	/**
	 * Run timestamp of the posting.
	 *
	 * @var DateTimeImmutable|null
	 */
	public ?DateTimeImmutable $asOf = null;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $invoice          The settled ARInvoice record.
	 * @param string              $administrationId Administration scope from the invoice.
	 *
	 * @spec openspec/specs/bookkeeping-multi-currency/spec.md
	 */
	public function __construct(
		public readonly array $invoice,
		public readonly string $administrationId,
	) {

	}//end __construct()
}//end class
