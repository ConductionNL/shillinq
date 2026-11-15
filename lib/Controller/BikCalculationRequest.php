<?php

/**
 * BIK Calculation Request
 *
 * The input of POST /api/dunning/bik as read from the request.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use DateTimeImmutable;
use OCP\IRequest;

/**
 * The hoofdsom, party type, arrears, dates and rates of one BIK calculation.
 *
 * The dates stay raw until dates() is asked, so a malformed date is reported
 * only after the other input has been checked.
 *
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 */
final class BikCalculationRequest {
	/**
	 * The administration the calculation is for.
	 *
	 * @var string
	 */
	public readonly string $administrationId;

	/**
	 * The principal (hoofdsom).
	 *
	 * @var float
	 */
	public readonly float $principal;

	/**
	 * B2B, B2C or GOVERNMENT as sent; default B2B.
	 *
	 * @var string
	 */
	public readonly string $partyType;

	/**
	 * Days in arrears (dagenVerzuim).
	 *
	 * @var integer
	 */
	public readonly int $daysInArrears;

	/**
	 * The invoice, '' when not given.
	 *
	 * @var string
	 */
	public readonly string $invoiceId;

	/**
	 * The effective date as sent, '' for now.
	 *
	 * @var string
	 */
	public readonly string $effectiveDate;

	/**
	 * The calculation date as sent, '' for now.
	 *
	 * @var string
	 */
	public readonly string $calculatedOn;

	/**
	 * The B2B rate override, null when not sent.
	 *
	 * @var float|null
	 */
	public readonly ?float $rateB2B;

	/**
	 * The B2C rate override, null when not sent.
	 *
	 * @var float|null
	 */
	public readonly ?float $rateB2C;

	/**
	 * Read the calculation input from the request.
	 *
	 * @param IRequest $request The request.
	 * @param string $administrationId The checked administration id.
	 */
	public function __construct(IRequest $request, string $administrationId) {
		$this->administrationId = $administrationId;
		$this->principal = (float)$request->getParam('principal', 0.0);
		$this->partyType = (string)$request->getParam('partyType', 'B2B');
		$this->daysInArrears = (int)$request->getParam('dagenVerzuim', 0);
		$this->invoiceId = (string)$request->getParam('invoiceId', '');
		$this->effectiveDate = (string)$request->getParam('effectiveDate', '');
		$this->calculatedOn = (string)$request->getParam('calculatedOn', '');
		$this->rateB2B = self::rate(value: $request->getParam('tariefB2B'));
		$this->rateB2C = self::rate(value: $request->getParam('tariefB2C'));

	}//end __construct()

	/**
	 * The effective date and the calculation date; today when not sent.
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
	 *
	 * @throws \Exception When a date cannot be parsed.
	 *
	 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
	 */
	public function dates(): array {
		return [
			self::dateOrNow(raw: $this->effectiveDate),
			self::dateOrNow(raw: $this->calculatedOn),
		];

	}//end dates()

	/**
	 * A sent rate as float, null when not sent.
	 *
	 * @param mixed $value The parameter value.
	 *
	 * @return float|null
	 */
	private static function rate(mixed $value): ?float {
		if ($value === null) {
			return null;
		}

		return (float)$value;

	}//end rate()

	/**
	 * Parse a date, now when it is ''.
	 *
	 * @param string $raw The date as sent.
	 *
	 * @return DateTimeImmutable
	 *
	 * @throws \Exception When the date cannot be parsed.
	 */
	private static function dateOrNow(string $raw): DateTimeImmutable {
		if ($raw === '') {
			return new DateTimeImmutable();
		}

		return new DateTimeImmutable($raw);

	}//end dateOrNow()
}//end class
