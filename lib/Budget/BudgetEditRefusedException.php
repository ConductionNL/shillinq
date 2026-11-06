<?php

/**
 * Budget Edit Refused Exception
 *
 * A budget edit that cannot be carried out, with an English source
 * string and its parameters so the controller can translate the reason.
 *
 * @category Budget
 * @package  OCA\Shillinq\Budget
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/budget-grid-view/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Budget;

use DomainException;

/**
 * Refusal with a translatable template and its parameters.
 *
 * @spec openspec/specs/budget-grid-view/spec.md
 */
class BudgetEditRefusedException extends DomainException {
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
	 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.1
	 */
	public function getTemplate(): string {
		return $this->template;

	}//end getTemplate()

	/**
	 * The placeholder values.
	 *
	 * @return array<int,string>
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.1
	 */
	public function getParameters(): array {
		return $this->parameters;

	}//end getParameters()
}//end class
