<?php

/**
 * TenderNed Award Promotion Job.
 *
 * Runs the award -> Commitment promotion that
 * `TenderNedAwardDetectedListener` used to do synchronously, inside the
 * OpenRegister write that raised the event.
 *
 * ADR-078: work hung off a post-`*ed` event is async by default. The
 * promotion writes two objects (a new Commitment and a status bump on the
 * TenderNedProcurement dossier) and emits a cross-app CloudEvent, so
 * leaving it on the caller's write path made every openconnector polling
 * write pay for it — and made a slow promotion look like a slow dossier
 * save. See shillinq#1198.
 *
 * `ActorForwardedJob` is what makes the move safe rather than merely
 * later: it restores the acting user and organisation before calling
 * `runDeferred()`, so the promotion still writes as the user whose
 * import triggered it. A plain QueuedJob would run with no session, and
 * OpenRegister would attribute the Commitment to nobody.
 *
 * The job deliberately does NOT re-implement the promotion. It resolves
 * the listener and calls back into it, so the eligibility rules, the
 * idempotency check and the fail-soft logging stay in one place — the
 * inline and deferred paths cannot drift apart.
 *
 * @category BackgroundJob
 * @package  OCA\Shillinq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-tenderned-integratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\ActorForwardedJob;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\Shillinq\Listener\TenderNedAwardDetectedListener;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Deferred runner for the TenderNed award -> Commitment promotion.
 *
 * @spec openspec/specs/bookkeeping-tenderned-integratie/spec.md
 */
class TenderNedAwardPromotionJob extends ActorForwardedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory        $time         Time factory for QueuedJob.
	 * @param IUserSession        $userSession  Session used to restore the actor.
	 * @param IUserManager        $userManager  User lookup for actor restore.
	 * @param OrganisationService $organisation Organisation context restore.
	 * @param LoggerInterface     $logger       Logger.
	 * @param ContainerInterface  $container    Container, to resolve the listener.
	 */
	public function __construct(
		ITimeFactory $time,
		IUserSession $userSession,
		IUserManager $userManager,
		OrganisationService $organisation,
		LoggerInterface $logger,
		private readonly ContainerInterface $container,
	) {
		parent::__construct(
			time: $time,
			userSession: $userSession,
			userManager: $userManager,
			organisation: $organisation,
			logger: $logger
		);

	}//end __construct()

	/**
	 * Run every buffered award entry as the restored actor.
	 *
	 * @param DeferredListenerContext $context Actor + buffered entries.
	 *
	 * @return void
	 */
	protected function runDeferred(DeferredListenerContext $context): void {
		$listener = $this->resolveListener();
		if ($listener === null) {
			return;
		}

		// The getEntries() return is declared `array<int, array<string, mixed>>`
		// upstream, so an is_array() guard per entry would be dead code that
		// phpstan reports as an always-false comparison.
		foreach ($context->getEntries() as $entry) {
			$this->runEntry(listener: $listener, entry: $entry);
		}

	}//end runDeferred()

	/**
	 * Resolve the listener that owns the promotion logic.
	 *
	 * @return TenderNedAwardDetectedListener|null Null when unresolvable.
	 */
	private function resolveListener(): ?TenderNedAwardDetectedListener {
		try {
			$listener = $this->container->get(TenderNedAwardDetectedListener::class);
		} catch (Throwable $e) {
			$this->logger->warning(
				'TenderNedAwardPromotionJob: listener could not be resolved — dropping batch',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		if (($listener instanceof TenderNedAwardDetectedListener) === false) {
			return null;
		}

		return $listener;

	}//end resolveListener()

	/**
	 * Run one buffered entry, fail-soft.
	 *
	 * @param TenderNedAwardDetectedListener $listener Promotion owner.
	 * @param array<string, mixed>           $entry    Buffered payload.
	 *
	 * @return void
	 */
	private function runEntry(TenderNedAwardDetectedListener $listener, array $entry): void {
		$payload = ($entry['payload'] ?? null);
		if (is_array($payload) === false) {
			$this->logger->warning(
				'TenderNedAwardPromotionJob: buffered entry carries no payload — skipped',
				['keys' => array_keys($entry)]
			);
			return;
		}

		try {
			$listener->runDeferredPromotion(payload: $payload);
		} catch (Throwable $e) {
			// Same blast radius the inline handler had: logged and dropped,
			// never rethrown into cron. The next polling tick re-attempts.
			$this->logger->warning(
				'TenderNedAwardPromotionJob: promotion failed — fail-soft',
				[
					'tenderId'  => (string)($payload['tenderId'] ?? ''),
					'exception' => $e->getMessage(),
				]
			);
		}//end try

	}//end runEntry()
}//end class
