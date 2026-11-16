<?php

/**
 * Persistent TimelineRetryQueue binding (slice 09).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Replaces the slice-07 {@see LoggingTimelineRetryQueue} stub with a real
 * persistent queue: on every {@see self::enqueue()} call we
 *
 *   1. write a {@see TimelinePublishRetryEntry} OR record carrying the
 *      event payload + retryCount 0 + a `nextRetryAt` of "now",
 *   2. add a {@see PipelinqTimelineRetryJob} entry to Nextcloud's
 *      `IJobList` with that entry's id as its argument.
 *
 * The {@see PipelinqTimelineRetryJob} reads the row on each tick, calls
 * {@see PipelinqContactAdapter::publishTimelineEvent()}, and either
 * deletes the row (success), advances the backoff + re-queues (failure
 * with budget left), or dead-letters (failure with budget exhausted) per
 * D3 in the giant.
 *
 * Failure semantics: enqueue() MUST NEVER raise — the booking commit
 * already happened by the time the listener calls in, and a queueing
 * failure would mask the original publish failure. We swallow both the
 * OR write error and the job-list add error, logging at WARNING, and
 * silently degrade to the slice-07 logging behaviour for the affected
 * event (an operator can still see the failed-publish log line from
 * slice 07 + this WARNING and intervene).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Pipelinq
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-09-async-retry/tasks.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Pipelinq;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\BackgroundJob\PipelinqTimelineRetryJob;
use OCA\Shillinq\Service\RegisterLocation;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use OCA\Shillinq\Kernel\Model\TimelineEventDto;

/**
 * Default slice-09 binding for {@see TimelineRetryQueue}.
 *
 * Persists failed events into the TimelinePublishRetryEntry register and
 * adds a {@see PipelinqTimelineRetryJob} tick to the job list.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-09-async-retry/tasks.md
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
final class PersistentTimelineRetryQueue implements TimelineRetryQueue {
	/**
	 * Construct the persistent queue.
	 *
	 * @param RegisterLocation $settings Where the register lives (slug + OpenRegister availability).
	 *                                  slug.
	 * @param ContainerInterface $container DI container for late-bound OR
	 *                                      ObjectService lookup.
	 * @param IJobList $jobList Nextcloud background-job list.
	 * @param ITimeFactory $time Time factory for nowIso().
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly RegisterLocation $settings,
		private readonly ContainerInterface $container,
		private readonly IJobList $jobList,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Hand the event off for async retry.
	 *
	 * Writes a TimelinePublishRetryEntry record, then adds a
	 * PipelinqTimelineRetryJob tick keyed on the entry's id.
	 *
	 * @param TimelineEventDto $event Event the synchronous publish failed for.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-09-async-retry/tasks.md
	 */
	public function enqueue(TimelineEventDto $event): void {
		try {
			$this->enqueueEntry(event: $event);
		} catch (Throwable $e) {
			// Outer safety net — enqueue() MUST NEVER raise (the booking
			// commit already happened).
			$this->logger->warning(
				'PersistentTimelineRetryQueue: enqueue failed, falling back to logging',
				[
					'app' => AppIdentity::APP_ID,
					'type' => $event->type(),
					'externalId' => $event->externalId(),
					'exception' => $e->getMessage(),
				]
			);
		}//end try

	}//end enqueue()

	/**
	 * Persist the retry entry and schedule its tick, falling back to logging without OpenRegister.
	 *
	 * @param TimelineEventDto $event Event the synchronous publish failed for.
	 *
	 * @return void
	 *
	 * @throws Throwable When the settings or the event fail.
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-09-async-retry/tasks.md
	 */
	private function enqueueEntry(TimelineEventDto $event): void {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			$this->logger->warning(
				'PersistentTimelineRetryQueue: OR not available, falling back to logging',
				['app' => AppIdentity::APP_ID, 'type' => $event->type(), 'externalId' => $event->externalId()]
			);
			return;
		}

		$entryId = $this->persistEntry(event: $event);
		if ($entryId === null) {
			return;
		}

		if ($this->scheduleRetry(entryId: $entryId) === false) {
			return;
		}

		$this->logQueued(entryId: $entryId, event: $event);
	}//end enqueueEntry()

	/**
	 * Log that an event is queued for async retry.
	 *
	 * @param string           $entryId The retry entry id.
	 * @param TimelineEventDto $event   The queued event.
	 *
	 * @return void
	 */
	private function logQueued(string $entryId, TimelineEventDto $event): void {
		$this->logger->info(
			'PersistentTimelineRetryQueue: event queued for async retry',
			[
				'app' => AppIdentity::APP_ID,
				'entryId' => $entryId,
				'type' => $event->type(),
				'externalId' => $event->externalId(),
				'contactId' => $event->contactId(),
			]
		);
	}//end logQueued()

	/**
	 * Add the retry job tick for an entry; a failure is logged.
	 *
	 * @param string $entryId The retry entry id.
	 *
	 * @return bool True when the tick was added.
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-09-async-retry/tasks.md
	 */
	private function scheduleRetry(string $entryId): bool {
		try {
			$this->jobList->add(
				PipelinqTimelineRetryJob::class,
				['entryId' => $entryId]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'PersistentTimelineRetryQueue: failed to schedule retry job',
				[
					'app' => AppIdentity::APP_ID,
					'entryId' => $entryId,
					'exception' => $e->getMessage(),
				]
			);
			return false;
		}

		return true;
	}//end scheduleRetry()

	/**
	 * Write the TimelinePublishRetryEntry row and return its id.
	 *
	 * @param TimelineEventDto $event Event to persist.
	 *
	 * @return string|null Persisted entry id, or NULL on write failure.
	 */
	private function persistEntry(TimelineEventDto $event): ?string {
		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$registerSlug = $this->settings->getRegisterSlug();

			$nowIso = $this->nowIso();
			$timestampIso = $this->renderTimestamp(timestamp: $event->timestamp());

			$payload = [
				'type' => $event->type(),
				'externalId' => $event->externalId(),
				'contactId' => $event->contactId(),
				'timestampIso' => $timestampIso,
				'metadata' => $event->metadata(),
				'retryCount' => 0,
				'maxRetries' => PipelinqTimelineRetryJob::DEFAULT_MAX_RETRIES,
				'nextRetryAt' => $nowIso,
				'lastError' => null,
				'lastAttemptAt' => null,
			];

			$saved = $objectService
				->setRegister($registerSlug)
				->setSchema('TimelinePublishRetryEntry')
				->saveObject(
					object: $payload,
					options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $registerSlug, schema: 'TimelinePublishRetryEntry')),
					_rbac: false
				);

			return (new StoredRecord())->idOf(result: $saved);
		} catch (Throwable $e) {
			$this->logger->warning(
				'PersistentTimelineRetryQueue: failed to persist retry entry',
				[
					'app' => AppIdentity::APP_ID,
					'type' => $event->type(),
					'externalId' => $event->externalId(),
					'exception' => $e->getMessage(),
				]
			);
			return null;
		}//end try

	}//end persistEntry()

	/**
	 * Current UTC time as ISO-8601.
	 *
	 * @return string
	 */
	private function nowIso(): string {
		return DateTimeImmutable::createFromInterface(
			$this->time->getDateTime()
		)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

	}//end nowIso()

	/**
	 * Render a DateTimeInterface as UTC ISO-8601.
	 *
	 * @param \DateTimeInterface $timestamp Timestamp to render.
	 *
	 * @return string
	 */
	private function renderTimestamp(\DateTimeInterface $timestamp): string {
		if (($timestamp instanceof DateTimeImmutable) === true) {
			$utc = $timestamp;
		} else {
			$utc = DateTimeImmutable::createFromInterface($timestamp);
		}

		return $utc->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
	}//end renderTimestamp()
}//end class
