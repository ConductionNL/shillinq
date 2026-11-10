<?php

/**
 * Reads the compliance-deadline source records from OpenRegister.
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
 * @spec openspec/specs/compliance-deadline-calendar/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\AppInfo\AppIdentity;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fetches one schema's rows as arrays for ComplianceDeadlineCalendarService,
 * which builds it per read.
 *
 * @spec openspec/specs/compliance-deadline-calendar/spec.md
 */
final class ComplianceSourceRows {

	/**
	 * Construct the reader.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param IAppConfig             $appConfig     App config for the register slug.
	 * @param LoggerInterface        $logger        Logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Fetch all records of a schema via the real OpenRegister
	 * ObjectService fluent API (setRegister/setSchema/findAll — ADR-022).
	 * Fail-soft: an unavailable OR or absent schema yields [] so one
	 * source never hides the others.
	 *
	 * @param string $schema The schema name.
	 *
	 * @return array<int,array<string,mixed>> The records (possibly empty).
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	public function fetch(string $schema): array {
		try {
			$result = $this->objectService
				->setRegister(register: $this->registerSlug())
				->setSchema(schema: $schema)
				->findAll([]);

			// ObjectService::findAll() yields ObjectEntity objects, NOT arrays.
			// Everything downstream — objectId(), the deadline builders — reads
			// rows with array syntax, so passing them through verbatim threw
			// "Cannot use object of type OCA\OpenRegister\Db\ObjectEntity as
			// array" on every publish. The catch below then swallowed it as
			// "publication failed — degrading fail-soft", so the calendar
			// silently published NOTHING while reporting a handled degradation.
			//
			// Same defect and same normalisation as the VAT fix in this branch;
			// house idiom is jsonSerialize() then getObject().
			$rows = [];
			foreach (array_values($result) as $row) {
				$array = $this->rowAsArray(row: $row);
				if ($array !== null) {
					$rows[] = $array;
					continue;
				}

				// Skip loudly rather than appending something the callers will
				// fatal on — a dropped row is recoverable, a fatal is not.
				$this->logger->warning(
					'ComplianceDeadlineCalendarService: unsupported row type from ObjectService::findAll',
					['schema' => $schema, 'type' => get_debug_type($row)]
				);
			}//end foreach

			return $rows;
		} catch (Throwable $e) {
			$this->logger->debug(
				'ComplianceDeadlineCalendarService: schema read unavailable — treating as empty',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return [];
		}//end try

	}//end fetch()

	/**
	 * One findAll() row as an array: as given, else its jsonSerialize(), else
	 * its getObject(); null when none of those is an array.
	 *
	 * @param mixed $row One row.
	 *
	 * @return array<string,mixed>|null
	 */
	private function rowAsArray(mixed $row): ?array {
		if (is_array($row) === true) {
			return $row;
		}

		return ($this->serialisedArray(row: $row) ?? $this->objectArray(row: $row));
	}//end rowAsArray()

	/**
	 * A row object's jsonSerialize(), when it has one that returns an array.
	 *
	 * @param mixed $row One row.
	 *
	 * @return array<string,mixed>|null
	 */
	private function serialisedArray(mixed $row): ?array {
		if (is_object($row) === false || method_exists($row, 'jsonSerialize') === false) {
			return null;
		}

		$out = $row->jsonSerialize();
		if (is_array($out) === false) {
			return null;
		}

		return $out;
	}//end serialisedArray()

	/**
	 * A row object's getObject(), when it has one that returns an array.
	 *
	 * @param mixed $row One row.
	 *
	 * @return array<string,mixed>|null
	 */
	private function objectArray(mixed $row): ?array {
		if (is_object($row) === false || method_exists($row, 'getObject') === false) {
			return null;
		}

		$out = $row->getObject();
		if (is_array($out) === false) {
			return null;
		}

		return $out;
	}//end objectArray()

	/**
	 * Resolve the configured OpenRegister register slug (default 'shillinq').
	 *
	 * @return string The register slug.
	 */
	private function registerSlug(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end registerSlug()
}//end class
