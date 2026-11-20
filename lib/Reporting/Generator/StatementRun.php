<?php

/**
 * Statement run
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * One rendering of a financial statement: the section being filled, the
 * reporting currency, and the two side totals as they are computed (debit
 * side: assets / expenses; credit side: equity and liabilities / revenue).
 *
 * @category Reporting
 * @package  OCA\Shillinq\Reporting\Generator
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude The reporting capability has no canonical spec. This tag pointed at
 *       openspec/changes/reporting-compliance-consolidation (a change directory that
 *       exists neither under changes nor under changes/archive), and no canonical
 *       reporting capability exists under openspec/specs either. Tracked in #525.
 *       Deliberately NOT resolved by writing that spec — authoring the requirement
 *       a tag is checked against turns the gate green over an unspecified capability.
 *
 * KNOWINGLY DANGLING — do not repoint this tag (gate-46, shillinq#499).
 * The change directory it names was never committed, and the `reporting`
 * capability has NO canonical spec. One was drafted during gate remediation
 * and withdrawn: a spec written to fit the code, by the process whose job is
 * to check the code against a spec, is not a specification anyone agreed to.
 * Authoring it is the capability owner's decision, not a gate fix. No existing
 * target is honest either — bookkeeping-iv3-reporting REQ-IV3-004 and
 * bookkeeping-vat-btw-filing REQ-VBTW-004 forbid the PHP renderers in this
 * directory, so pointing there would report conformance to a rule this code
 * breaks.
 *
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting\Generator;

use OCA\Shillinq\Reporting\ReportSection;

/**
 * Progress of one statement rendering; never leaves the generator that built it.
 */
final class StatementRun {

	/**
	 * Total of the debit side (assets on the balance sheet, expenses on the
	 * profit-and-loss account).
	 *
	 * @var float
	 */
	public float $debitTotal = 0.0;

	/**
	 * Total of the credit side (equity plus liabilities on the balance sheet,
	 * revenue on the profit-and-loss account).
	 *
	 * @var float
	 */
	public float $creditTotal = 0.0;

	/**
	 * Hold the section and the currency.
	 *
	 * @param ReportSection $section  The block accumulator being filled.
	 * @param string        $currency ISO-4217 reporting currency.
	 */
	public function __construct(
		public readonly ReportSection $section,
		public readonly string $currency,
	) {
	}//end __construct()
}//end class
