<?php

/**
 * Contract of ComplianceExportService
 *
 * The public surface of ComplianceExportService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Shillinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

use RuntimeException;

/**
 * Contract of ComplianceExportService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ComplianceExportServiceInterface {

	/**
	 * Default scope when the caller omits the parameter.
	 *
	 * @var string
	 */
	public const SCOPE_ALL = 'all';

	/**
	 * Default format when the caller omits the parameter.
	 *
	 * @var string
	 */
	public const FORMAT_CSV = 'csv';

	/**
	 * Build the PII-filtered audit-trail export envelope.
	 *
	 * @param string $from ISO date YYYY-MM-DD (inclusive).
	 * @param string $to ISO date YYYY-MM-DD (inclusive).
	 * @param string $scope ComplianceExportService::SCOPE_ALL
	 *                      or ::SCOPE_SUBJECT.
	 * @param string $format ::FORMAT_CSV or ::FORMAT_JSON.
	 * @param string|null $actorFilter When scope=subject, restrict
	 *                                 to events where the actor
	 *                                 matches. Defaults to the
	 *                                 current session UID.
	 *
	 * @return array{
	 *     from:string,
	 *     to:string,
	 *     scope:string,
	 *     format:string,
	 *     actorFilter:?string,
	 *     generatedAt:string,
	 *     generatedBy:string,
	 *     eventCount:int,
	 *     headers:array<int,string>,
	 *     rows:array<int,array<string,mixed>>
	 * }
	 *
	 * @throws RuntimeException When [from, to] is invalid.
	 */
	public function generateExport(
		string $from,
		string $to,
		string $scope = self::SCOPE_ALL,
		string $format = self::FORMAT_CSV,
		?string $actorFilter = null,
	): array;

	/**
	 * Render the in-memory export as a single CSV blob (RFC 4180).
	 *
	 * @param array{headers:array<int,string>,rows:array<int,array<string,mixed>>} $envelope The envelope from generateExport().
	 *
	 * @return string The CSV blob.
	 */
	public function renderCsv(array $envelope): string;
}//end interface
