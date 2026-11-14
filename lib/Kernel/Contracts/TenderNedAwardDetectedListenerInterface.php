<?php

/**
 * Contract of TenderNedAwardDetectedListener
 *
 * The public surface of TenderNedAwardDetectedListener that other components call, so they depend
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
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

/**
 * Contract of TenderNedAwardDetectedListener.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TenderNedAwardDetectedListenerInterface {

	/**
	 * Run the promotion from the background job.
	 *
	 * Public only so `TenderNedAwardPromotionJob` can call back in; the
	 * eligibility rules and the idempotency check stay owned by this class so
	 * the inline and deferred paths cannot drift.
	 *
	 * Deferred delivery is at-least-once, so this MUST NOT promote from the
	 * payload captured at dispatch time (ADR-078 / object-event-work-placement:
	 * "a deferred handler MUST re-resolve the object it acts on and MUST no-op
	 * when the object is gone or soft-deleted"). Between the event and this
	 * run the dossier can have been deleted, or the award reverted — promoting
	 * a stale snapshot would create a Commitment for an award that no longer
	 * exists, and nothing downstream would flag it.
	 *
	 * @param array<string, mixed> $entry Buffered entry: uuid, schema, payload.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-tenderned-integratie/spec.md
	 */
	public function runDeferredPromotion(array $entry): void;
}//end interface
