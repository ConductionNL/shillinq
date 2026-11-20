<?php

/**
 * A deferral schedule the service refuses to create, with a message a person can act on.
 *
 * The message is an English source string; the controller translates it.
 *
 * @category Service
 * @package  OCA\Shillinq\Kernel\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-continuous-close/spec.md#requirement-an-invoice-line-spanning-several-periods-is-spread-over-them-req-lpe-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Exception;

use DomainException;

/**
 * Refusal of a deferral, with the HTTP status that fits it.
 *
 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-continuous-close/spec.md#requirement-an-invoice-line-spanning-several-periods-is-spread-over-them-req-lpe-004
 */
class DeferralRefusedException extends DomainException {

	/**
	 * The request is not a usable deferral.
	 */
	public const INVALID = 422;

	/**
	 * The line is already spread.
	 */
	public const CONFLICT = 409;

	/**
	 * Constructor.
	 *
	 * @param string $message The English source string.
	 * @param int    $status  One of the constants.
	 */
	public function __construct(string $message, private readonly int $status = self::INVALID) {
		parent::__construct(message: $message);

	}//end __construct()

	/**
	 * The HTTP status of the refusal.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-continuous-close/spec.md#requirement-an-invoice-line-spanning-several-periods-is-spread-over-them-req-lpe-004
	 */
	public function getStatus(): int {
		return $this->status;

	}//end getStatus()
}//end class
