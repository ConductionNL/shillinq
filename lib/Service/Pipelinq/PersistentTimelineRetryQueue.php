<?php

/**
 * Persistent TimelineRetryQueue binding (slice 09).
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Pipelinq;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\BackgroundJob\PipelinqTimelineRetryJob;
use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

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
	 * @param SettingsService $settings Resolves the active OR register
	 *                                  slug.
	 * @param ContainerInterface $container DI container for late-bound OR
	 *                                      ObjectService lookup.
	 * @param IJobList $jobList Nextcloud background-job list.
	 * @param ITimeFactory $time Time factory for nowIso().
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly SettingsService $settings,
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
			if ($this->settings->isOpenRegisterAvailable() === false) {
				$this->logger->warning(
					'PersistentTimelineRetryQueue: OR not available, falling back to logging',
					[
						'app' => Application::APP_ID,
						'type' => $event->type(),
						'externalId' => $event->externalId(),
					]
				);
				return;
			}

			$entryId = $this->persistEntry(event: $event);
			if ($entryId === null) {
				return;
			}

			try {
				$this->jobList->add(
					PipelinqTimelineRetryJob::class,
					['entryId' => $entryId]
				);
			} catch (Throwable $e) {
				$this->logger->warning(
					'PersistentTimelineRetryQueue: failed to schedule retry job',
					[
						'app' => Application::APP_ID,
						'entryId' => $entryId,
						'exception' => $e->getMessage(),
					]
				);
				return;
			}

			$this->logger->info(
				'PersistentTimelineRetryQueue: event queued for async retry',
				[
					'app' => Application::APP_ID,
					'entryId' => $entryId,
					'type' => $event->type(),
					'externalId' => $event->externalId(),
					'contactId' => $event->contactId(),
				]
			);
		} catch (Throwable $e) {
			// Outer safety net — enqueue() MUST NEVER raise (the booking
			// commit already happened).
			$this->logger->warning(
				'PersistentTimelineRetryQueue: enqueue failed, falling back to logging',
				[
					'app' => Application::APP_ID,
					'type' => $event->type(),
					'externalId' => $event->externalId(),
					'exception' => $e->getMessage(),
				]
			);
		}//end try

	}//end enqueue()

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
					register: $registerSlug,
					schema: 'TimelinePublishRetryEntry'
				);

			return $this->extractId(result: $saved);
		} catch (Throwable $e) {
			$this->logger->warning(
				'PersistentTimelineRetryQueue: failed to persist retry entry',
				[
					'app' => Application::APP_ID,
					'type' => $event->type(),
					'externalId' => $event->externalId(),
					'exception' => $e->getMessage(),
				]
			);
			return null;
		}//end try

	}//end persistEntry()

	/**
	 * Pull a string id out of an OR saveObject() return value (entity or array).
	 *
	 * @param mixed $result saveObject() return value.
	 *
	 * @return string|null
	 */
	private function extractId(mixed $result): ?string {
		if (is_array($result) === true) {
			$id = (string)($result['id'] ?? '');
			if ($id !== '') {
				return $id;
			}

			return null;
		}

		if (is_object($result) === true) {
			if (method_exists($result, 'getUuid') === true) {
				$uuid = (string)$result->getUuid();
				if ($uuid !== '') {
					return $uuid;
				}
			}

			if (method_exists($result, 'jsonSerialize') === true) {
				$payload = $result->jsonSerialize();
				if (is_array($payload) === true) {
					$id = (string)($payload['id'] ?? ($payload['@self']['id'] ?? ''));
					if ($id !== '') {
						return $id;
					}
				}
			}

			if (method_exists($result, 'getId') === true) {
				$id = (string)$result->getId();
				if ($id !== '') {
					return $id;
				}
			}
		}//end if

		return null;
	}//end extractId()

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
