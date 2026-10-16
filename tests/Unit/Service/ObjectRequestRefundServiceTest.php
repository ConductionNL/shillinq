<?php

/**
 * Tests for finance approving and paying a refund an app asked for.
 *
 * @category Tests
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

use InvalidArgumentException;
use OCA\Shillinq\Controller\ObjectRequestRefundController;
use OCA\Shillinq\Event\PaymentRefundRequestedEvent;
use OCA\Shillinq\Listener\PaymentRefundRequestedListener;
use OCA\Shillinq\Service\DebtorCreditService;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\ObjectRequestCommandService;
use OCA\Shillinq\Service\ObjectRequestRefundService;
use OCA\Shillinq\Service\PaymentActionAuthorizer;
use OCA\Shillinq\Service\PaymentRevenueAccountResolver;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Approve and mark-paid of a refund (REQ-ORC-002), from a request that the
 * real refund command put in refund_requested.
 */
final class ObjectRequestRefundServiceTest extends TestCase {

	/**
	 * A request uuid as OpenRegister answers one.
	 *
	 * @var string
	 */
	private const REQUEST_ID = '0f6c2a9e-4b1d-4e8a-9c3f-7d5e2b1a0c94';

	/**
	 * The store both services read and write.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * The transitions the bookings ran.
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $runs = [];

	/**
	 * App config with the refund account as given.
	 *
	 * @var IAppConfig
	 */
	private IAppConfig $appConfig;

	/**
	 * Store Mila's settled request and let larpinq ask for its refund through
	 * the real listener, so the tests start from what the command leaves.
	 *
	 * @param string $refundAccount The paymentRefundAccount setting.
	 *
	 * @return ObjectRequestRefundService The refund service over the same store.
	 */
	private function refundRequested(string $refundAccount = '1860'): ObjectRequestRefundService {
		$this->store = new InMemoryObjectServiceStub(
			data: [
				'PaymentRequest' => [
					[
						'id' => self::REQUEST_ID,
						'subjectKind' => 'object',
						'subject' => ['type' => 'registration', 'register' => 'larpinq', 'schema' => 'Registration', 'id' => 'reg-42'],
						'requestType' => 'event-fee',
						'amount' => 85.0,
						'currency' => 'EUR',
						'paymentGateway' => 'mollie',
						'state' => 'captured',
						'settledAt' => '2026-10-01T10:00:00Z',
						'settledVia' => 'provider',
						'requestedBy' => 'app:larpinq',
						'revenueAccount' => '8050',
						'paymentReference' => 'WC26-0042',
						'administrationId' => 'adm-larp-1',
						'debtor' => ['name' => 'Mila de Wit', 'email' => 'mila@example.nl'],
					],
				],
			]
		);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($refundAccount): string {
				return match ($key) {
					'register' => 'shillinq',
					ObjectRequestRefundService::CONFIG_KEY => $refundAccount,
					PaymentRevenueAccountResolver::CONFIG_KEY => '{"event-fee":"8050"}',
					default => $default,
				};
			}
		);
		$transitions = $this->createMock(ObjectTransitionRunner::class);
		$transitions->method('run')->willReturnCallback(
			function (string $objectId, string $action): void {
				$this->runs[] = [$objectId, $action];
			}
		);
		$revenue = new PaymentRevenueAccountResolver(appConfig: $this->appConfig);
		$commands = new ObjectRequestCommandService(
			objectService: $this->store,
			appConfig: $this->appConfig,
			credits: new DebtorCreditService(objectService: $this->store, appConfig: $this->appConfig, transitions: $transitions, revenueAccounts: $revenue),
			logger: $this->createMock(LoggerInterface::class),
		);
		$event = new PaymentRefundRequestedEvent(sourceApp: 'larpinq', paymentRequestId: self::REQUEST_ID, reason: 'Cancelled by the player', correlationId: 'reg-42');
		(new PaymentRefundRequestedListener(commands: $commands))->handle(event: $event);
		self::assertTrue($event->isHandled(), (string)$event->getError());
		$this->store->saved = [];

