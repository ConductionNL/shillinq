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
		$byListenerAll = [];
		foreach ($this->listeners as [$event, $listener]) {
			$byListener[$listener] = $event;
			$byListenerAll[$listener][] = $event;
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

		// The two outcome legs ride the OTHER app's terminal event, and each is
		// registered under EVERY namespace that app has shipped the event under.
		// Registering by FQCN string is safe even when the class is not
		// autoloadable, but it also means a wrong name cannot fail at build
		// time — it just never fires. Both spellings are pinned here because
		// dropping either one is exactly the regression this suite exists for:
		// the new name alone breaks an instance on an older release, and the
		// old name alone is what took these two listeners dark in August 2026.
		$eventsFor = static function (string $listener) use ($byListenerAll): array {
			$events = ($byListenerAll[$listener] ?? []);
			sort($events);
			return $events;
		};

		self::assertSame(
			['OCA\Decidesk\Event\DecisionConcludedEvent', 'OCA\Decidiq\Event\DecisionConcludedEvent'],
			$eventsFor(SignoffDecisionConcludedListener::class),
			'The decision outcome leg must be registered under both decidiq spellings.'
		);
		self::assertSame(
			['OCA\DocuDesk\Event\SigningConcludedEvent', 'OCA\Filinq\Event\SigningConcludedEvent'],
			$eventsFor(SigningConcludedListener::class),
			'The signing outcome leg must be registered under both filinq spellings.'
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

		// Six, not four: the two request legs ride a single OpenRegister event
		// each, while the two outcome legs are registered once per candidate
		// namespace of the app that raises them. A count of four here would
		// mean an outcome leg had been pinned back to one spelling.
		self::assertCount(
			6,
			$this->listeners,
			'Two request legs plus two outcome legs, each outcome leg under both namespace spellings.'
		);

	}//end testRequestAndOutcomeLegsAreRegisteredTogether()
}//end class
