<?php

/**
 * A quote-to-order or order-to-invoice conversion the service refuses, with a message a person can act on.
 *
 * The message is an English source string with `%1$s` placeholders, the
 * form Nextcloud's IL10N::t() fills; the controller translates it, so the
 * refusal reaches the user in their language.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Sales
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-quote-and-order-to-invoice/tasks.md#task-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Sales;

use DomainException;

/**
 * Refusal with a translatable template and its parameters.
 *
 * @spec openspec/changes/sales-quote-and-order-to-invoice/tasks.md#task-1.2
 */
class SalesOrderRefusedException extends DomainException {
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
	 * @spec openspec/changes/sales-quote-and-order-to-invoice/tasks.md#task-1.2
	 */
	public function getTemplate(): string {
		return $this->template;

	}//end getTemplate()

	/**
	 * The placeholder values.
	 *
	 * @return array<int,string>
	 *
	 * @spec openspec/changes/sales-quote-and-order-to-invoice/tasks.md#task-1.2
	 */
	public function getParameters(): array {
		return $this->parameters;

	}//end getParameters()
}//end class
