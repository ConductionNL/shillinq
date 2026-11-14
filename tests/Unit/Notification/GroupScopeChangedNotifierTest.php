<?php

/**
 * Unit tests for GroupScopeChangedNotifier.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-3.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Notification;

use OCA\Shillinq\Notification\AppNotifier;
use OCA\Shillinq\Notification\GroupScopeChangedNotifier;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The scope-changed notification renders in the user's language.
 */
#[CoversClass(GroupScopeChangedNotifier::class)]
#[CoversClass(AppNotifier::class)]
final class GroupScopeChangedNotifierTest extends TestCase {
	/**
	 * What prepare() wrote on the notification.
	 *
	 * @var array<string,string>
	 */
	private array $parsed = [];

	/**
	 * The notifier over an l10n that substitutes %n$s and marks the text as translated.
	 *
	 * @return GroupScopeChangedNotifier
	 */
	private function notifier(): GroupScopeChangedNotifier {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(
			static fn (string $text, $parameters = []): string => '«' . vsprintf($text, (array)$parameters) . '»'
		);
		$factory = $this->createMock(IFactory::class);
		$factory->expects($this->atLeastOnce())->method('get')->with('shillinq')->willReturn($l);

		return new GroupScopeChangedNotifier(l10nFactory: $factory);
	}//end notifier()

	/**
	 * A notification with the given subject and parameters.
	 *
	 * @param string              $subject    The subject.
	 * @param array<string,mixed> $parameters The subject parameters.
	 *
	 * @return INotification
	 */
	private function notification(string $subject, array $parameters): INotification {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('shillinq');
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($parameters);
		foreach (['setParsedSubject' => 'subject', 'setParsedMessage' => 'message'] as $method => $key) {
			$notification->method($method)->willReturnCallback(
				function (string $value) use ($key, $notification): INotification {
					$this->parsed[$key] = $value;
					return $notification;
				}
			);
		}

		return $notification;
	}//end notification()

	/**
	 * A group coming into both scopes says both returns are due.
	 *
	 * @return void
	 */
	public function testAGroupInBothScopesIsToldBothApply(): void {
		$this->notifier()->prepare(
			$this->notification(
				'cbcr.scope_changed',
				['group' => 'Voorbeeld Groep Holding B.V.', 'year' => 2027, 'cbcrInScope' => true, 'pillar2InScope' => true]
			),
			'nl'
		);

		$this->assertSame(
			[
				'subject' => '«The tax scope of Voorbeeld Groep Holding B.V. changed for 2027»',
				'message' => '«A country-by-country report is due.» «The minimum tax applies.»',
			],
			$this->parsed
		);
	}//end testAGroupInBothScopesIsToldBothApply()

	/**
	 * A group leaving both scopes says neither applies.
	 *
	 * @return void
	 */
	public function testAGroupOutOfScopeIsToldNeitherApplies(): void {
		$this->notifier()->prepare(
			$this->notification('cbcr.scope_changed', ['group' => 'Klein B.V.', 'year' => 2026, 'cbcrInScope' => false, 'pillar2InScope' => false]),
			'en'
		);

		$this->assertSame('«No country-by-country report is due.» «The minimum tax does not apply.»', $this->parsed['message']);
	}//end testAGroupOutOfScopeIsToldNeitherApplies()

	/**
	 * Another subject is not this notifier's.
	 *
	 * @return void
	 */
	public function testAnotherSubjectIsRefused(): void {
		$l       = $this->createMock(IL10N::class);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);

		$this->expectException(UnknownNotificationException::class);
		(new GroupScopeChangedNotifier(l10nFactory: $factory))->prepare($this->notification('einvoice_delivery_rejected', []), 'nl');
	}//end testAnotherSubjectIsRefused()
}//end class
