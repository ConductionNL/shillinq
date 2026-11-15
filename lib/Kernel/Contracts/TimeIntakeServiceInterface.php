<?php

/**
 * Contract of TimeIntakeService
 *
 * The public surface of TimeIntakeService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Shillinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

use InvalidArgumentException;
use RuntimeException;

/**
 * Contract of TimeIntakeService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TimeIntakeServiceInterface {

	/**
	 * Ingest one batch (POST /apps/shillinq/api/billing/time-intake).
	 *
	 * @param string $administrationId Server-resolved tenant scope (never client-supplied).
	 * @param string $personId Server-resolved authenticated user id — stamped as
	 *                         the UrenRegistratie.personId of every materialised
	 *                         entry (the batch carries no per-entry person
	 *                         identity).
	 * @param array<string,mixed> $body Decoded JSON request body.
	 *
	 * @throws InvalidArgumentException On structural/malformed input (maps to HTTP 400).
	 * @throws RuntimeException On a "Conflict: ..." message (maps to HTTP 409) or any other
	 *                          semantic validation failure (maps to HTTP 422).
	 *
	 * @return array{invoiceId:string,invoiceNumber:string,status:string,lines:int,duplicated:bool}
	 *
	 * @spec openspec/specs/time-expense-invoice-intake/spec.md
	 */
	public function ingest(string $administrationId, string $personId, array $body): array;
}//end interface
