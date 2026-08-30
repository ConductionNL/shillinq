<?php

/**
 * Pipelinq Timeline Retry Job.
 *
 * Slice 09 of the `bookings-pipelinq-customer-bridge` chain (ADR-032).
 * Background job that drives the async-retry fallback: each tick reads the
 * persisted {@see TimelinePublishRetryEntry} pointed to by the job
 * argument, reconstructs the original {@see TimelineEventDto}, and asks
 * {@see PipelinqContactAdapter::publishTimelineEvent()} for another go.
 *
 * Outcomes per tick:
 *
 *   - publish OK   → delete the entry, log DEBUG.
 *   - publish fail + retryCount + 1 ≤ maxRetries → bump retryCount,
 *     advance `nextRetryAt` per exponential backoff (1m / 5m / 30m), keep
 *     the entry, log INFO.
 *   - publish fail + retryCount + 1 > maxRetries → move the row into the
 *     {@see TimelineDeadLetter} schema, delete the retry entry, log
 *     ERROR with booking id + event type (D3 in the giant).
 *
 * The job uses {@see QueuedJob} (single-shot per registration): one
 * argument set = one queued event. The {@see PersistentTimelineRetryQueue}
 * is responsible for both initial enqueue and re-enqueue on failed ticks.
 * NextCloud's job runner respects the `executeAfter` timestamp that the
 * queue carries in the argument, but since v25 the runner does not enforce
 * it natively — we therefore re-check `nextRetryAt` against the current
 * time at the top of `run()` and reschedule (no-op tick) when the entry is
 * not yet due. That keeps the cron tick cheap and gives the exponential
 * backoff its real teeth.
 *
 * @category Cron
 * @package  OCA\Shillinq\BackgroundJob
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

namespace OCA\Shillinq\BackgroundJob;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\Pipelinq\PipelinqContactAdapter;
use OCA\Shillinq\Service\Pipelinq\TimelineEventDto;
use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tick the retry pipeline for one queued timeline event.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-09-async-retry/tasks.md
 */
class PipelinqTimelineRetryJob extends QueuedJob {

	/**
	 * Default maximum number of retry attempts before dead-lettering.
	 *
	 * Three retries with 1m / 5m / 30m backoff gives ~36 minutes of grace
	 * before an admin has to look at the event manually.
	 */
	public const DEFAULT_MAX_RETRIES = 3;

	/**
	 * Exponential backoff schedule in seconds, indexed by 0-based retry
	 * attempt number. The schedule terminates after MAX_RETRIES so the
	 * dead-letter transition is reached exactly once.
	 *
	 * @var array<int, int>
	 */
	public const BACKOFF_SECONDS = [
		0 => 60,
		1 => 300,
		2 => 1800,
	];

	/**
	 * Construct the job with its container and dependencies.
	 *
	 * @param ITimeFactory $time Time factory (also passed to
	 *                           QueuedJob parent for scheduling).
	 * @param SettingsService $settings Resolves the active OR register
	 *                                  slug.
	 * @param ContainerInterface $container DI container for the late-bound
	 *                                      OR ObjectService lookup (the
	 *                                      service may not be available at
	 *                                      construction time on the unit
	 *                                      tests).
	 * @param IJobList $jobList Nextcloud job list (used to
	 *                          re-queue this job with the next
	 *                          backoff delay).
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly SettingsService $settings,
		private readonly ContainerInterface $container,
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		$this->timeFactory = $time;

	}//end __construct()

	/**
	 * Captured ITimeFactory (the parent owns a protected `$time` of the same
	 * type; the duplicate makes intent explicit and helps testing).
	 *
	 * @var ITimeFactory
	 */
	private ITimeFactory $timeFactory;

