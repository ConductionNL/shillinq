<?php

/**
 * Contract of PipelinqContactAdapter
 *
 * The public surface of PipelinqContactAdapter that other components call, so they depend
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

use OCA\Shillinq\Kernel\Model\KlantbeeldResult;
use OCA\Shillinq\Kernel\Model\PipelinqContact;
use OCA\Shillinq\Kernel\Exception\PipelinqTransportException;
use OCA\Shillinq\Kernel\Model\TimelineEventDto;
use OCA\Shillinq\Kernel\Model\TimelinePublishOutcome;

/**
 * Contract of PipelinqContactAdapter.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PipelinqContactAdapterInterface {

	/**
	 * Invalidate every cached Contact entry.
	 *
	 * Wipes all keys under {@see self::CONTACT_CACHE_KEY_PREFIX}. Used by
	 * the admin "Clear pipelinq cache" action and exposed for tests so a
	 * cache-expiry scenario can be simulated without sleeping for 5
	 * minutes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-03-contact-read/tasks.md
	 */
	public function clearCache(): void;

	/**
	 * Fetch the klantbeeld transaction history for a contact.
	 *
	 * GETs `/api/v1/contacts/{externalId}/klantbeeld?limit=…&offset=…` via
	 * the resilient transport (slice 02), parses the `transactions` array
	 * into {@see KlantbeeldTransaction} rows, and wraps the page in a
	 * {@see KlantbeeldResult} envelope so the slice-05 controller can
	 * distinguish three legitimate outcomes:
	 *
	 *   1. Available with rows — render the history.
	 *   2. Available, empty — render "no previous transactions" (NOT an error).
	 *   3. Unavailable (klantbeeld 5xx while Contact succeeded) — render the
	 *      profile but hide / replace history.
	 *
	 * The `limit` argument is clamped to the spec range (1..100, default 5)
	 * before being placed on the query string so a downstream caller cannot
	 * fan out an unbounded page. The `offset` argument is clamped to a
	 * non-negative integer for the same reason.
	 *
	 * No persistent cache is used: transactions are immutable but the page
	 * window depends on the (limit, offset) tuple AND the live tail of the
	 * ledger, so per the giant's decision D6 we cache only within the
	 * request (the consumer holds its own session-scoped memoisation).
	 *
	 * Failure semantics:
	 *
	 *   - 404 on the contact → returns an empty available page (the
	 *     contact has no klantbeeld; the profile fetch in slice 03 owns
	 *     the strict "contact missing" answer).
	 *   - 5xx exhausting the retry budget, or the breaker tripping →
	 *     returns {@see KlantbeeldResult::unavailable()} so the profile
	 *     stays usable. The underlying transport failure is logged at
	 *     WARNING.
	 *   - Any other non-transport failure (malformed JSON, unsupported
	 *     shape) → returns {@see KlantbeeldResult::unavailable()} with a
	 *     WARNING log. We never raise out of this method: the entire
	 *     point of the envelope is to let the slice-05 controller keep
	 *     the profile rendered regardless.
	 *
	 * @param string $externalId Pipelinq contact id (slice 01 link).
	 * @param int $limit Page size, default 5, clamped to 1..100.
	 * @param int $offset Page offset, clamped to ≥0, default 0.
	 *
	 * @return KlantbeeldResult Envelope; never throws.
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-04-klantbeeld-read/tasks.md
	 */
	public function fetchKlantbeeld(string $externalId, int $limit = 5, int $offset = 0): KlantbeeldResult;

	/**
	 * Load a pipelinq Contact by `externalId`.
	 *
	 * Read-through cache against the injected {@see ICache} (5-minute
	 * TTL per `externalId`, key `pipelinq:contact:{externalId}`):
	 *
	 *   1. A cache hit is returned immediately, never touching pipelinq.
	 *   2. On a cache miss the adapter issues
	 *      `GET /api/v1/contacts/{externalId}` via the slice-02 request
	 *      loop (3s timeout, retry policy, circuit breaker).
	 *   3. A 200 response is parsed into a {@see PipelinqContact} DTO
	 *      and cached for the TTL.
	 *   4. A 404 is the expected "not found" outcome — a fallback DTO
	 *      is returned and cached (so the same lookup does not re-hit
	 *      pipelinq inside the TTL); no error is logged.
	 *   5. A malformed-JSON response yields a fallback DTO + a WARNING
	 *      log line; the raw body is truncated to 200 chars before
	 *      logging. No retry is issued — this is a client-side
	 *      classification, not a transient failure.
	 *   6. When pipelinq is unavailable (transport error / open
	 *      breaker) and a still-valid cached entry exists, the cached
	 *      entry is served instead of propagating the failure.
	 *
	 * @param string $externalId The pipelinq external Contact id (see slice 01).
	 *
	 * @return PipelinqContact The Contact (with `isFound()=true`) or a
	 *                         fallback DTO when the id is unknown / the
	 *                         response is malformed.
	 *
	 * @throws PipelinqTransportException When pipelinq is unavailable AND
	 *                                    no still-valid cache entry can
	 *                                    be served.
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-03-contact-read/tasks.md
	 */
	public function getContact(string $externalId): PipelinqContact;

	/**
	 * Publish a timeline event to pipelinq.
	 *
	 * Slice 07 of the customer-bridge chain. POSTs the fixed JSON payload
	 * (type, externalId, timestamp, contactId, metadata) to
	 * `/api/v1/timeline` via the slice-02 request loop:
	 *
	 *   - 3 second HTTP timeout (giant decision D5).
	 *   - Shared retry policy (1s/2s/4s, max 3 attempts) on transient 5xx
	 *     and transport errors.
	 *   - Shared circuit breaker (open after 5 consecutive failures, fast
	 *     fail until the cooldown expires).
	 *   - Bearer token sent as `Authorization: Bearer …` only, never
	 *     reaches the payload, the URL, or any log line (ADR-005).
	 *
	 * The method is best-effort: any failure (open breaker, retry budget
	 * exhausted, non-2xx) is logged at WARNING and surfaced as a
	 * `false` return so the caller can hand the event off for async
	 * retry (slice 09) without raising. A success is logged at DEBUG
	 * via the underlying {@see self::request()} loop.
	 *
	 * The design.md contract is `201 Created` on success; the shared
	 * {@see self::request()} loop treats any 2xx as success and returns
	 * the decoded body. We accept that tolerance here rather than
	 * second-guess the transport — if pipelinq ever returns 200/202 to
	 * a publish, that signals an upstream contract drift that surfaces
	 * via the existing DEBUG `pipelinq request succeeded` log line
	 * (which already carries the actual status code).
	 *
	 * @param TimelineEventDto $event Event to publish.
	 *
	 * @return bool TRUE when the publish succeeded (HTTP 201), FALSE otherwise.
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-07-timeline-publish-core/tasks.md
	 */
	public function publishTimelineEvent(TimelineEventDto $event): bool;

	/**
	 * Publish a timeline event and return the granular publish outcome.
	 *
	 * Slice 08 extension over {@see self::publishTimelineEvent()}: the
	 * lifecycle listener needs to distinguish a permanent auth rejection
	 * (401 — never retry, alert admin) from a transient failure (open
	 * breaker / 5xx / network — hand off to the async retry queue). This
	 * method returns the {@see TimelinePublishOutcome} produced by the
	 * publish attempt so the listener can choose the right follow-up.
	 *
	 * Logging contract:
	 *   - Success → INFO `pipelinq timeline publish succeeded`.
	 *   - 401     → ERROR `Invalid pipelinq API token`; the bearer token
	 *               is NEVER included in the log payload (ADR-005).
	 *   - Other   → WARNING `pipelinq timeline publish failed`, identical
	 *               to the slice-07 line.
	 *
	 * The method is best-effort: every failure produces an outcome
	 * value, never an exception.
	 *
	 * @param TimelineEventDto $event Event to publish.
	 *
	 * @return TimelinePublishOutcome Terminal outcome of the publish.
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-08-lifecycle-events/tasks.md
	 */
	public function publishWithOutcome(TimelineEventDto $event): TimelinePublishOutcome;
}//end interface
