<?php

/**
 * Query Failure Log
 *
 * Where a failed register read is reported: the logger and the name of the
 * component that ran the read, so the log line says who could not read.
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
 * @spec openspec/specs/budget-projection-engine/spec.md#req-bpe-009
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Logs a failed register read under the reading component's name. Never
 * logs a record body.
 *
 * @spec openspec/specs/budget-projection-engine/spec.md#req-bpe-009
 */
final class QueryFailureLog {

	/**
	 * Hold the logger and the component name.
	 *
	 * @param LoggerInterface $logger    Logger — never receives a record body.
	 * @param string          $component The name the log line starts with.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly string $component,
	) {
	}//end __construct()

	/**
	 * Report one failed read.
	 *
	 * @param string    $schema The schema that could not be read.
	 * @param Throwable $error  The failure.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/budget-projection-engine/spec.md#req-bpe-009
	 */
	public function report(string $schema, Throwable $error): void {
		$this->logger->error(
			$this->component . ': failed to query OpenRegister',
			['schema' => $schema, 'exception' => $error->getMessage()]
		);
	}//end report()
}//end class
