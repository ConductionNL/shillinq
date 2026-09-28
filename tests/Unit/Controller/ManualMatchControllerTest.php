<?php

/**
 * The manual match endpoint masks foreign lines and translates refusals.
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
 * @spec openspec/changes/banking-manual-match/tasks.md#task-3.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\ManualMatchController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Bank\ManualMatchRefusedException;
use OCA\Shillinq\Service\Bank\ManualMatchService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-BMM-001, REQ-BMM-002 at the HTTP edge.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ManualMatchControllerTest extends TestCase {
	/**
	 * Build the controller.
	 *
	 * @param ManualMatchService  $service The service.
	 * @param bool                $member  Whether the caller may access the line's administration.
	 * @param array<string,mixed> $params  Request params.
	 *
	 * @return ManualMatchController
	 */
	private function controller(ManualMatchService $service, bool $member, array $params): ManualMatchController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key) => $params[$key] ?? null);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturn($member);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bookkeeper');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $p = []): string => 'NL:' . vsprintf($text, $p));

		return new ManualMatchController(
			request: $request,
			service: $service,
			context: $context,
			userSession: $session,
			l10n: $l10n,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end controller()

	/**
	 * A caller outside the line's administration gets 404 and nothing is matched.
	 *
	 * @return void
	 */
	public function testForeignLineIsMaskedAsNotFound(): void {
		$service = $this->createMock(ManualMatchService::class);
		$service->method('findLine')->willReturn(['id' => 'l1', 'administrationId' => 'adm-other']);
		$service->expects(self::never())->method('matchInvoices');

		$response = $this->controller($service, false, ['targets' => ['ap-1']])->match('l1');

		self::assertSame(404, $response->getStatus());

	}//end testForeignLineIsMaskedAsNotFound()

	/**
	 * A refusal answers 422 with the translated message.
	 *
	 * @return void
	 */
	public function testRefusalIsTranslated(): void {
		$service = $this->createMock(ManualMatchService::class);
		$service->method('findLine')->willReturn(['id' => 'l1', 'administrationId' => 'adm-gv']);
		$service->method('matchInvoices')->willThrowException(
			new ManualMatchRefusedException(template: 'The selection exceeds the bank line by %1$s.', parameters: ['EUR 600.00'])
		);

		$response = $this->controller($service, true, ['targets' => ['a', 'b']])->match('l1');

		self::assertSame(422, $response->getStatus());
		self::assertSame('NL:The selection exceeds the bank line by EUR 600.00.', $response->getData()['message']);

	}//end testRefusalIsTranslated()

	/**
	 * A ledger booking goes to bookToLedger with the confirming user.
	 *
	 * @return void
	 */
	public function testLedgerBookingIsRouted(): void {
		$service = $this->createMock(ManualMatchService::class);
		$service->method('findLine')->willReturn(['id' => 'l1', 'administrationId' => 'adm-gv']);
		$service->expects(self::once())->method('bookToLedger')
			->with(['id' => 'l1', 'administrationId' => 'adm-gv'], ['accountNumber' => '4910'], 'bookkeeper')
			->willReturn(['id' => 'm1', 'status' => 'confirmed']);

		$response = $this->controller($service, true, ['ledgerAccount' => ['accountNumber' => '4910']])->match('l1');

		self::assertSame(200, $response->getStatus());
		self::assertSame('m1', $response->getData()['id']);

	}//end testLedgerBookingIsRouted()
}//end class
