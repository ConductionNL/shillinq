<?php

/**
 * Contract of WidgetService
 *
 * The public surface of WidgetService that other components call, so they depend
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
 * Contract of WidgetService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WidgetServiceInterface {

	/**
	 * Create an appointment from a widget payload (REQ-WSW-003, REQ-WSW-008).
	 *
	 * THE SINGLE WRITE PATH for widget bookings. `WidgetApiController::appointments()`
	 * used to re-implement this inline; the two copies had already drifted once on
	 * a security check (PR #491 had to patch an `isPublic` gate into the routed copy
	 * that this method always had) and a second time on customer data (the routed
	 * copy persisted only an anonymised `customerId`, silently dropping the
	 * `customerName` / `customerEmail` / `customerPhone` fields that
	 * `register.d/30-bookings-self-service-widget.json` declares on `Appointment`
	 * and that REQ-WSW-006 and the REQ-BCF-003 confirmation email both need).
	 * The controller now delegates here.
	 *
	 * Contract notes:
	 *  - The service is resolved by `serviceId` (REQ-WSW-001/003 payload shape),
	 *    and must be BOTH `isPublic` and `status = active` to be bookable.
	 *  - Availability is re-checked server-side against SlotService, which is
	 *    authoritative and already excludes confirmed appointments (REQ-WSW-002).
	 *  - Status is `pending_confirmation`, NOT `confirmed`: this is the customer
	 *    self-service pathway (REQ-BCA-005 pathway 2, REQ-BCF-010), and
	 *    bookings-confirm-flow keys its ConfirmationToken, confirmation email and
	 *    auto-cancel job off that status.
	 *  - Customer contact details are stored write-only and are never echoed back
	 *    to the public API (design D6).
	 *
	 * Boundary validation (name/email/phone/notes/ISO format) is the controller's
	 * `validateAppointmentPayload()`, which owns the user-facing REQ-WSW-008
	 * messages. This method re-checks email and phone as documented server-side
	 * defence-in-depth; it deliberately does NOT re-check the name, because the
	 * two name patterns differ and silently adopting the stricter one here would
	 * change what the live endpoint accepts (see the PR notes).
	 *
	 * @param string $administrationId The owning administration.
	 * @param array<string,mixed> $payload Widget payload (serviceId, resourceId,
	 *                                     startTime, endTime, customer*).
	 *
	 * @return array<string,mixed> ['code' => int, ...] where code is 201/400/404/409/500.
	 *
	 * @spec openspec/specs/bookings-self-service-widget/spec.md
	 */
	public function createAppointment(string $administrationId, array $payload): array;

	/**
	 * Whether a serviceId names a service the business actually published.
	 *
	 * The same #491 gate as findPublicService(), exposed for callers that need
	 * to ASK the question rather than resolve the row — currently
	 * WidgetApiController::slots().
	 *
	 * Why slots() needs it: `guard()` proves the caller holds a widget API key,
	 * and that key is by definition shipped in a PUBLIC booking widget, so
	 * anyone who can view the widget holds it. listPublicServices() filters the
	 * catalogue down to `isPublic` entries precisely so a business can keep
	 * internal services out of it — but availability was readable for ANY
	 * serviceId of that tenant, published or not. Booking was gated (#491) and
	 * listing was gated; reading availability was the third door.
	 *
	 * Fail-closed: an unresolvable catalogue denies rather than permits.
	 *
	 * @param string $serviceId        The service to check.
	 * @param string $administrationId The administration the service must belong to.
	 *
	 * @return bool True when the service is public, active and in that administration.
	 *
	 * @psalm-return   bool
	 * @phpstan-return bool
	 *
	 * @spec openspec/specs/bookings-self-service-widget/spec.md
	 */
	public function isPubliclyBookable(string $serviceId, string $administrationId): bool;
}//end interface
