<?php

/**
 * Fee schedule lookup for an object in hand
 *
 * The inputs of one FeeScheduleService::resolveForObject call, plus the
 * type properties already tried and the variant value of the row being
 * tried, so the steps of the walk over the register's schedules share them.
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
 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md (REQ-SOPR-008)
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * One resolveForObject walk: what is asked, and what was already tried.
 *
 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md (REQ-SOPR-008)
 */
final class FeeObjectLookup {
	/**
	 * The object's register slug.
	 *
	 * @var string
	 */
	public string $register = '';

	/**
	 * The object's schema slug.
	 *
	 * @var string
	 */
	public string $schema = '';

	/**
	 * The object itself.
	 *
	 * @var array<string, mixed>
	 */
	public array $object = [];

	/**
	 * Where the application came in, or an empty string.
	 *
	 * @var string
	 */
	public string $intakeChannel = '';

	/**
	 * The day to resolve for, as Y-m-d; today when empty.
	 *
	 * @var string
	 */
	public string $onDate = '';

	/**
	 * Type properties already tried, as property => true.
	 *
	 * @var array<string, true>
	 */
	public array $seen = [];

	/**
	 * The object's variant value for the row being tried.
	 *
	 * @var string
	 */
	public string $variant = '';
}//end class