	/**
	 * Drain one queued timeline event.
	 *
	 * @param mixed $argument Job argument from PersistentTimelineRetryQueue:
	 *                        an array carrying the `entryId` of the
	 *                        TimelinePublishRetryEntry to attempt.
	 *
	 * @return void
	 */
	protected function run($argument): void {
		if (is_array($argument) === false || isset($argument['entryId']) === false) {
			$this->logger->warning(
				'PipelinqTimelineRetryJob: invalid argument, skipping',
				['app' => Application::APP_ID]
			);
			return;
		}

		$entryId = (string)$argument['entryId'];
		if ($entryId === '') {
			$this->logger->warning(
				'PipelinqTimelineRetryJob: empty entryId, skipping',
				['app' => Application::APP_ID]
			);
			return;
		}

		if ($this->settings->isOpenRegisterAvailable() === false) {
			$this->logger->info(
				'PipelinqTimelineRetryJob: OpenRegister not available, skipping',
				['app' => Application::APP_ID]
			);
			return;
		}

		try {
			$registerSlug = $this->settings->getRegisterSlug();
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');

			$entry = $this->loadEntry(
				objectService: $objectService,
				registerSlug: $registerSlug,
				entryId: $entryId
			);

			if ($entry === null) {
				// Entry already deleted (successful retry in a previous tick,
				// or admin-removed); idempotent no-op.
				$this->logger->debug(
					'PipelinqTimelineRetryJob: entry not found, treating as already drained',
					[
						'app' => Application::APP_ID,
						'entryId' => $entryId,
					]
				);
				return;
			}

			$nowDt = $this->nowDateTime();
			if ($this->isDue(entry: $entry, now: $nowDt) === false) {
				// Reschedule for the next backoff window; the runner saw us
				// too early.
				$this->enqueueNextTick(entryId: $entryId);
				$this->logger->debug(
					'PipelinqTimelineRetryJob: entry not yet due, re-queued',
					[
						'app' => Application::APP_ID,
						'entryId' => $entryId,
						'nextRetryAt' => (string)($entry['nextRetryAt'] ?? ''),
					]
				);
				return;
			}

			$dto = $this->reconstructDto(entry: $entry);
			$adapter = $this->container->get(PipelinqContactAdapter::class);

			$ok = $adapter->publishTimelineEvent(event: $dto);
			if ($ok === true) {
				$this->deleteEntry(
					objectService: $objectService,
					registerSlug: $registerSlug,
					entryId: $entryId
				);
				$this->logger->debug(
					'PipelinqTimelineRetryJob: retry succeeded, entry drained',
					[
						'app' => Application::APP_ID,
						'entryId' => $entryId,
						'type' => $dto->type(),
						'externalId' => $dto->externalId(),
					]
				);
				return;
			}

			$this->handleFailure(
				objectService: $objectService,
				registerSlug: $registerSlug,
				entry: $entry,
				entryId: $entryId,
				now: $nowDt
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'PipelinqTimelineRetryJob: tick failed unexpectedly',
				[
					'app' => Application::APP_ID,
					'entryId' => $entryId,
					'exception' => $e->getMessage(),
				]
			);
		}//end try

	}//end run()

	/**
	 * Increment retryCount, then either advance nextRetryAt + re-queue or
	 * dead-letter the entry.
	 *
	 * @param mixed $objectService OR ObjectService.
	 * @param string $registerSlug Register slug.
	 * @param array<string, mixed> $entry Loaded retry entry.
	 * @param string $entryId Entry id (also the @self).
	 * @param DateTimeImmutable $now Current UTC time.
	 *
	 * @return void
	 */
	private function handleFailure(
		mixed $objectService,
		string $registerSlug,
		array $entry,
		string $entryId,
		DateTimeImmutable $now,
	): void {
		$retryCount = (int)($entry['retryCount'] ?? 0);
		$maxRetries = (int)($entry['maxRetries'] ?? self::DEFAULT_MAX_RETRIES);
		$nextCount = ($retryCount + 1);

		if ($nextCount > $maxRetries) {
			// Move to dead-letter.
			$this->deadLetter(
				objectService: $objectService,
				registerSlug: $registerSlug,
				entry: $entry,
				entryId: $entryId,
				retryCount: $retryCount,
				now: $now
			);
			$this->logger->error(
				'PipelinqTimelineRetryJob: max retries exhausted, dead-lettered',
				[
					'app' => Application::APP_ID,
					'entryId' => $entryId,
					'type' => (string)($entry['type'] ?? ''),
					'externalId' => (string)($entry['externalId'] ?? ''),
					'retries' => $retryCount,
				]
			);
			return;
		}//end if

		// Bump retry count, advance nextRetryAt, re-queue.
		$backoffSeconds = self::BACKOFF_SECONDS[$retryCount] ?? 1800;
		$nextRetryAt = $now->modify('+' . $backoffSeconds . ' seconds');

		$entry['retryCount'] = $nextCount;
		$entry['nextRetryAt'] = $nextRetryAt->format('Y-m-d\TH:i:s\Z');
		$entry['lastAttemptAt'] = $now->format('Y-m-d\TH:i:s\Z');
		$entry['lastError'] = 'publish_failed';

		$this->saveEntry(
			objectService: $objectService,
			registerSlug: $registerSlug,
			entry: $entry
		);

		$this->enqueueNextTick(entryId: $entryId);

		$this->logger->info(
			'PipelinqTimelineRetryJob: retry failed, re-queued with backoff',
			[
				'app' => Application::APP_ID,
				'entryId' => $entryId,
				'retryCount' => $nextCount,
				'nextRetryAt' => $entry['nextRetryAt'],
				'backoffSeconds' => $backoffSeconds,
			]
		);

	}//end handleFailure()

