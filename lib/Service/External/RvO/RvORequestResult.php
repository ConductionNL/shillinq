<?php

/**
 * Result value-object returned by an RvO aanvraag adapter call.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\RvO
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-investeringsaftrek/spec.md
 * @spec openspec/specs/bookkeeping-wbso-sno-administratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\External\RvO;

/**
 * Result of an RvO aanvraag submission attempt.
 *
 * `aanvraagnummer` is the RvO-side identifier — Mijn-RvO's canonical
 * handle for tracking the application's status. The dormant default
 * synthesises one so callers can persist a non-null reference even
 * when no outbound call took place.
 *
 * @spec openspec/specs/bookkeeping-investeringsaftrek/spec.md
 * @spec openspec/specs/bookkeeping-wbso-sno-administratie/spec.md
 */
final class RvORequestResult {
	/**
	 * Construct the result value-object.
	 *
	 * @param string $deliveryStatus ACCEPTED / REJECTED / DEFERRED.
	 * @param string $aanvraagnummer RvO-side application id.
	 * @param bool $dormant TRUE when the adapter was dormant.
	 * @param array<string,mixed> $extras Provider-specific extras
	 *                                    (e.g. behandeltermijn,
	 *                                    rejectieReden, mijnRvoUrl).
	 */
	public function __construct(
		public readonly string $deliveryStatus,
		public readonly string $aanvraagnummer,
		public readonly bool $dormant,
		public readonly array $extras = [],
	) {
	}//end __construct()
}//end class
