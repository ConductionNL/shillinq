<?php

/**
 * Unit tests for KnownCostController.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-budget-known-costs/specs/budget-known-costs/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\KnownCostController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\KnownCostBudgetWriter;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The operator-triggered regeneration: who may run it, for which
 * administration, and what comes back.
 *
 * @covers \OCA\Shillinq\Controller\KnownCostController
 */
class KnownCostControllerTest extends TestCase {

	private const SUMMARY = ['created' => 2, 'updated' => 1, 'overridden' => 1, 'skippedFiscalYears' => [2025]];

	/**
	 * The administrations the writer ran for, in order.
	 *
	 * @var list<string>
	 */
	private array $ran = [];

	/**
	 * Lines handed to the logger's error().
	 *
	 * @var list<string>
	 */
	private array $logged = [];

	/**
	 * A controller over a user who may post in adm-1 only, whose active
	 * administration is `$active`.
	 *
	 * @param string|null     $user             The signed-in user, or null.
	 * @param string          $administrationId The request parameter.
	 * @param string          $active           The active administration.
	 * @param \Throwable|null $failure          What the writer throws, if anything.
	 *
	 * @return KnownCostController
	 */
	private function controller(?string $user='ann', string $administrationId='', string $active='adm-1', ?\Throwable $failure=null): KnownCostController {
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn($user);
		$context->method('buildContext')->willReturn(['activeAdministrationId' => $active]);
		$context->method('canPostJournalEntry')->willReturnCallback(static fn (string $id): bool => $id === 'adm-1');

		$writer = $this->createMock(KnownCostBudgetWriter::class);
		$writer->method('run')->willReturnCallback(
			function (string $administrationId) use ($failure): array {
				if ($failure !== null) {
					throw $failure;
				}

				$this->ran[] = $administrationId;
				return self::SUMMARY;
			}
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnMap([['administrationId', '', $administrationId]]);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message): void {
				$this->logged[] = $message;
			}
		);

		return new KnownCostController(request: $request, context: $context, writer: $writer, logger: $logger);
	}//end controller()

	/**
	 * The active administration is regenerated when none is named, and a
	 * named one (trimmed) is regenerated when the caller may post in it.
	 *
	 * @return void
	 */
	public function testTheRunSummaryIsReturnedForAnAdministrationTheCallerPostsIn(): void {
		$response = $this->controller()->regenerate();
		self::assertSame([200, self::SUMMARY], [$response->getStatus(), $response->getData()]);

		$named = $this->controller(administrationId: ' adm-1 ', active: 'adm-2')->regenerate();
		self::assertSame(200, $named->getStatus());
		self::assertSame(['adm-1', 'adm-1'], $this->ran);
	}//end testTheRunSummaryIsReturnedForAnAdministrationTheCallerPostsIn()

	/**
	 * Without a user, for an administration the caller may not change, or
	 * with no administration at all, nothing runs and the administration's
	 * existence is not confirmed.
	 *
	 * @return void
	 */
	public function testRefusalsRunNothing(): void {
		$foreign = $this->controller(administrationId: 'adm-9')->regenerate();
		self::assertSame([404, ['error' => 'Administration not found']], [$foreign->getStatus(), $foreign->getData()]);
		self::assertSame(404, $this->controller(active: 'adm-9')->regenerate()->getStatus());
		self::assertSame(404, $this->controller(active: '')->regenerate()->getStatus());

		$anonymous = $this->controller(user: null)->regenerate();
		self::assertSame([401, ['error' => 'Not authenticated']], [$anonymous->getStatus(), $anonymous->getData()]);

		self::assertSame([], $this->ran);
	}//end testRefusalsRunNothing()

	/**
	 * A failing run answers 500 without the exception text, and logs it.
	 *
	 * @return void
	 */
	public function testAFailingRunIsA500WithAGenericMessage(): void {
		$response = $this->controller(failure: new \RuntimeException('store unreachable at host x'))->regenerate();

		self::assertSame(500, $response->getStatus());
		self::assertSame(['error' => 'Failed to regenerate the known-cost budget lines'], $response->getData());
		self::assertSame(['KnownCostController: the known-cost regeneration failed'], $this->logged);
	}//end testAFailingRunIsA500WithAGenericMessage()
}//end class
