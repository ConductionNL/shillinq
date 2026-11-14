<?php

/**
 * Shillinq JurisdictionRefusedException.
 *
 * Thrown when an output that exists for one jurisdiction only is asked for an
 * administration kept under another jurisdiction's rules.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Jurisdiction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Jurisdiction;

use DomainException;

/**
 * An output was refused because the administration's jurisdiction is not the one it is for.
 *
 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
 */
class JurisdictionRefusedException extends DomainException {

	/**
	 * Constructor.
	 *
	 * @param string $output       The output asked for, e.g. `Dutch VAT return`.
	 * @param string $jurisdiction The administration's jurisdiction (ISO 3166 alpha-2).
	 * @param string $required     The jurisdiction the output is for.
	 *
	 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
	 */
	public function __construct(
		private readonly string $output,
		private readonly string $jurisdiction,
		private readonly string $required,
	) {
		parent::__construct(
			message: sprintf(
				'A %s is only produced for an administration under %s rules; this administration is kept under %s rules.',
				$output,
				$required,
				$jurisdiction
			)
		);
	}//end __construct()

	/**
	 * The output that was refused.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
	 */
	public function getOutput(): string {
		return $this->output;
	}//end getOutput()

	/**
	 * The administration's jurisdiction.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
	 */
	public function getJurisdiction(): string {
		return $this->jurisdiction;
	}//end getJurisdiction()

	/**
	 * The jurisdiction the output is for.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
	 */
	public function getRequired(): string {
		return $this->required;
	}//end getRequired()
}//end class
