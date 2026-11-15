<?php

/**
 * Golden test for the CommitmentYearEndController carry-over checks.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
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

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\CommitmentYearEndController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Commitment\CommitmentCarryOverService;
use OCA\Shillinq\Service\Commitment\CommitmentInvoicing;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins who may preview a carry-over, and every read and check behind the answer in order.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class CommitmentYearEndControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Preview the carry-over of administration a1.
	 *
	 * @param array<int,mixed> $administrations The caller's administrations in the context.
	 * @param bool             $loggedIn        Whether a user is logged in.
	 *
	 * @return array{0:int,1:mixed}
	 */
	private function preview(array $administrations, bool $loggedIn=true): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			function (string $key, mixed $default=null): mixed {
				$this->calls[] = ['getParam', $key];
				return ['administrationId' => ' a1 ', 'fromYear' => '2026'][$key] ?? $default;
			}
		);
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($loggedIn === true) {
			$user = $this->createMock(IUser::class);
		}

		$session->method('getUser')->willReturn($user);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(
			function (string $administrationId): bool {
				$this->calls[] = ['canAccess', $administrationId];
				return true;
			}
		);
		$context->method('buildContext')->willReturnCallback(
			function () use ($administrations): array {
				$this->calls[] = ['buildContext'];
				return ['administrations' => $administrations];
			}
		);
		$carryOver = $this->createMock(CommitmentCarryOverService::class);
		$carryOver->method('preview')->willReturnCallback(
			function (string $administrationId, int $fromYear): array {
				$this->calls[] = ['preview', $administrationId, $fromYear];
				return ['lines' => []];
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text): string => 'T:' . $text);
		$controller = new CommitmentYearEndController(
			$request,
			$carryOver,
			$this->createMock(CommitmentInvoicing::class),
			$context,
			$session,
			$l10n,
			$this->createMock(LoggerInterface::class)
		);
		$response = $controller->previewCarryOver();
		return [$response->getStatus(), $response->getData()];
	}//end preview()

	/**
	 * Only a controller or owner of the administration passes; the last matching entry's role wins.
	 *
	 * @return void
	 */
	public function testRoles(): void {
		$forbidden = [403, ['message' => 'T:Only a controller can carry commitments over.']];
		$cases     = [
			[[['administrationId' => 'a1', 'role' => 'controller']], [200, ['lines' => []]]],
			[[['administrationId' => 'a1', 'role' => 'eigenaar']], [200, ['lines' => []]]],
			[[['administrationId' => 'a1', 'role' => 'boekhouder']], $forbidden],
			[[['administrationId' => 'a2', 'role' => 'controller']], $forbidden],
			[[['administrationId' => 'a1', 'role' => 'controller'], ['administrationId' => 'a1', 'role' => 'lezer']], $forbidden],
			[[['administrationId' => 'a1', 'role' => 'lezer'], ['administrationId' => 'a1', 'role' => 'Controller']], $forbidden],
			[[['administrationId' => 'a1']], $forbidden],
			[[], $forbidden],
		];
		foreach ($cases as [$administrations, $answer]) {
			self::assertSame(expected: $answer, actual: $this->preview(administrations: $administrations));
		}

		self::assertSame(expected: [401, ['message' => 'T:Not logged in']], actual: $this->preview(administrations: [], loggedIn: false));
	}//end testRoles()

	/**
	 * The administration parameter is read once per listed administration while looking for the role.
	 *
	 * @return void
	 */
	public function testReads(): void {
		$this->preview(administrations: [['administrationId' => 'a2', 'role' => 'controller'], ['administrationId' => 'a1', 'role' => 'controller']]);
		$expected = [
			['getParam', 'administrationId'],
			['canAccess', 'a1'],
			['buildContext'],
			['getParam', 'administrationId'],
			['getParam', 'administrationId'],
			['getParam', 'administrationId'],
			['getParam', 'fromYear'],
			['preview', 'a1', 2026],
		];
		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testReads()
}//end class
