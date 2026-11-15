<?php

/**
 * Golden test for PaymentPlanController::lineCandidates.
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

use OCA\Shillinq\Controller\PaymentPlanController;
use OCA\Shillinq\PaymentPlan\PaymentPlanBankMatcher;
use OCA\Shillinq\PaymentPlan\PaymentPlanService;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Bank\ManualMatchService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the candidate list for a bank line, allowed and refused, and the calls behind it in order.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class PaymentPlanControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Ask for the candidates of line l1.
	 *
	 * @param array<string,mixed> $setup user (bool), line (array|null), member (bool).
	 *
	 * @return array{0:int,1:mixed}
	 */
	private function candidates(array $setup=[]): array {
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if (($setup['user'] ?? true) === true) {
			$user = $this->createMock(IUser::class);
		}

		$session->method('getUser')->willReturnCallback(
			function () use ($user): ?IUser {
				$this->calls[] = ['getUser'];
				return $user;
			}
		);
		$line = ['id' => 'l1', 'administrationId' => 'a1'];
		if (array_key_exists('line', $setup) === true) {
			$line = $setup['line'];
		}

		$lines = $this->createMock(ManualMatchService::class);
		$lines->method('findLine')->willReturnCallback(
			function (string $lineId) use ($line): array {
				$this->calls[] = ['findLine', $lineId];
				if ($line === null) {
					throw new OutOfBoundsException('gone');
				}

				return $line;
			}
		);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(
			function (string $administrationId) use ($setup): bool {
				$this->calls[] = ['canAccess', $administrationId];
				return ($setup['member'] ?? true);
			}
		);
		$matcher = $this->createMock(PaymentPlanBankMatcher::class);
		$matcher->method('candidates')->willReturnCallback(
			function (array $given): array {
				$this->calls[] = ['candidates', $given['id']];
				return [
					[
						'plan' => ['id' => 7, 'planNumber' => 'PP-1', 'customerName' => 'Acme', 'nextDueDate' => '2026-02-01', 'nextDueAmount' => 12.5, 'x' => 1],
						'confidence' => 0.9,
						'reason' => 'reference',
						'extra' => true,
					],
					['plan' => ['id' => 'p2'], 'confidence' => 'low', 'reason' => null],
				];
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text): string => 'T:' . $text);
		$controller = new PaymentPlanController(
			$this->createMock(IRequest::class),
			$this->createMock(PaymentPlanService::class),
			$matcher,
			$lines,
			$context,
			$session,
			$l10n,
			$this->createMock(LoggerInterface::class)
		);
		$response = $controller->lineCandidates('l1');
		return [$response->getStatus(), $response->getData()];
	}//end candidates()

	/**
	 * No session is a 401; an unknown line and one outside the caller's administrations a 404.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		self::assertSame(expected: [401, ['message' => 'T:Not logged in']], actual: $this->candidates(setup: ['user' => false]));
		$missing = [404, ['message' => 'T:This bank line does not exist.']];
		self::assertSame(expected: $missing, actual: $this->candidates(setup: ['line' => null]));
		self::assertSame(expected: $missing, actual: $this->candidates(setup: ['member' => false]));
		self::assertSame(expected: $missing, actual: $this->candidates(setup: ['line' => ['id' => 'l1'], 'member' => false]));
		$expected = [
			['getUser'],
			['getUser'],
			['findLine', 'l1'],
			['getUser'],
			['findLine', 'l1'],
			['canAccess', 'a1'],
			['getUser'],
			['findLine', 'l1'],
			['canAccess', ''],
		];
		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testRefusals()

	/**
	 * Each candidate is answered with its plan's id, number, customer, next due date and amount, confidence and reason.
	 *
	 * @return void
	 */
	public function testCandidates(): void {
		$first  = [
			'planId' => '7',
			'planNumber' => 'PP-1',
			'customerName' => 'Acme',
			'nextDueDate' => '2026-02-01',
			'nextDueAmount' => 12.5,
			'confidence' => 0.9,
			'reason' => 'reference',
		];
		$second = [
			'planId' => 'p2',
			'planNumber' => '',
			'customerName' => '',
			'nextDueDate' => null,
			'nextDueAmount' => null,
			'confidence' => 'low',
			'reason' => null,
		];
		self::assertSame(expected: [200, ['candidates' => [$first, $second]]], actual: $this->candidates());
		self::assertSame(expected: [['getUser'], ['findLine', 'l1'], ['canAccess', 'a1'], ['candidates', 'l1']], actual: $this->calls);
	}//end testCandidates()
}//end class
