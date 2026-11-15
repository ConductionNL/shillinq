<?php

/**
 * Mandate Dossier
 *
 * The records gathered for one SEPA mandate's audit dossier (REQ-SDD-010),
 * carried from the tenant check through the related-record lookups into the
 * ZIP assembly of {@see SepaAuditService}.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * One mandate and the records its dossier is built from.
 *
 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
 */
class MandateDossier {

	/**
	 * The SepaMandate id as requested.
	 *
	 * @var string
	 */
	public string $mandateId = '';

	/**
	 * The mandate record.
	 *
	 * @var array<string,mixed>
	 */
	public array $mandate = [];

	/**
	 * Its collections (may contain non-array store rows).
	 *
	 * @var array<int,mixed>
	 */
	public array $collections = [];

	/**
	 * R-transactions of those collections.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $rTransactions = [];

	/**
	 * Pre-notifications of those collections.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $preNotifications = [];
}//end class
