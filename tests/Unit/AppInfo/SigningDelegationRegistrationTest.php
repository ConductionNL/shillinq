<?php

/**
 * Unit tests for SigningDelegationRegistration.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\AppInfo;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\AppInfo\SigningDelegationRegistration;
use OCA\Shillinq\Listener\ACMReportSignTransitionListener;
use OCA\Shillinq\Listener\AnnualReportSignoffRequestListener;
use OCA\Shillinq\Listener\SigningConcludedListener;
use OCA\Shillinq\Listener\SignoffDecisionConcludedListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * The signing / delegation listeners must stay registered as PAIRS.
 *
 * Each capability has a REQUEST leg and an OUTCOME leg, in different apps:
 * decidesk decides, docudesk signs, and shillinq never does either on local
 * authority. Registering a request leg without its outcome leg is silent and
 * costly — the signature or decision is asked for, the external app answers,
 * and nothing projects the answer back onto the finance object. That is the
 * orphaned-capability defect these registrations were added to close, so the
 * pairing is what this test pins rather than a bare count.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SigningDelegationRegistrationTest extends TestCase {
	/**
	 * Captured [event, listener] pairs.
	 *
	 * @var array<int, array{0:string,1:string}>
	 */
	private array $listeners = [];

	/**
	 * A registration context that records registerEventListener() calls.
	 *
	 * @return IRegistrationContext The recording context.
	 */
	private function recordingContext(): IRegistrationContext {
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			function (string $event, string $listener, int $priority = 0): void {
				$this->listeners[] = [$event, $listener];
			}
		);

		return $context;
	}//end recordingContext()

	/**
	 * Every listener registered maps to the event that actually carries it.
	 *
	 * @return void
	 */
	public function testEachListenerIsBoundToItsOwnEvent(): void {
		(new SigningDelegationRegistration())->register($this->recordingContext());

		$byListener = [];
		foreach ($this->listeners as [$event, $listener]) {
			$byListener[$listener] = $event;
		}

		// The two shillinq-side request legs ride OpenRegister transitions.
		self::assertSame(
			ObjectTransitionedEvent::class,
			($byListener[AnnualReportSignoffRequestListener::class] ?? null)
		);
		self::assertSame(
			ObjectTransitionedEvent::class,
			($byListener[ACMReportSignTransitionListener::class] ?? null)
		);

		// The two outcome legs ride the OTHER app's terminal event. These are
		// registered by FQCN string even when the class is not autoloadable,
		// which is safe — but it also means a typo cannot fail at build time,
		// so the exact keys are pinned here.
		self::assertSame(
			'OCA\Decidesk\Event\DecisionConcludedEvent',
			($byListener[SignoffDecisionConcludedListener::class] ?? null)
		);
		self::assertSame(
			'OCA\DocuDesk\Event\SigningConcludedEvent',
			($byListener[SigningConcludedListener::class] ?? null)
		);

	}//end testEachListenerIsBoundToItsOwnEvent()

	/**
	 * Neither capability may be registered request-only.
	 *
	 * @return void
	 */
	public function testRequestAndOutcomeLegsAreRegisteredTogether(): void {
		(new SigningDelegationRegistration())->register($this->recordingContext());

		$registered = array_column($this->listeners, 1);

		foreach (
			[
				'governance decision' => [
					AnnualReportSignoffRequestListener::class,
					SignoffDecisionConcludedListener::class,
				],
				'document signature' => [
					ACMReportSignTransitionListener::class,
					SigningConcludedListener::class,
				],
			] as $capability => $pair
		) {
			[$request, $outcome] = $pair;

			self::assertContains($request, $registered, $capability . ': request leg missing.');
			self::assertContains(
				$outcome,
				$registered,
				$capability . ': the request leg is registered but the OUTCOME leg is not — the answer is '
				. 'never projected back onto the finance object.'
			);
		}

		self::assertCount(4, $this->listeners, 'Exactly the two request+outcome pairs.');

	}//end testRequestAndOutcomeLegsAreRegisteredTogether()
}//end class