	/**
	 * Compute due-ness from an entry's nextRetryAt vs now.
	 *
	 * @param array<string, mixed> $entry Loaded retry entry.
	 * @param DateTimeImmutable $now Current UTC time.
	 *
	 * @return bool TRUE when nextRetryAt has elapsed (or is unparseable, so we run).
	 */
	private function isDue(array $entry, DateTimeImmutable $now): bool {
		$nextRetryAt = trim((string)($entry['nextRetryAt'] ?? ''));
		if ($nextRetryAt === '') {
			return true;
		}

		try {
			$due = new DateTimeImmutable($nextRetryAt, new DateTimeZone('UTC'));
			return ($due <= $now);
		} catch (\Exception $e) {
			// Malformed timestamp — let the tick run rather than block.
			return true;
		}

	}//end isDue()

	/**
	 * Reconstruct the TimelineEventDto from a retry entry's persisted fields.
	 *
	 * @param array<string, mixed> $entry Retry entry payload.
	 *
	 * @return TimelineEventDto
	 */
	private function reconstructDto(array $entry): TimelineEventDto {
		$timestampIso = trim((string)($entry['timestampIso'] ?? ''));
		try {
			$timestamp = new DateTimeImmutable($timestampIso, new DateTimeZone('UTC'));
		} catch (\Exception $e) {
			// Fall back to "now" — the DTO requires a parseable timestamp;
			// if the original is unparseable we still want to attempt the
			// publish (a slightly-shifted timestamp is preferable to losing
			// the event entirely).
			$timestamp = $this->nowDateTime();
		}

		$metadata = [];
		if (is_array($entry['metadata'] ?? null) === true) {
			// The metadata is a string-keyed scalar map (see DTO contract).
			$metadata = $entry['metadata'];
		}

		return new TimelineEventDto(
			type: (string)($entry['type'] ?? ''),
			externalId: (string)($entry['externalId'] ?? ''),
			timestamp: $timestamp,
			contactId: (string)($entry['contactId'] ?? ''),
			metadata: $metadata
		);

	}//end reconstructDto()

	/**
	 * Look up the retry entry by id.
	 *
	 * @param mixed $objectService OR ObjectService.
	 * @param string $registerSlug Register slug.
	 * @param string $entryId Entry id to load.
	 *
	 * @return array<string, mixed>|null
	 */
	private function loadEntry(mixed $objectService, string $registerSlug, string $entryId): ?array {
		try {
			$record = $objectService
				->setRegister($registerSlug)
				->setSchema('TimelinePublishRetryEntry')
				->find($entryId);
		} catch (Throwable $e) {
			return null;
		}

		return $this->toArray(object: $record);
	}//end loadEntry()

	/**
	 * Persist an updated retry entry.
	 *
	 * @param mixed $objectService OR ObjectService.
	 * @param string $registerSlug Register slug.
	 * @param array<string, mixed> $entry Retry entry to save (with id).
	 *
	 * @return void
	 */
	private function saveEntry(mixed $objectService, string $registerSlug, array $entry): void {
		$objectService
			->setRegister($registerSlug)
			->setSchema('TimelinePublishRetryEntry')
			->saveObject(
				object: $entry,
				register: $registerSlug,
				schema: 'TimelinePublishRetryEntry'
			);

	}//end saveEntry()

	/**
	 * Delete the retry entry (successful drain or dead-letter move).
	 *
	 * @param mixed $objectService OR ObjectService.
	 * @param string $registerSlug Register slug.
	 * @param string $entryId Entry id to delete.
	 *
	 * @return void
	 */
	private function deleteEntry(mixed $objectService, string $registerSlug, string $entryId): void {
		try {
			$objectService
				->setRegister($registerSlug)
				->setSchema('TimelinePublishRetryEntry')
				->deleteObject($entryId);
		} catch (Throwable $e) {
			// Fail-soft — if the row already vanished a subsequent
			// attempt will see the not-found path and treat it as drained.
			$this->logger->warning(
				'PipelinqTimelineRetryJob: failed to delete retry entry',
				[
					'app' => Application::APP_ID,
					'entryId' => $entryId,
					'exception' => $e->getMessage(),
				]
			);
		}

	}//end deleteEntry()

