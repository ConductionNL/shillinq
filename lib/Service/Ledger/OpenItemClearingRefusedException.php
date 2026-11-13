<?php

/**
 * A clearing the open item service refuses, with a message a person can act on.
 *
 * The message is an English source string with `%1$s` placeholders, the form
 * Nextcloud's IL10N::t() fills; the controller translates it, so the refusal
 * reaches the user in their language.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Ledger
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ledger-open-item-clearing/specs/bookkeeping-general-ledger/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Ledger;

use DomainException;

/**
 * Refusal with a translatable template and its parameters.
 *
 * @spec openspec/changes/ledger-open-item-clearing/specs/bookkeeping-general-ledger/spec.md
 */
class OpenItemClearingRefusedException extends DomainException {

	/**
	 * Constructor.
	 *
	 * @param string            $template   English source string with `%1$s` placeholders.
	 * @param array<int,string> $parameters Placeholder values, in order.
	 */
	public function __construct(
		private readonly string $template,
		private readonly array $parameters = [],
	) {
		parent::__construct(message: vsprintf($template, $parameters));

	}//end __construct()

	/**
	 * The English source string.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/ledger-open-item-clearing/specs/bookkeeping-general-ledger/spec.md (REQ-LOIC-002)
	 */
	public function getTemplate(): string {
		return $this->template;

	}//end getTemplate()

	/**
	 * The placeholder values.
	 *
	 * @return array<int,string>
	 *
	 * @spec openspec/changes/ledger-open-item-clearing/specs/bookkeeping-general-ledger/spec.md (REQ-LOIC-002)
	 */
	public function getParameters(): array {
		return $this->parameters;

	}//end getParameters()
}//end class
