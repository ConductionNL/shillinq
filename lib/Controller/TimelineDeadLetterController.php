<?php

/**
 * Timeline Dead-Letter Controller.
 *
 * Slice 09 of the `bookings-pipelinq-customer-bridge` chain (ADR-032).
 * Admin-only REST endpoints over the {@see TimelineDeadLetter} register
 * populated by {@see PipelinqTimelineRetryJob} once a queued event
 * exhausts its retry budget. Two actions:
 *
 *   - `index()` — list dead-letter entries for the admin dashboard
 *     (paginated; never returns secrets — the event payload itself
 *     never carried any).
 *   - `retry($id)` — manually re-queue a dead-lettered event by writing
 *     a fresh {@see TimelinePublishRetryEntry} (retryCount reset to 0)
 *     and adding a {@see PipelinqTimelineRetryJob} tick. Stamps
 *     `dispatchedAt` on the dead-letter row so the audit trail is
 *     preserved without immediately deleting the source.
 *
 * Both actions are gated by `#[AuthorizedAdminSetting]` so only admins
 * authorised for the shillinq settings section may invoke them
 * (ADR-005: admin-only operations on operator dashboards).
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
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

namespace OCA\Shillinq\Controller;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\BackgroundJob\PipelinqTimelineRetryJob;
use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Admin endpoints over the timeline dead-letter queue.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-09-async-retry/tasks.md
 */
class TimelineDeadLetterController extends Controller {
	/**
	 * Construct the controller with its dependencies.
	 *
	 * @param IRequest $request The current HTTP request.
	 * @param SettingsService $settings Resolves OR register slug + availability.
	 * @param ContainerInterface $container DI container for OR ObjectService.
	 * @param IJobList $jobList Background-job list for manual re-queue.
	 * @param ITimeFactory $time Time factory for stamping dispatchedAt.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly SettingsService $settings,
		private readonly ContainerInterface $container,
		private readonly IJobList $jobList,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * GET the current dead-letter list (admin dashboard).
	 *
	 * Returns the most recent dead-lettered events. Each row carries the
	 * persisted event details + retryCount + lastError + failedAt +
	 * dispatchedAt; no secrets ever leak because the payload itself
	 * never carried the bearer token (ADR-005).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-09-async-retry/tasks.md
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function index(): JSONResponse {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			return new JSONResponse(
				['results' => [], 'total' => 0],
				Http::STATUS_OK
			);
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$registerSlug = $this->settings->getRegisterSlug();

			$rows = $objectService
				->setRegister($registerSlug)
				->setSchema('TimelineDeadLetter')
				->findAll(
					[
						'limit' => 500,
					]
				);

			$results = [];
			foreach ($rows as $row) {
				$payload = $this->toArray(object: $row);
				if ($payload === null) {
					continue;
				}

				$results[] = $payload;
			}

			return new JSONResponse(
				[
					'results' => $results,
					'total' => count($results),
				],
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'TimelineDeadLetterController: index failed',
				[
					'app' => Application::APP_ID,
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				['error' => 'Failed to list dead-letter entries'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

	}//end index()

	/**
	 * Re-queue a dead-lettered event by id.
	 *
	 * Builds a fresh TimelinePublishRetryEntry from the dead-letter row
	 * (retryCount reset to 0, lastError cleared, nextRetryAt = now) and
	 * adds a PipelinqTimelineRetryJob tick. Stamps dispatchedAt on the
	 * source dead-letter row so the audit trail records the manual
	 * intervention.
	 *
	 * @param string $id Dead-letter entry id (UUID).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-09-async-retry/tasks.md
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function retry(string $id): JSONResponse {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			return new JSONResponse(
				['error' => 'OpenRegister is not available'],
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		if (trim($id) === '') {
			return new JSONResponse(
				['error' => 'Missing dead-letter id'],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$registerSlug = $this->settings->getRegisterSlug();

			$dead = $this->loadDead(
				objectService: $objectService,
				registerSlug: $registerSlug,
				id: $id
			);

			if ($dead === null) {
				return new JSONResponse(
					['error' => 'Dead-letter entry not found'],
					Http::STATUS_NOT_FOUND
				);
			}

			$nowIso = $this->nowIso();

			$retry = [
				'type' => (string)($dead['type'] ?? ''),
				'externalId' => (string)($dead['externalId'] ?? ''),
				'contactId' => (string)($dead['contactId'] ?? ''),
				'timestampIso' => (string)($dead['timestampIso'] ?? ''),
				'metadata' => ($dead['metadata'] ?? null),
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
					object: $retry,
					register: $registerSlug,
					schema: 'TimelinePublishRetryEntry'
				);

			$entryId = $this->extractId(result: $saved);
			if ($entryId === null || $entryId === '') {
				return new JSONResponse(
					['error' => 'Failed to persist re-queued entry'],
					Http::STATUS_INTERNAL_SERVER_ERROR
				);
			}

			$this->jobList->add(
				PipelinqTimelineRetryJob::class,
				['entryId' => $entryId]
			);

			// Stamp dispatchedAt on the dead-letter row so audit shows it
			// was manually handled — preserve the row for post-mortem.
			$dead['dispatchedAt'] = $nowIso;
			$deadId = (string)($dead['id'] ?? $id);
			if ($deadId === '') {
				$deadId = $id;
			}

			// Ensure the saveObject() target stays the same row.
			if (isset($dead['id']) === false) {
				$dead['id'] = $deadId;
			}

			$objectService
				->setRegister($registerSlug)
				->setSchema('TimelineDeadLetter')
				->saveObject(
					object: $dead,
					register: $registerSlug,
					schema: 'TimelineDeadLetter'
				);

			$this->logger->info(
				'TimelineDeadLetterController: dead-letter manually re-queued',
				[
					'app' => Application::APP_ID,
					'deadLetter' => $deadId,
					'retryEntry' => $entryId,
					'type' => $retry['type'],
					'externalId' => $retry['externalId'],
				]
			);

			return new JSONResponse(
				[
					'success' => true,
					'entryId' => $entryId,
					'deadLetter' => $deadId,
				],
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'TimelineDeadLetterController: retry failed',
				[
					'app' => Application::APP_ID,
					'id' => $id,
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				['error' => 'Failed to re-queue dead-letter entry'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

	}//end retry()

	/**
	 * Look up a dead-letter entry by id.
	 *
	 * @param mixed $objectService OR ObjectService.
	 * @param string $registerSlug Register slug.
	 * @param string $id Dead-letter id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function loadDead(mixed $objectService, string $registerSlug, string $id): ?array {
		try {
			$record = $objectService
				->setRegister($registerSlug)
				->setSchema('TimelineDeadLetter')
				->find($id);
		} catch (Throwable $e) {
			return null;
		}

		return $this->toArray(object: $record);
	}//end loadDead()

	/**
	 * Extract a string id from a saveObject() return value.
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
	 * Normalise an OR record into a flat array (copying id through).
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
