<?php

/**
 * Timeline Dead-Letter Controller.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Shillinq\BackgroundJob\PipelinqTimelineRetryJob;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\RegisterLocationInterface;
use OCA\Shillinq\Util\ObjectIdentifier;
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
	 * @param RegisterLocationInterface $settings Where the register lives (slug + OpenRegister availability).
	 * @param ContainerInterface $container DI container for OR ObjectService.
	 * @param IJobList $jobList Background-job list for manual re-queue.
	 * @param ITimeFactory $time Time factory for stamping dispatchedAt.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly RegisterLocationInterface $settings,
		private readonly ContainerInterface $container,
		private readonly IJobList $jobList,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

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
	#[AuthorizedAdminSetting(AppIdentity::APP_ID)]
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
					'app' => AppIdentity::APP_ID,
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
	#[AuthorizedAdminSetting(AppIdentity::APP_ID)]
	public function retry(string $id): JSONResponse {
		$guard = $this->retryGuard(id: $id);
		if ($guard !== null) {
			return $guard;
		}

		try {
			return $this->requeue(id: $id);
		} catch (Throwable $e) {
			return $this->retryFailed(id: $id, e: $e);
		}

	}//end retry()

	/**
	 * Log a failed retry and build the error response.
	 *
	 * @param string    $id Dead-letter entry id (UUID).
	 * @param Throwable $e  The failure.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-09-async-retry/tasks.md
	 */
	private function retryFailed(string $id, Throwable $e): JSONResponse {
		$this->logger->error(
			'TimelineDeadLetterController: retry failed',
			[
				'app' => AppIdentity::APP_ID,
				'id' => $id,
				'exception' => $e->getMessage(),
			]
		);
		return new JSONResponse(
			['error' => 'Failed to re-queue dead-letter entry'],
			Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end retryFailed()

	/**
	 * Reject a retry request when OpenRegister is missing or the id is empty.
	 *
	 * @param string $id Dead-letter entry id (UUID).
	 *
	 * @return JSONResponse|null The rejection, or null when the request may proceed.
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-09-async-retry/tasks.md
	 */
	private function retryGuard(string $id): JSONResponse|null {
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

		return null;
	}//end retryGuard()

	/**
	 * Load the dead-letter entry and re-queue it.
	 *
	 * @param string $id Dead-letter entry id (UUID).
	 *
	 * @return JSONResponse
	 *
	 * @throws Throwable When the container or OpenRegister fails.
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-09-async-retry/tasks.md
	 */
	private function requeue(string $id): JSONResponse {
		$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		$registerSlug = $this->settings->getRegisterSlug();

		$dead = $this->loadDead(objectService: $objectService, registerSlug: $registerSlug, id: $id);

		if ($dead === null) {
			return new JSONResponse(
				['error' => 'Dead-letter entry not found'],
				Http::STATUS_NOT_FOUND
			);
		}

		return $this->requeueDead(objectService: $objectService, registerSlug: $registerSlug, dead: $dead, id: $id);
	}//end requeue()

	/**
	 * Write a fresh retry entry for a dead-letter row, schedule a tick and stamp the row.
	 *
	 * @param mixed                $objectService OR ObjectService.
	 * @param string               $registerSlug  Register slug.
	 * @param array<string, mixed> $dead          Dead-letter row.
	 * @param string               $id            Dead-letter entry id (UUID).
	 *
	 * @return JSONResponse
	 *
	 * @throws Throwable When OpenRegister fails to save.
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-09-async-retry/tasks.md
	 */
	private function requeueDead(mixed $objectService, string $registerSlug, array $dead, string $id): JSONResponse {
		$nowIso = $this->nowIso();

		$retry = $this->buildRetryEntry(dead: $dead, nowIso: $nowIso);

		$saved = $this->saveTo(objectService: $objectService, registerSlug: $registerSlug, schema: 'TimelinePublishRetryEntry', object: $retry);

		$entryId = $this->extractId(result: $saved);
		if ($entryId === null || $entryId === '') {
			return new JSONResponse(['error' => 'Failed to persist re-queued entry'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$this->jobList->add(PipelinqTimelineRetryJob::class, ['entryId' => $entryId]);

		// Stamp dispatchedAt on the dead-letter row so audit shows it
		// was manually handled — preserve the row for post-mortem.
		$dead['dispatchedAt'] = $nowIso;
		$deadId = $this->saveDead(objectService: $objectService, registerSlug: $registerSlug, dead: $dead, id: $id);

		return $this->requeuedResponse(deadId: $deadId, entryId: $entryId, retry: $retry);
	}//end requeueDead()

	/**
	 * Build the fresh retry entry for a dead-letter row.
	 *
	 * @param array<string, mixed> $dead   Dead-letter row.
	 * @param string               $nowIso Current UTC time as ISO-8601.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-09-async-retry/tasks.md
	 */
	private function buildRetryEntry(array $dead, string $nowIso): array {
		return [
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
	}//end buildRetryEntry()

	/**
	 * Save an object into a schema of the register.
	 *
	 * @param mixed                $objectService OR ObjectService.
	 * @param string               $registerSlug  Register slug.
	 * @param string               $schema        Schema slug.
	 * @param array<string, mixed> $object        Object to save.
	 *
	 * @return mixed saveObject() return value.
	 *
	 * @throws Throwable When OpenRegister fails to save.
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-09-async-retry/tasks.md
	 */
	private function saveTo(mixed $objectService, string $registerSlug, string $schema, array $object): mixed {
		return $objectService
			->setRegister($registerSlug)
			->setSchema($schema)
			->saveObject(
				object: $object,
				register: $registerSlug,
				schema: $schema
			);
	}//end saveTo()

	/**
	 * Save the stamped dead-letter row back onto the same row.
	 *
	 * @param mixed                $objectService OR ObjectService.
	 * @param string               $registerSlug  Register slug.
	 * @param array<string, mixed> $dead          Stamped dead-letter row.
	 * @param string               $id            Dead-letter entry id (UUID).
	 *
	 * @return string The dead-letter id.
	 *
	 * @throws Throwable When OpenRegister fails to save.
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-09-async-retry/tasks.md
	 */
	private function saveDead(mixed $objectService, string $registerSlug, array $dead, string $id): string {
		$deadId = (string)($dead['id'] ?? $id);
		if ($deadId === '') {
			$deadId = $id;
		}

		// Ensure the saveObject() target stays the same row.
		if (isset($dead['id']) === false) {
			$dead['id'] = $deadId;
		}

		$this->saveTo(objectService: $objectService, registerSlug: $registerSlug, schema: 'TimelineDeadLetter', object: $dead);

		return $deadId;
	}//end saveDead()

	/**
	 * Log the manual re-queue and build the success response.
	 *
	 * @param string               $deadId  Dead-letter id.
	 * @param string               $entryId New retry entry id.
	 * @param array<string, mixed> $retry   The retry entry that was saved.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-09-async-retry/tasks.md
	 */
	private function requeuedResponse(string $deadId, string $entryId, array $retry): JSONResponse {
		$this->logger->info(
			'TimelineDeadLetterController: dead-letter manually re-queued',
			[
				'app' => AppIdentity::APP_ID,
				'deadLetter' => $deadId,
				'retryEntry' => $entryId,
				'type' => $retry['type'],
				'externalId' => $retry['externalId'],
			]
		);

		return new JSONResponse(['success' => true, 'entryId' => $entryId, 'deadLetter' => $deadId], Http::STATUS_OK);
	}//end requeuedResponse()

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
			$record = ObjectIdentifier::findScoped(
				scoped: $objectService->setRegister($registerSlug)->setSchema('TimelineDeadLetter'),
				id: $id
			);
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
			return $this->extractObjectId(result: $result);
		}

		return null;
	}//end extractId()

	/**
	 * Extract a string id from an object saveObject() return value.
	 *
	 * Tries getUuid(), then the serialised payload, then getId().
	 *
	 * @param object $result saveObject() return value.
	 *
	 * @return string|null
	 */
	private function extractObjectId(object $result): string|null {
		$id = $this->uuidIdOf(result: $result);
		if ($id === null) {
			$id = $this->payloadIdOf(result: $result);
		}

		if ($id === null) {
			$id = $this->getIdOf(result: $result);
		}

		return $id;
	}//end extractObjectId()

	/**
	 * Non-empty getUuid() value of an object.
	 *
	 * @param object $result saveObject() return value.
	 *
	 * @return string|null
	 */
	private function uuidIdOf(object $result): string|null {
		if (method_exists($result, 'getUuid') === true) {
			$uuid = (string)$result->getUuid();
			if ($uuid !== '') {
				return $uuid;
			}
		}

		return null;
	}//end uuidIdOf()

	/**
	 * Non-empty id from an object's jsonSerialize() payload.
	 *
	 * @param object $result saveObject() return value.
	 *
	 * @return string|null
	 */
	private function payloadIdOf(object $result): string|null {
		if (method_exists($result, 'jsonSerialize') === true) {
			$payload = $result->jsonSerialize();
			if (is_array($payload) === true) {
				$id = (string)($payload['id'] ?? ($payload['@self']['id'] ?? ''));
				if ($id !== '') {
					return $id;
				}
			}
		}

		return null;
	}//end payloadIdOf()

	/**
	 * Non-empty getId() value of an object.
	 *
	 * @param object $result saveObject() return value.
	 *
	 * @return string|null
	 */
	private function getIdOf(object $result): string|null {
		if (method_exists($result, 'getId') === true) {
			$id = (string)$result->getId();
			if ($id !== '') {
				return $id;
			}
		}

		return null;
	}//end getIdOf()

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
			return $this->objectToArray(object: $object);
		}

		return null;
	}//end toArray()

	/**
	 * Normalise an OR record object into a flat array.
	 *
	 * @param object $object OR ObjectService payload.
	 *
	 * @return array<string, mixed>
	 */
	private function objectToArray(object $object): array {
		if (method_exists($object, 'jsonSerialize') === true) {
			$serialised = $object->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		$inner = $this->innerObjectOf(object: $object);
		if ($inner !== null) {
			return $inner;
		}

		return (array)$object;
	}//end objectToArray()

	/**
	 * The getObject() array of a record, with its uuid copied in as id.
	 *
	 * @param object $object OR ObjectService payload.
	 *
	 * @return array<string, mixed>|null Null when the record has no array getObject().
	 */
	private function innerObjectOf(object $object): array|null {
		if (method_exists($object, 'getObject') === false) {
			return null;
		}

		$inner = $object->getObject();
		if (is_array($inner) === false) {
			return null;
		}

		return $this->withUuidId(object: $object, inner: $inner);
	}//end innerObjectOf()

	/**
	 * Copy the record's uuid into the inner array as id when it has none.
	 *
	 * @param object               $object OR ObjectService payload.
	 * @param array<string, mixed> $inner  The record's getObject() array.
	 *
	 * @return array<string, mixed>
	 */
	private function withUuidId(object $object, array $inner): array {
		if (method_exists($object, 'getUuid') === true) {
			$uuid = (string)$object->getUuid();
			if ($uuid !== '' && isset($inner['id']) === false) {
				$inner['id'] = $uuid;
			}
		}

		return $inner;
	}//end withUuidId()
}//end class
