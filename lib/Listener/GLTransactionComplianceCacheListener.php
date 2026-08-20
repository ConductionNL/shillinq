<?php

/**
 * GL Transaction Compliance Cache Listener.
 *
 * Slice 08 of the `bookkeeping-waterschappen-bbv-variant` chain
 * (ADR-032). The slice-02 `x-openregister-aggregations` block on
 * `BBVProgramme` materialises `totalBudget`, `ytdSpend`, `utilization`
 * and `complianceStatus` at query time over the mapped GL transaction
 * lines. The slice-08 `ComplianceService` caches the per-programme
 * envelope for 1h (REQ-BBVW-006) so the dashboard widget controller
 * does not re-page the engine on every render.
 *
 * Any GL transaction create/update can shift the YTD spend of any
 * mapped programme (an allocation pair can split a single line across
 * several programmes per REQ-BBVW-002). The cheapest correct response
 * is to drop the whole shillinq compliance cache namespace and let the
 * next dashboard render repopulate from the engine — that is exactly
 * what `ComplianceService::invalidateAll()` does.
 *
 * The listener fires for `GLTransaction`, `GLLine`, and the
 * `GLTransactionLine` schema referenced by the slice-02 aggregation
 * join. Other schemas are ignored; the listener never raises and
 * never blocks the GL write path.
 *
 * @category Listener
 * @package  OCA\Shillinq\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Shillinq\Service\ComplianceService;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drop the shillinq BBV-compliance cache when a GL transaction record
 * is created or updated.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 */
final class GLTransactionComplianceCacheListener implements IEventListener {
	/**
	 * Schema slugs whose create/update should invalidate the cache.
	 *
	 * `GLTransaction` is the bookkeeping-foundation transaction header;
	 * `GLLine` is its line child; `GLTransactionLine` is the slice-02
	 * aggregation join target (some seeds use the long-form slug). All
	 * three are checked case-insensitively in {@see self::isWatched()}.
	 *
	 * @var array<int,string>
	 */
	private const WATCHED_SCHEMAS = [
		'gltransaction',
		'glline',
		'gltransactionline',
	];

	/**
	 * Construct the listener with DI dependencies.
	 *
	 * @param ComplianceService $compliance Compliance cache owner.
	 * @param ListenerSchemaResolver $schemaResolver Resolves the entity's schema id to its slug.
	 * @param LoggerInterface $logger Logger for fail-soft.
	 */
	public function __construct(
		private readonly ComplianceService $compliance,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a GL transaction create or update event by invalidating
	 * the per-programme compliance cache.
	 *
	 * @param Event $event OR ObjectCreatedEvent / ObjectUpdatedEvent.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false
			&& ($event instanceof ObjectUpdatedEvent) === false
		) {
			return;
		}

		try {
			$entity = $event->getObject();
			if ($entity === null || $entity->getSchema() === null) {
				return;
			}

			$schema = $this->schemaResolver->schemaSlug(entity: $entity);
			if ($this->isWatched(schema: $schema) === false) {
				return;
			}

			$this->compliance->invalidateAll();
		} catch (Throwable $e) {
			// Never let a cache-invalidation hiccup bubble into the GL
			// write path — log + continue. A stale envelope expires on
			// the 1h TTL in the worst case.
			$this->logger->warning(
				'Shillinq compliance cache listener: invalidation failed',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end handle()

	/**
	 * Whether the supplied schema string identifies a watched GL schema.
	 *
	 * Schema strings may be plain slugs or `register/schema` paths
	 * depending on OR config — both are accepted.
	 *
	 * @param string $schema Schema identifier from the entity.
	 *
	 * @return bool TRUE when the schema is GLTransaction / GLLine / GLTransactionLine.
	 */
	private function isWatched(string $schema): bool {
		$normalised = strtolower(trim($schema));
		foreach (self::WATCHED_SCHEMAS as $watched) {
			if ($normalised === $watched) {
				return true;
			}

			if (str_ends_with($normalised, ('/' . $watched)) === true) {
				return true;
			}
		}

		return false;
	}//end isWatched()
}//end class
