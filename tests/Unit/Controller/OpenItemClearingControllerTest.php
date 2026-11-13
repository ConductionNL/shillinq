<?php

/**
 * The open item endpoints check the caller's administration before they read or write.
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
 * @spec openspec/changes/ledger-open-item-clearing/specs/bookkeeping-general-ledger/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Controller\OpenItemClearingController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Ledger\OpenItemClearingRefusedException;
use OCA\Shillinq\Service\Ledger\OpenItemClearingService;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Ledger\OpenItemClearingServiceTest as Seed;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * REQ-LOIC-001..004 at the HTTP edge.
 */
#[CoversClass(OpenItemClearingController::class)]
#[UsesClass(OpenItemClearingService::class)]
#[UsesClass(OpenItemClearingRefusedException::class)]
#[UsesClass(ObjectTransitionRunner::class)]
#[UsesClass(RegisterLocation::class)]
#[UsesClass(ObjectIdentifier::class)]
final class OpenItemClearingControllerTest extends TestCase {

	/**
	 * Every write the store received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The store.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * The administration check.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService $context;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the store and the boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->saved = [];
		$this->store = new InMemoryObjectServiceStub(Seed::records(), $this->saved, true);
		$this->context = $this->createMock(AdministrationContextService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * Build the controller over the real service.
	 *
	 * @param array<string,mixed>          $params Request params.
	 * @param bool                         $signed Whether a user is signed in.
	 * @param ObjectServiceInterface|null $store  The object service, the in-memory store by default.
	 *
	 * @return OpenItemClearingController
	 */
	private function controller(array $params=[], bool $signed=true, ?ObjectServiceInterface $store=null): OpenItemClearingController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key) => $params[$key] ?? null);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bookkeeper');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signed === true ? $user : null);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $p = []): string => 'NL:' . vsprintf($text, $p));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$service = new OpenItemClearingService(
			($store ?? $this->store),
			new RegisterLocation($appConfig, $this->createMock(IAppManager::class)),
			new ObjectTransitionRunner($this->createMock(ContainerInterface::class))
		);

		return new OpenItemClearingController($request, $service, $this->context, $session, $l10n, $this->logger);

	}//end controller()

	/**
	 * Let the caller in to these administrations, with or without the right to post.
	 *
	 * @param array<int, string> $members The administrations the caller is a member of.
	 * @param bool               $post    Whether the caller may post in them.
	 *
	 * @return void
	 */
	private function member(array $members, bool $post=true): void {
		$this->context->method('canAccess')->willReturnCallback(static fn (string $administrationId): bool => in_array($administrationId, $members, true));
		$this->context->method('canPostJournalEntry')->willReturnCallback(static fn (string $administrationId): bool => $post === true && in_array($administrationId, $members, true));

	}//end member()

	/**
	 * A member sees the open items of the account.
	 *
	 * @return void
	 */
	public function testAMemberSeesTheOverview(): void {
		$this->member([Seed::ADMIN]);

		$response = $this->controller()->overview('acc-1950');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['l-out', 'l-in', 'l-cash', 'l-short'], array_column($response->getData()['uncleared'], 'id'));
		$this->assertSame([['l-out', 'l-in']], array_column($response->getData()['suggestions'], 'lineIds'));

	}//end testAMemberSeesTheOverview()

	/**
	 * A caller outside the account's administration and an unknown account both get the masked 404.
	 *
	 * @return void
	 */
	public function testAForeignOrUnknownAccountIsNotFound(): void {
		$this->context->expects($this->once())->method('canAccess')->with(Seed::ADMIN)->willReturn(false);

		$foreign = $this->controller()->overview('acc-1950');
		$unknown = $this->controller()->overview('nope');

		$this->assertSame([404, ['message' => 'NL:Not found']], [$foreign->getStatus(), $foreign->getData()]);
		$this->assertSame([404, ['message' => 'NL:Not found']], [$unknown->getStatus(), $unknown->getData()]);

	}//end testAForeignOrUnknownAccountIsNotFound()

	/**
	 * Clearing the pair as a member with posting rights saves the group with the caller as actor.
	 *
	 * @return void
	 */
	public function testAMemberClearsThePair(): void {
		$this->member([Seed::ADMIN]);

		$response = $this->controller(['lineIds' => ['l-out', 'l-in'], 'reason' => '  Spaaropdracht 0903 '])->clear();

		$this->assertSame(200, $response->getStatus());
		$group = $response->getData();
		$this->assertSame(['bookkeeper', 0, 'Spaaropdracht 0903'], [$group['clearedBy'], $group['balanceCents'], $group['reason']]);
		$this->assertSame($group['id'], $this->store->find(id: 'l-out', schema: 'GLLine')->getObject()['clearingGroupId']);

	}//end testAMemberClearsThePair()

	/**
	 * Lines of an administration the caller is not a member of are masked as absent and nothing is written.
	 *
	 * @return void
	 */
	public function testClearingForeignLinesIsNotFoundAndWritesNothing(): void {
		$this->member([Seed::OTHER_ADMIN]);

		$response = $this->controller(['lineIds' => ['l-out', 'l-in']])->clear();

		$this->assertSame([404, ['message' => 'NL:Not found']], [$response->getStatus(), $response->getData()]);
		$this->assertSame([], $this->saved);

	}//end testClearingForeignLinesIsNotFoundAndWritesNothing()

	/**
	 * A member without the right to post gets 403 and nothing is written.
	 *
	 * @return void
	 */
	public function testAMemberWithoutPostingRightsIsForbidden(): void {
		$this->member([Seed::ADMIN], false);

		$response = $this->controller(['lineIds' => ['l-out', 'l-in']])->clear();

		$this->assertSame([403, ['message' => 'NL:You may not clear lines in this administration.']], [$response->getStatus(), $response->getData()]);
		$this->assertSame([], $this->saved);

	}//end testAMemberWithoutPostingRightsIsForbidden()

	/**
	 * A refusal answers 422 with the translated message.
	 *
	 * @return void
	 */
	public function testARefusalIsTranslated(): void {
		$this->member([Seed::ADMIN]);

		$response = $this->controller(['lineIds' => ['l-cash', 'l-draft']])->clear();

		$this->assertSame([422, ['message' => 'NL:Line Kasstorting 0910 is not posted and cannot be cleared.']], [$response->getStatus(), $response->getData()]);

	}//end testARefusalIsTranslated()

	/**
	 * Lines spanning administrations are refused before any administration is checked.
	 *
	 * @return void
	 */
	public function testLinesOfTwoAdministrationsAreRefused(): void {
		$this->context->expects($this->never())->method('canAccess');

		$response = $this->controller(['lineIds' => ['l-out', 'l-foreign']])->clear();

		$this->assertSame([422, ['message' => 'NL:Lines of different administrations cannot be cleared together.']], [$response->getStatus(), $response->getData()]);

	}//end testLinesOfTwoAdministrationsAreRefused()

	/**
	 * No lineIds array, an unknown line, or no user, never reach a write.
	 *
	 * @return void
	 */
	public function testMalformedRequestsAreRefused(): void {
		$this->member([Seed::ADMIN]);

		$this->assertSame(422, $this->controller(['lineIds' => 'l-out'])->clear()->getStatus());
		$this->assertSame(404, $this->controller(['lineIds' => ['l-out', 'nope']])->clear()->getStatus());
		$this->assertSame([401, ['message' => 'NL:Not logged in']], [$this->controller(['lineIds' => ['l-out', 'l-in']], false)->clear()->getStatus(), $this->controller([], false)->clear()->getData()]);
		$this->assertSame(401, $this->controller([], false)->undo('grp-old')->getStatus());
		$this->assertSame([], $this->saved);

	}//end testMalformedRequestsAreRefused()

	/**
	 * A member undoes a clearing.
	 *
	 * @return void
	 */
	public function testAMemberUndoesAClearing(): void {
		$this->member([Seed::ADMIN]);

		$response = $this->controller()->undo('grp-old');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['groupId' => 'grp-old', 'releasedLineIds' => ['l-old-a', 'l-old-b']], $response->getData());
		$this->assertNull($this->store->find(id: 'grp-old', schema: 'ClearingGroup'));

	}//end testAMemberUndoesAClearing()

	/**
	 * A foreign or unknown group is masked as absent; a member without posting rights is forbidden.
	 *
	 * @return void
	 */
	public function testUndoChecksTheGroupsAdministration(): void {
		$this->member([Seed::OTHER_ADMIN]);

		$this->assertSame(404, $this->controller()->undo('grp-old')->getStatus());
		$this->assertSame(404, $this->controller()->undo('nope')->getStatus());
		$this->assertNotNull($this->store->find(id: 'grp-old', schema: 'ClearingGroup'));

	}//end testUndoChecksTheGroupsAdministration()

	/**
	 * A member without posting rights cannot undo.
	 *
	 * @return void
	 */
	public function testUndoWithoutPostingRightsIsForbidden(): void {
		$this->member([Seed::ADMIN], false);

		$this->assertSame(403, $this->controller()->undo('grp-old')->getStatus());
		$this->assertNotNull($this->store->find(id: 'grp-old', schema: 'ClearingGroup'));

	}//end testUndoWithoutPostingRightsIsForbidden()

	/**
	 * A failure of OpenRegister answers 500 without detail, logs it, and never passes the access check by default.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedAndAnswers500(): void {
		$broken = $this->createMock(ObjectServiceInterface::class);
		$broken->method('setRegister')->willThrowException(new RuntimeException('database down'));
		$this->context->expects($this->never())->method('canAccess');
		$this->logger->expects($this->exactly(3))->method('error')->with(
			$this->stringStartsWith('OpenItemClearingController: '),
			['exception' => 'database down']
		);

		foreach ([
			$this->controller([], true, $broken)->overview('acc-1950'),
			$this->controller(['lineIds' => ['l-out', 'l-in']], true, $broken)->clear(),
			$this->controller([], true, $broken)->undo('grp-old'),
		] as $response) {
			$this->assertSame([500, ['message' => 'NL:The open items could not be processed.']], [$response->getStatus(), $response->getData()]);
		}

	}//end testAFailureIsLoggedAndAnswers500()
}//end class
