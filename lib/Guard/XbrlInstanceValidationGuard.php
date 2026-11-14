<?php

/**
 * Shillinq XBRL instance validation guard
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Guard;

use OCA\Shillinq\Lifecycle\PostingRefusedException;
use OCA\Shillinq\Service\Sbr\XbrlInstanceCheck;
use OCA\Shillinq\Service\Sbr\XbrlTaxonomyLookup;

/**
 * The `requires` of XbrlInstance `validate`: refused, naming each failing
 * item, until the instance has every required concept of its entry point,
 * its period and identifier, totals that add up, and a taxonomy effective
 * for its period.
 *
 * The refusal is a PostingRefusedException, which the shared
 * RegisterRequiresGuardAdapter turns into a deny carrying its message; any
 * other failure (the taxonomy cannot be read) denies too, fail-closed.
 *
 * @spec openspec/changes/tax-digipoort-filing/specs/bookkeeping-sbr-xbrl-reporting/spec.md#requirement-an-instance-is-validated-against-its-entry-point-before-submission-req-tdf-003
 */
class XbrlInstanceValidationGuard {

	/**
	 * Constructor.
	 *
	 * @param XbrlTaxonomyLookup $taxonomies Finds the taxonomy effective for the period.
	 * @param XbrlInstanceCheck  $check      Lists the failing items.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-2.1
	 */
	public function __construct(
		private readonly XbrlTaxonomyLookup $taxonomies,
		private readonly XbrlInstanceCheck $check,
	) {
	}//end __construct()

	/**
	 * Whether the instance may be validated.
	 *
	 * @param array<string, mixed> $instance The XbrlInstance under transition.
	 *
	 * @return bool True for a clean instance.
	 *
	 * @throws PostingRefusedException Naming every failing item.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-2.1
	 */
	public function requireValidInstance(array $instance): bool {
		$taxonomy = $this->taxonomies->effectiveFor(
			entryPoint: trim((string) ($instance['entryPoint'] ?? '')),
			start: trim((string) ($instance['reportingPeriodStart'] ?? '')),
			end: trim((string) ($instance['reportingPeriodEnd'] ?? ''))
		);

		$problems = $this->check->problems(instance: $instance, taxonomy: $taxonomy);
		if ($problems !== []) {
			throw new PostingRefusedException(message: implode(' ', $problems));
		}

		return true;
	}//end requireValidInstance()
}//end class
