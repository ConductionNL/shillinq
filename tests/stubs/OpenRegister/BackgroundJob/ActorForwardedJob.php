<?php

/**
 * Minimal ActorForwardedJob stub for unit tests and static analysis.
 *
 * Upstream this base class restores the acting user and organisation captured
 * at defer() time, then calls `runDeferred()`. The stub reproduces the
 * CONSTRUCTOR SHAPE and the abstract hook, not the actor restore — a unit test
 * has no session to restore, and faking one here would test the stub.
 *
 * `runDeferred()` is exposed through `runForTest()` so a test can drive the
 * hook without reflection; upstream's own `run()` is not reproduced.
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Stub for OCA\OpenRegister\BackgroundJob\ActorForwardedJob.
 */
abstract class ActorForwardedJob extends QueuedJob {

	/**
	 * Construct the job.
	 *
	 * @param ITimeFactory        $time         Time factory.
	 * @param IUserSession        $userSession  Session for actor restore.
	 * @param IUserManager        $userManager  User lookup.
	 * @param OrganisationService $organisation Organisation context.
	 * @param LoggerInterface     $logger       Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly IUserSession $userSession,
		private readonly IUserManager $userManager,
		private readonly OrganisationService $organisation,
		protected readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

	}//end __construct()

	/**
	 * Decode job arguments and delegate, as upstream does.
	 *
	 * Required concretely: OCP\BackgroundJob\Job declares `run()` abstract, so
	 * a stub base that omitted it made every subclass abstract — phpstan
	 * reports that as a non-ignorable error on the SUBCLASS, which reads as a
	 * defect in the app rather than a gap in the stub.
	 *
	 * @param mixed $argument Job arguments.
	 *
	 * @return void
	 */
	protected function run($argument): void {
		$entries = [];
		if (is_array($argument) === true && is_array(($argument['entries'] ?? null)) === true) {
			$entries = $argument['entries'];
		}

		$this->runDeferred(
			new DeferredListenerContext(
				userId: null,
				orgUuid: null,
				entries: $entries
			)
		);

	}//end run()

	/**
	 * Drive the deferred hook from a test.
	 *
	 * @param DeferredListenerContext $context Actor + entries.
	 *
	 * @return void
	 */
	public function runForTest(DeferredListenerContext $context): void {
		$this->runDeferred($context);

	}//end runForTest()

	/**
	 * Run the deferred work as the restored actor.
	 *
	 * @param DeferredListenerContext $context Actor + entries.
	 *
	 * @return void
	 */
	abstract protected function runDeferred(DeferredListenerContext $context): void;
}//end class
