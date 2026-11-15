<?php

/**
 * Minimal ListenerDeferralService stub for unit tests.
 *
 * Mirrors the constructor and the two methods shillinq calls on OpenRegister's
 * real service (`isDeferralEnabled()` and `defer()`) on openregister main. A
 * test subclasses it to decide whether deferral is enabled and to record what
 * was deferred.
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Deferral;

use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCP\IUserSession;
use RuntimeException;

/**
 * Stub for OCA\OpenRegister\Service\Deferral\ListenerDeferralService.
 */
class ListenerDeferralService {

	/**
	 * Default number of entries per queued job.
	 *
	 * @var integer
	 */
	public const DEFAULT_CHUNK_SIZE = 100;

	/**
	 * Constructor (real signature).
	 *
	 * @param IUserSession                 $userSession   The session.
	 * @param OrganisationServiceInterface $organisation  The organisation service.
	 * @param DeferralQueueServices        $queue         The job queue services.
	 * @param int|null                     $argumentLimit The job argument size limit.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly OrganisationServiceInterface $organisation,
		private readonly DeferralQueueServices $queue,
		private readonly ?int $argumentLimit = null,
	) {

	}//end __construct()

	/**
	 * Whether listeners defer their work (real signature).
	 *
	 * @return bool
	 *
	 * @throws RuntimeException Always in the stub: a test overrides this method.
	 */
	public function isDeferralEnabled(): bool {
		throw new RuntimeException('ListenerDeferralService stub: override isDeferralEnabled() in the test.');

	}//end isDeferralEnabled()

	/**
	 * Queue one entry for a background job (real signature).
	 *
	 * @param string               $jobClass  The job class.
	 * @param array<string, mixed> $entry     The entry.
	 * @param int                  $chunkSize Entries per job.
	 * @param string|null          $dedupeKey Key that collapses duplicates.
	 *
	 * @return void
	 *
	 * @throws RuntimeException Always in the stub: a test overrides this method.
	 */
	public function defer(
		string $jobClass,
		array $entry,
		int $chunkSize = self::DEFAULT_CHUNK_SIZE,
		?string $dedupeKey = null,
	): void {
		throw new RuntimeException('ListenerDeferralService stub: override defer() in the test.');

	}//end defer()
}//end class
