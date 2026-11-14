<?php

/**
 * Unit tests for PaymentActionAuthorizer.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\PaymentActionAuthorizer;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Pins every allowed and refused outcome of may() and callerId().
 */
final class PaymentActionAuthorizerTest extends TestCase {

	/**
	 * Group checks and config reads, in order.
	 *
	 * @var array<int,mixed>
	 */
	private array $events = [];

	/**
	 * Build the authorizer.
	 *
	 * @param string|null        $uid    The session user, or null for none.
	 * @param boolean            $admin  Whether that user is an admin.
	 * @param string             $matrix The configured action matrix (JSON).
	 * @param array<int,string>  $groups The user's groups.
	 *
	 * @return PaymentActionAuthorizer
	 */
	private function authorizer(?string $uid, bool $admin, string $matrix, array $groups): PaymentActionAuthorizer {
		$events = &$this->events;
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(
			function (string $id) use ($admin, &$events): bool {
				$events[] = ['isAdmin', $id];
				return $admin;
			}
		);
		$groupManager->method('isInGroup')->willReturnCallback(
			function (string $id, string $group) use ($groups, &$events): bool {
				$events[] = ['isInGroup', $id, $group];
				return in_array($group, $groups, true);
			}
		);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default) use ($matrix, &$events): string {
				$events[] = ['config', $app, $key, $default];
				return $matrix;
			}
		);

		return new PaymentActionAuthorizer($config, $session, $groupManager);

	}//end authorizer()

	/**
	 * Pin every allowed and refused outcome and the checks made for it.
	 *
	 * @return void
	 */
	public function testEveryOutcomeAndTheChecksBehindIt(): void {
		$matrix = json_encode(
			[
				'payment.request' => ['clerks', 7, 'finance'],
				'payment.administer' => [],
				'payment.void' => 'finance',
			]
		);
		$read = ['config', 'shillinq', 'paymentActionGroups', ''];
		$cases = [
			'noSession' => [[null, true, $matrix, ['finance']], 'payment.request', false, []],
			'admin' => [['u', true, '', []], 'payment.request', true, [['isAdmin', 'u']]],
			'badMatrix' => [['u', false, '{nope', ['finance']], 'payment.request', false, [['isAdmin', 'u'], $read]],
			'scalarMatrix' => [['u', false, '"x"', ['finance']], 'payment.request', false, [['isAdmin', 'u'], $read]],
			'unknownAction' => [['u', false, $matrix, ['finance']], 'payment.refund', false, [['isAdmin', 'u'], $read]],
			'emptyList' => [['u', false, $matrix, ['finance']], 'payment.administer', false, [['isAdmin', 'u'], $read]],
			'scalarList' => [['u', false, $matrix, ['finance']], 'payment.void', false, [['isAdmin', 'u'], $read]],
			'secondGroup' => [
				['u', false, $matrix, ['finance']],
				'payment.request',
				true,
				[['isAdmin', 'u'], $read, ['isInGroup', 'u', 'clerks'], ['isInGroup', 'u', 'finance']],
			],
			'firstGroup' => [['u', false, $matrix, ['clerks']], 'payment.request', true, [['isAdmin', 'u'], $read, ['isInGroup', 'u', 'clerks']]],
			'noGroup' => [
				['u', false, $matrix, ['other']],
				'payment.request',
				false,
				[['isAdmin', 'u'], $read, ['isInGroup', 'u', 'clerks'], ['isInGroup', 'u', 'finance']],
			],
		];
		foreach ($cases as $label => [$setup, $action, $allowed, $checks]) {
			$this->events = [];
			self::assertSame($allowed, $this->authorizer(...$setup)->may($action), $label);
			self::assertSame($checks, $this->events, $label);
		}

		self::assertSame('', $this->authorizer(null, false, '', [])->callerId());
		self::assertSame('u', $this->authorizer('u', false, '', [])->callerId());

	}//end testEveryOutcomeAndTheChecksBehindIt()
}//end class