		return new ObjectRequestRefundService(
			objectService: $this->store,
			appConfig: $this->appConfig,
			transitions: $transitions,
			revenueAccounts: $revenue,
		);
	}//end refundRequested()

	/**
	 * The saves the store recorded for one schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return array<int, array<string, mixed>> The saved objects.
	 */
	private function savedIn(string $schema): array {
		return array_values(
			array_map(
				static fn (array $save): array => $save['object'],
				array_filter($this->store->saved, static fn (array $save): bool => $save['schema'] === $schema)
			)
		);
	}//end savedIn()

	/**
	 * The lines of a journal entry as [account, side, amount].
	 *
	 * @param array<string, mixed> $journal The journal entry.
	 *
	 * @return array<int, array{0: mixed, 1: mixed, 2: mixed}> The lines.
	 */
	private static function lines(array $journal): array {
		return array_map(static fn (array $l): array => [$l['accountNumber'], $l['side'], $l['amount']], $journal['lines']);
	}//end lines()

	/**
	 * A treasurer approves Mila's refund: the income is reversed to refunds
	 * payable, posted, and the refund reads approved (REQ-ORC-002).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-002)
	 */
	public function testApproveBooksTheReversal(): void {
		$refunds = $this->refundRequested();

		$request = $refunds->approve(paymentRequestId: self::REQUEST_ID);

		$journal = $this->savedIn(schema: 'JournalEntry');
		self::assertCount(1, $journal);
		self::assertSame([['8050', 'debit', 85.0], ['1860', 'credit', 85.0]], self::lines(journal: $journal[0]));
		self::assertSame('adm-larp-1', $journal[0]['administrationId']);
		self::assertSame([[$journal[0]['id'], 'postDirect']], $this->runs);

		self::assertSame('refund_requested', $request['state']);
		self::assertSame('approved', $request['refunds'][0]['state']);
		$saved = $this->savedIn(schema: 'PaymentRequest');
		self::assertCount(1, $saved);
		self::assertSame('approved', $saved[0]['refunds'][0]['state']);
		foreach (['JournalEntry' => $journal[0], 'PaymentRequest' => $saved[0]] as $schema => $object) {
			self::assertSame([], RegisterSchema::errors(slug: $schema, object: $object), $schema . ' payload refused by the merged register');
		}
	}//end testApproveBooksTheReversal()

	/**
	 * The treasurer paid 85.00 by bank and marks it paid with RF-2026-0007:
	 * refunds payable is cleared against the bank and the request reads
	 * refunded, the state larpinq reads from the object event (REQ-ORC-002).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-002)
	 */
	public function testMarkPaidMovesToRefunded(): void {
		$refunds = $this->refundRequested();
		$refunds->approve(paymentRequestId: self::REQUEST_ID);
		$this->store->saved = [];
		$this->runs = [];

		$request = $refunds->markPaid(paymentRequestId: self::REQUEST_ID, bankReference: 'RF-2026-0007', bankAccount: '1100');

		$journal = $this->savedIn(schema: 'JournalEntry');
		self::assertCount(1, $journal);
		self::assertSame([['1860', 'debit', 85.0], ['1100', 'credit', 85.0]], self::lines(journal: $journal[0]));
		self::assertSame([[$journal[0]['id'], 'postDirect']], $this->runs);

		self::assertSame('refunded', $request['state']);
		$saved = $this->savedIn(schema: 'PaymentRequest');
		self::assertCount(1, $saved);
		self::assertSame('refunded', $saved[0]['state']);
		self::assertSame('paid', $saved[0]['refunds'][0]['state']);
		self::assertSame('RF-2026-0007', $saved[0]['refunds'][0]['bankReference']);
		foreach (['JournalEntry' => $journal[0], 'PaymentRequest' => $saved[0]] as $schema => $object) {
			self::assertSame([], RegisterSchema::errors(slug: $schema, object: $object), $schema . ' payload refused by the merged register');
		}
	}//end testMarkPaidMovesToRefunded()

	/**
	 * A refund is paid only after it was approved, so the reversal is
	 * always booked before the bank payment; nothing is written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-002)
	 */
	public function testMarkPaidBeforeApprovalIsRefused(): void {
		$refunds = $this->refundRequested();

		try {
			$refunds->markPaid(paymentRequestId: self::REQUEST_ID, bankReference: 'RF-2026-0007', bankAccount: '1100');
			self::fail('A refund that was not approved was marked paid.');
		} catch (InvalidArgumentException $e) {
			self::assertStringContainsString('approved', $e->getMessage());
		}

		self::assertSame([], $this->store->saved);
		self::assertSame([], $this->runs);
	}//end testMarkPaidBeforeApprovalIsRefused()

	/**
	 * Without a refunds payable account nothing is booked and finance is told
	 * which setting to fill.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-002)
	 */
	public function testApproveWithoutARefundAccountIsRefused(): void {
		$refunds = $this->refundRequested(refundAccount: '');

		try {
			$refunds->approve(paymentRequestId: self::REQUEST_ID);
			self::fail('A refund was approved without a refunds payable account.');
		} catch (InvalidArgumentException $e) {
			self::assertStringContainsString(ObjectRequestRefundService::CONFIG_KEY, $e->getMessage());
		}

		self::assertSame([], $this->store->saved);
	}//end testApproveWithoutARefundAccountIsRefused()

	/**
	 * A request that is not waiting for a refund cannot be approved twice.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-002)
	 */
	public function testApprovingTwiceIsRefused(): void {
		$refunds = $this->refundRequested();
		$refunds->approve(paymentRequestId: self::REQUEST_ID);
		$this->store->saved = [];

		$this->expectException(InvalidArgumentException::class);
		$refunds->approve(paymentRequestId: self::REQUEST_ID);
	}//end testApprovingTwiceIsRefused()

	/**
	 * The page's two actions reach the service through the controller, and
	 * only for a user who carries payment.administer: a clerk without it is
	 * refused before anything is booked (REQ-ORC-002).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-002)
	 */
	public function testOnlyFinancePaysARefundThroughTheController(): void {
		$refunds = $this->refundRequested();

		$clerk = $this->controller(refunds: $refunds, group: 'desk');
		self::assertSame(Http::STATUS_FORBIDDEN, $clerk->approve(id: self::REQUEST_ID)->getStatus());
		self::assertSame([], $this->store->saved);

		$finance = $this->controller(refunds: $refunds, group: 'finance');
		self::assertSame(Http::STATUS_OK, $finance->approve(id: self::REQUEST_ID)->getStatus());
		$paid = $finance->markPaid(id: self::REQUEST_ID, bankReference: 'RF-2026-0007', bankAccount: '1100');
		self::assertSame(Http::STATUS_OK, $paid->getStatus());
		self::assertSame('refunded', $paid->getData()['state']);
		self::assertSame(Http::STATUS_BAD_REQUEST, $finance->approve(id: self::REQUEST_ID)->getStatus());
	}//end testOnlyFinancePaysARefundThroughTheController()

	/**
	 * The refund controller for a user in one group, with payment.administer
	 * granted to the finance group.
	 *
	 * @param ObjectRequestRefundService $refunds The service.
	 * @param string                     $group   The user's group.
	 *
	 * @return ObjectRequestRefundController The controller.
	 */
	private function controller(ObjectRequestRefundService $refunds, string $group): ObjectRequestRefundController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				PaymentActionAuthorizer::CONFIG_ACTION_GROUPS => '{"payment.administer":["finance"]}',
				default => $default,
			}
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('treasurer');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturnCallback(static fn (string $uid, string $name): bool => $name === $group);

		return new ObjectRequestRefundController(
			appName: 'shillinq',
			request: $this->createMock(IRequest::class),
			authorizer: new PaymentActionAuthorizer(appConfig: $appConfig, userSession: $session, groupManager: $groups),
			refunds: $refunds,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end controller()
}//end class
