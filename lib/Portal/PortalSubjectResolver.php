<?php

/**
 * Portal Subject Resolver
 *
 * The ownership chain every portal write receiver in shillinq starts with: the
 * target must be an opaque object id (never a URL or a path), and the subject's
 * `customerMasterId` comes from their OWN portalAccount row in portaliq's
 * register, read the way portaliq's `PortalObjectReader::resolveClaim()` reads
 * it. The frozen A6 assertion carries only sub, audience, organisation, trust
 * and jti, never an app-specific claim, so the receiver derives the claim
 * itself. The pay receiver and the decline receiver share this one copy of the
 * security boundary instead of each keeping their own.
 *
 * Portal subjects are not Nextcloud users, so the read bypasses NC RBAC and
 * multitenancy (`_rbac: false, _multitenancy: false`); the audience and subject
 * filters ARE the boundary (ADR-005).
 *
 * @category Portal
 * @package  OCA\Shillinq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-002, REQ-SPPI-003)
 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-013)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Portal;

/**
 * Checks the target shape and resolves the subject's customer.
 *
 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-013)
 */
final class PortalSubjectResolver {
	/**
	 * Portaliq's own register, read cross-app and never written.
	 *
	 * @var string
	 */
	private const PORTALIQ_REGISTER = 'portaliq';

	/**
	 * The schema carrying the server-managed `claims` map.
	 *
	 * @var string
	 */
	private const SCHEMA_PORTAL_ACCOUNT = 'portalAccount';

	/**
	 * The claim namespace this app's scope claim lives under.
	 *
	 * @var string
	 */
	private const CLAIM_APP_ID = 'shillinq';

	/**
	 * The claim resolved from the subject's portal account.
	 *
	 * @var string
	 */
	private const CLAIM_NAME = 'customerMasterId';

	/**
	 * SSRF hardening: the target is used only as an opaque OpenRegister id,
	 * never to build a request. A URL, an absolute path or a parent traversal
	 * is refused.
	 *
	 * @param string $target The client-supplied target id.
	 *
	 * @return bool True when the target is safe to use as an opaque id.
	 *
	 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-003)
	 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-013)
	 */
	public function isOpaqueId(string $target): bool {
		if ($target === '' || str_contains($target, '://') === true || str_contains($target, '..') === true) {
			return false;
		}

		return str_starts_with($target, '/') === false && str_starts_with($target, '\\') === false;
	}//end isOpaqueId()

	/**
	 * The subject's `claims.shillinq.customerMasterId`, from their own portal
	 * account for the asserted audience.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $subjectRef The verified assertion's `sub`.
	 * @param string $audience The verified assertion's `audience`.
	 *
	 * @return string|null The customer, or null when absent or malformed.
	 *
	 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-002)
	 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-013)
	 */
	public function customerMasterId(object $objectService, string $subjectRef, string $audience): ?string {
		if ($subjectRef === '' || $audience === '') {
			return null;
		}

		$rows = $objectService
			->setRegister(self::PORTALIQ_REGISTER)
			->setSchema(self::SCHEMA_PORTAL_ACCOUNT)
			->findAll(
				config: [
					'filters' => [
						'subjectRef' => $subjectRef,
						'audience' => $audience,
					],
					'limit' => 2,
				],
				_rbac: false,
				_multitenancy: false,
			);

		if (is_array($rows) === false || empty($rows) === true) {
			return null;
		}

		$claims = ($rows[0]['claims'] ?? null);
		$appClaims = null;
		if (is_array($claims) === true) {
			$appClaims = ($claims[self::CLAIM_APP_ID] ?? null);
		}

		$value = null;
		if (is_array($appClaims) === true) {
			$value = ($appClaims[self::CLAIM_NAME] ?? null);
		}

		if (is_string($value) === false || $value === '') {
			return null;
		}

		return $value;
	}//end customerMasterId()
}//end class
