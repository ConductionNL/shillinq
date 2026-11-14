<?php

/**
 * A filed ICP statement cannot be prepared again
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Icp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Icp;

use RuntimeException;

/**
 * Thrown when the statement of a period is past draft: it is never
 * replaced, a change goes through a correction (REQ-ICP-008).
 *
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.1
 */
class IcpStatementFiledException extends RuntimeException {

	/**
	 * Construct the refusal.
	 *
	 * @param string $period The period.
	 * @param string $status The statement's status.
	 */
	public function __construct(
		public readonly string $period,
		public readonly string $status,
	) {
		parent::__construct(
			message: sprintf(
				'The ICP statement of %s is %s and is not replaced. File a correction instead.',
				$period,
				$status
			)
		);
	}//end __construct()
}//end class