	/**
	 * Move the entry to the dead-letter queue + delete the retry row.
	 *
	 * @param mixed $objectService OR ObjectService.
	 * @param string $registerSlug Register slug.
	 * @param array<string, mixed> $entry Retry entry payload.
	 * @param string $entryId Entry id.
	 * @param int $retryCount Final retryCount reached.
	 * @param DateTimeImmutable $now Current UTC time.
	 *
	 * @return void
	 */
	private function deadLetter(
		mixed $objectService,
		string $registerSlug,
		array $entry,
		string $entryId,
		int $retryCount,
		DateTimeImmutable $now,
	): void {
		$dead = [
			'type' => (string)($entry['type'] ?? ''),
			'externalId' => (string)($entry['externalId'] ?? ''),
			'contactId' => (string)($entry['contactId'] ?? ''),
			'timestampIso' => (string)($entry['timestampIso'] ?? ''),
			'metadata' => ($entry['metadata'] ?? null),
			'retryCount' => $retryCount,
			'lastError' => (string)($entry['lastError'] ?? 'max_retries_exhausted'),
			'failedAt' => $now->format('Y-m-d\TH:i:s\Z'),
			'dispatchedAt' => null,
		];

		try {
			$objectService
				->setRegister($registerSlug)
				->setSchema('TimelineDeadLetter')
				->saveObject(
					object: $dead,
					register: $registerSlug,
					schema: 'TimelineDeadLetter'
				);
		} catch (Throwable $e) {
			$this->logger->error(
				'PipelinqTimelineRetryJob: failed to write dead-letter entry',
				[
					'app' => Application::APP_ID,
					'entryId' => $entryId,
					'exception' => $e->getMessage(),
				]
			);
			// Re-raise — losing both the retry row and the dead-letter
			// copy would silently drop the event; the outer run() catches.
			throw $e;
		}//end try

		$this->deleteEntry(
			objectService: $objectService,
			registerSlug: $registerSlug,
			entryId: $entryId
		);

	}//end deadLetter()

	/**
	 * Re-queue ourselves for another tick on the same entry. Used after a
	 * fail (with the new backoff) and on the "too early" no-op path.
	 *
	 * @param string $entryId Entry id to tick again.
	 *
	 * @return void
	 */
	private function enqueueNextTick(string $entryId): void {
		try {
			$this->jobList->add(self::class, ['entryId' => $entryId]);
		} catch (Throwable $e) {
			$this->logger->warning(
				'PipelinqTimelineRetryJob: failed to re-queue retry tick',
				[
					'app' => Application::APP_ID,
					'entryId' => $entryId,
					'exception' => $e->getMessage(),
				]
			);
		}

	}//end enqueueNextTick()

	/**
	 * Now as a UTC DateTimeImmutable.
	 *
	 * @return DateTimeImmutable
	 */
	private function nowDateTime(): DateTimeImmutable {
		return DateTimeImmutable::createFromInterface(
			$this->timeFactory->getDateTime()
		)->setTimezone(new DateTimeZone('UTC'));

	}//end nowDateTime()

	/**
	 * Normalise an OR record into a flat array, copying the id through under
	 * `@self.id` when present (the form OR returns when the entity has an id).
	 *
	 * @param mixed $object OR ObjectService payload.
	 *
	 * @return array<string, mixed>|null
	 */
	private function toArray(mixed $object): ?array {
		if ($object === null) {
			return null;
		}

		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true) {
			if (method_exists($object, 'jsonSerialize') === true) {
				$serialised = $object->jsonSerialize();
				if (is_array($serialised) === true) {
					return $serialised;
				}
			}

			if (method_exists($object, 'getObject') === true) {
				$inner = $object->getObject();
				if (is_array($inner) === true) {
					// The id lives on the entity, fold it into the payload
					// so subsequent saveObject() targets the same row.
					if (method_exists($object, 'getUuid') === true) {
						$uuid = (string)$object->getUuid();
						if ($uuid !== '' && isset($inner['id']) === false) {
							$inner['id'] = $uuid;
						}
					}

					return $inner;
				}
			}

			return (array)$object;
		}//end if

		return null;
	}//end toArray()
}//end class
