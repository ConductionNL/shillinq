<?php

/**
 * Result value-object returned by a KvK Handelsregister adapter call.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\Kvk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-multi-administratie/spec.md
 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\External\Kvk;

/**
 * Result of a KvK Handelsregister lookup attempt.
 *
 * `lookupStatus` is one of `FOUND`, `NOT_FOUND`, `LOOKUP_DEFERRED`,
 * `LOOKUP_ERROR`. The dormant default always returns
 * `LOOKUP_DEFERRED` with an empty `entity` envelope so callers can
 * persist the lookup intent and re-run once a live binding is
 * provisioned.
 *
 * @spec openspec/specs/bookkeeping-multi-administratie/spec.md
 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
 */
final class KvkLookupResult {
	/**
	 * Construct the result value-object.
	 *
	 * @param string $lookupStatus FOUND / NOT_FOUND /
	 *                             LOOKUP_DEFERRED / LOOKUP_ERROR.
	 * @param string $kvkNumber Echoed input — useful for
	 *                          batch correlation.
	 * @param array<string,mixed> $entity Entity envelope —
	 *                                    rechtsvorm, statutaireNaam,
	 *                                    rsin, sbiCodes[],
	 *                                    hoofdvestiging{adres,
	 *                                    bezoekadres, postadres},
	 *                                    uitschrijvingsdatum,
	 *                                    bestuurders[] — empty for
	 *                                    NOT_FOUND / DEFERRED.
	 * @param bool $dormant TRUE when the adapter was
	 *                      dormant.
	 * @param array<string,mixed> $extras Provider-specific extras
	 *                                    (e.g. rateLimitRemaining,
	 *                                    handelsregisterUrl).
	 */
	public function __construct(
		public readonly string $lookupStatus,
		public readonly string $kvkNumber,
		public readonly array $entity,
		public readonly bool $dormant,
		public readonly array $extras = [],
	) {
	}//end __construct()
}//end class
