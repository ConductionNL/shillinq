<?php

/**
 * Which ENSIA questions one cycle loads, and what it stamps on them.
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
 * @spec openspec/specs/bookkeeping-ensia-zelfevaluatie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * The selected verantwoordingsdomeinen and the cycle and administration each
 * Evaluatievraag is stamped with. Built and read by ENSIAQuestionSetLoader.
 */
final class ENSIAQuestionScope {

	/**
	 * Selected domains, as a set keyed by domain name.
	 *
	 * @var array<string,true>
	 */
	public array $domains = [];

	/**
	 * FK to ENSIAJaarcyclus.
	 *
	 * @var string
	 */
	public string $cyclusId = '';

	/**
	 * FK to Administration.
	 *
	 * @var string
	 */
	public string $administrationId = '';
}//end class
