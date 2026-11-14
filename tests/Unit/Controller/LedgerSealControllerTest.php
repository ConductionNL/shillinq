<?php

/**
 * Unit tests for LedgerSealController, the Check ledger seal action.
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
 *
 * @spec openspec/changes/ledger-sealed-entries/specs/bookkeeping-audit-trail/spec.md#requirement-a-controller-checks-the-seal-chain-req-lse-002
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\LedgerSealController;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Service\Ledger\LedgerSealService;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Only an admin or the administration's controller checks the chain; the
 * answer carries the result and the sentence the page shows.
 *
 * @covers \OCA\Shillinq\Controller\LedgerSealController
 */
class LedgerSealControllerTest extends TestCase {

	private const ADMINISTRATION = 'adm-voorbeeld';

	/**
	 * The controller over a mocked seal service and administration context.
	 *
	 * @param LedgerSealService $seals    The seal service.
	 * @param string|null       $uid      The signed-in user, or null.
	 * @param string            $role     The user's role in the administration.
	 * @param bool              $isAdmin  Whether the user is a Nextcloud admin.
	 * @param bool              $access   Whether the user may reach the administration.
	 * @param string            $adminId  The administration the request names.
	 *
	 * @return LedgerSealController
	 */
	private function controller(LedgerSealService $seals, ?string $uid = 'controller-1', string $role = 'controller', bool $isAdmin = false, bool $access = true, string $adminId = self::ADMINISTRATION): LedgerSealController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($key === 'administrationId' ? $adminId : $default));

		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$context = $this->createMock(AdministrationContextServiceInterface::class);
		$context->method('canAccess')->willReturnCallback(static fn (string $administrationId): bool => $access && $administrationId === self::ADMINISTRATION);
		$context->method('buildContext')->willReturn([
			'administrations' => [['administrationId' => self::ADMINISTRATION, 'role' => $role]],
		]);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($isAdmin);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		return new LedgerSealController($request, $seals, $context, $session, $groups, $l10n, $this->createMock(LoggerInterface::class));
	}//end controller()

	/**
	 * A seal service answering the given result for the administration, expecting $calls calls.
	 *
	 * @param array<string, mixed> $result The verify() result.
	 * @param int                  $calls  How often verify() may run.
	 *
	 * @return LedgerSealService
	 */
	private function seals(array $result, int $calls = 1): LedgerSealService {
		$seals = $this->createMock(LedgerSealService::class);
		$seals->expects($this->exactly($calls))->method('verify')->with(self::ADMINISTRATION)->willReturn($result);
		return $seals;
	}//end seals()

	public function testAControllerSeesTheChainIntactOverItsEntries(): void {
		$response = $this->controller(seals: $this->seals(['intact' => true, 'entries' => 3, 'transactionNumber' => null, 'transactionId' => null, 'reason' => null]))->verify();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			['intact' => true, 'entries' => 3, 'transactionNumber' => null, 'transactionId' => null, 'reason' => null, 'message' => 'The ledger seal chain is intact over 3 entries.'],
			$response->getData()
		);
	}//end testAControllerSeesTheChainIntactOverItsEntries()

	public function testAChangedTransactionIsNamed(): void {
		$response = $this->controller(seals: $this->seals(['intact' => false, 'entries' => 3, 'transactionNumber' => '2026-0902', 'transactionId' => 'gl-2', 'reason' => 'content']))->verify();

		$this->assertFalse($response->getData()['intact']);
		$this->assertSame('content', $response->getData()['reason']);
		$this->assertSame('Transaction 2026-0902 is the first entry that no longer matches its seal.', $response->getData()['message']);
	}//end testAChangedTransactionIsNamed()

	public function testAnAdminWithoutTheRoleMayCheck(): void {
		$response = $this->controller(seals: $this->seals(['intact' => true, 'entries' => 0]), role: 'inkijker', isAdmin: true)->verify();

		$this->assertSame(200, $response->getStatus());
	}//end testAnAdminWithoutTheRoleMayCheck()

	public function testABookkeeperIsRefused(): void {
		$response = $this->controller(seals: $this->seals([], calls: 0), role: 'boekhouder')->verify();

		$this->assertSame(403, $response->getStatus());
		$this->assertSame('Only a controller can check the ledger seal.', $response->getData()['message']);
	}//end testABookkeeperIsRefused()

	public function testAnAdministrationOutOfReachIsNotFound(): void {
		$this->assertSame(404, $this->controller(seals: $this->seals([], calls: 0), access: false)->verify()->getStatus());
		$this->assertSame(404, $this->controller(seals: $this->seals([], calls: 0), adminId: '')->verify()->getStatus());
	}//end testAnAdministrationOutOfReachIsNotFound()

	public function testAnAnonymousCallerIsRefused(): void {
		$this->assertSame(401, $this->controller(seals: $this->seals([], calls: 0), uid: null)->verify()->getStatus());
	}//end testAnAnonymousCallerIsRefused()

	public function testAFailingCheckAnswersAServerError(): void {
		$seals = $this->createMock(LedgerSealService::class);
		$seals->method('verify')->willThrowException(new RuntimeException('store down'));

		$response = $this->controller(seals: $seals)->verify();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame('The ledger seal could not be checked.', $response->getData()['message']);
	}//end testAFailingCheckAnswersAServerError()
}//end class
