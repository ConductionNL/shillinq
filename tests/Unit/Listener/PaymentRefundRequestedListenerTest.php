<?php

/**
 * Tests for the refund and credit commands another app sends about a request.
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Listener
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

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\Shillinq\Event\PaymentCreditRequestedEvent;
use OCA\Shillinq\Event\PaymentRefundRequestedEvent;
use OCA\Shillinq\Listener\PaymentCreditRequestedListener;
use OCA\Shillinq\Listener\PaymentRefundRequestedListener;
use OCA\Shillinq\Service\DebtorCreditService;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\ObjectRequestCommandService;
use OCA\Shillinq\Service\PaymentRevenueAccountResolver;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Refund and credit commands on the real event classes (REQ-ORC-001, REQ-ORC-003).
 */
final class PaymentRefundRequestedListenerTest extends TestCase {

	/**
	 * A request uuid as OpenRegister answers one.
	 *
	 * @var string
	 */
	private const REQUEST_ID = '0f6c2a9e-4b1d-4e8a-9c3f-7d5e2b1a0c94';

	/**
	 * The store the service reads and writes.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * The transitions the credit booking ran.
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $runs = [];

	/**
	 * The command service over one store holding one request.
	 *
	 * @param array<string, mixed> $request The stored request.
	 * @param string $creditAccount The paymentCreditAccount setting.
	 *
	 * @return ObjectRequestCommandService The service.
	 */
	private function commands(array $request, string $creditAccount = '1450'): ObjectRequestCommandService {
		$this->store = new InMemoryObjectServiceStub(data: ['PaymentRequest' => [$request]]);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($creditAccount): string {
				return match ($key) {
					'register' => 'shillinq',
					DebtorCreditService::CONFIG_KEY => $creditAccount,
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

		return new ObjectRequestCommandService(
			objectService: $this->store,
			appConfig: $appConfig,
			credits: new DebtorCreditService(
				objectService: $this->store,
				appConfig: $appConfig,
				transitions: $transitions,
				revenueAccounts: new PaymentRevenueAccountResolver(appConfig: $appConfig),
			),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end commands()

	/**
	 * Mila's settled request of 85.00 on a larpinq registration.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The request.
	 */
	private function request(array $overrides = []): array {
		return array_merge(
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
				'administrationId' => 'adm-larp-1',
				'debtor' => ['name' => 'Joris Bakker', 'email' => 'Joris@Example.nl'],
			],
			$overrides
		);
	}//end request()

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
	 * Send a refund command through the real listener.
	 *
	 * @param ObjectRequestCommandService $commands The service.
	 * @param string $sourceApp The asking app.
	 *
	 * @return PaymentRefundRequestedEvent The answered event.
	 */
	private function refund(ObjectRequestCommandService $commands, string $sourceApp = 'larpinq'): PaymentRefundRequestedEvent {
		$event = new PaymentRefundRequestedEvent(sourceApp: $sourceApp, paymentRequestId: self::REQUEST_ID, reason: 'Cancelled by the player', correlationId: 'reg-42');
		(new PaymentRefundRequestedListener(commands: $commands))->handle(event: $event);
		return $event;
	}//end refund()

	/**
	 * Larpinq asks for a refund of Mila's paid registration: the request waits
	 * for finance in refund_requested with the full amount (REQ-ORC-001).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-001)
	 */
	public function testARefundIsAcceptedForItsOwnSubject(): void {
		$event = $this->refund(commands: $this->commands(request: $this->request()));

		self::assertTrue($event->isHandled(), (string)$event->getError());
		self::assertSame(['contractVersion' => 1, 'paymentRequestId' => self::REQUEST_ID, 'state' => 'refund_requested'], $event->getResult());

		$saved = $this->savedIn(schema: 'PaymentRequest');
		self::assertCount(1, $saved);
		self::assertSame('refund_requested', $saved[0]['state']);
		self::assertSame(85.0, $saved[0]['refunds'][0]['amount']);
		self::assertSame('requested', $saved[0]['refunds'][0]['state']);
		self::assertSame('larpinq', $saved[0]['refunds'][0]['requestedBy']);
		self::assertSame([], RegisterSchema::errors(slug: 'PaymentRequest', object: $saved[0]));
	}//end testARefundIsAcceptedForItsOwnSubject()

	/**
	 * Another app cannot refund larpinq's request, an unsettled request has
	 * nothing to refund, and a request is refunded once (REQ-ORC-001).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-001)
	 */
	public function testRefusedForAnotherAppBeforeSettlementAndTwice(): void {
		$cases = [
			'another app' => [$this->request(), 'dossiq', 'does not stand on an object of dossiq'],
			'not settled' => [$this->request(['settledAt' => null, 'state' => 'pending']), 'larpinq', 'not settled'],
			'twice' => [$this->request(['state' => 'refund_requested']), 'larpinq', 'already refund requested'],
			'an invoice request' => [$this->request(['subjectKind' => 'invoice']), 'larpinq', 'on an object'],
		];

		foreach ($cases as $name => [$request, $app, $message]) {
			$event = $this->refund(commands: $this->commands(request: $request), sourceApp: $app);
			self::assertFalse($event->isHandled(), $name);
			self::assertStringContainsString($message, (string)$event->getError(), $name);
			self::assertSame([], $this->store->saved, $name . ' wrote something');
		}
	}//end testRefusedForAnotherAppBeforeSettlementAndTwice()

	/**
	 * Joris keeps Mila's payment as credit: the income moves to the customer
	 * credit account, an open credit of 85.00 exists for his email address and
	 * the request reads credited (REQ-ORC-003).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-003)
	 */
	public function testCreditIsKeyedByCustomerMasterOrEmail(): void {
		$commands = $this->commands(request: $this->request());
		$event = new PaymentCreditRequestedEvent(sourceApp: 'larpinq', paymentRequestId: self::REQUEST_ID, reason: 'Credit for a later event');
		(new PaymentCreditRequestedListener(commands: $commands))->handle(event: $event);

		self::assertTrue($event->isHandled(), (string)$event->getError());
		self::assertSame('credited', $event->getResult()['state']);

		$journal = $this->savedIn(schema: 'JournalEntry');
		self::assertCount(1, $journal);
		self::assertSame([['8050', 'debit', 85.0], ['1450', 'credit', 85.0]], array_map(static fn (array $l): array => [$l['accountNumber'], $l['side'], $l['amount']], $journal[0]['lines']));
		self::assertSame([[$journal[0]['id'], 'postDirect']], $this->runs);

		$credit = $this->savedIn(schema: 'DebtorCredit');
		self::assertCount(1, $credit);
		self::assertSame('joris@example.nl', $credit[0]['debtorKey']);
		self::assertSame(85.0, $credit[0]['remaining']);
		self::assertSame('open', $credit[0]['state']);
		self::assertSame(self::REQUEST_ID, $credit[0]['sourcePaymentRequestId']);

		$request = $this->savedIn(schema: 'PaymentRequest')[0];
		self::assertSame('credited', $request['state']);
		foreach (['JournalEntry' => $journal[0], 'DebtorCredit' => $credit[0], 'PaymentRequest' => $request] as $schema => $object) {
			self::assertSame([], RegisterSchema::errors(slug: $schema, object: $object), $schema . ' payload refused by the merged register');
		}

		self::assertSame('cm-7', DebtorCreditService::debtorKey(request: ['debtor' => ['customerMasterId' => 'cm-7', 'email' => 'joris@example.nl']]));
	}//end testCreditIsKeyedByCustomerMasterOrEmail()

	/**
	 * A debtor with neither a customer record nor an email gets no credit, and
	 * nothing is booked or written (REQ-ORC-003).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-003)
	 */
	public function testDebtorWithoutKeyGetsNoCredit(): void {
		$commands = $this->commands(request: $this->request(['debtor' => ['name' => 'Joris Bakker']]));
		$event = new PaymentCreditRequestedEvent(sourceApp: 'larpinq', paymentRequestId: self::REQUEST_ID);
		(new PaymentCreditRequestedListener(commands: $commands))->handle(event: $event);

		self::assertFalse($event->isHandled());
		self::assertSame('This debtor cannot be recognised again, so credit could never be used.', $event->getError());
		self::assertSame([], $this->store->saved);
		self::assertSame([], $this->runs);
	}//end testDebtorWithoutKeyGetsNoCredit()

	/**
	 * The app wires both listeners on their own event classes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-001)
	 */
	public function testTheAppWiresBothListeners(): void {
		$registration = (string)file_get_contents(__DIR__ . '/../../../lib/AppInfo/ObjectRequestSettlementRegistration.php');
		self::assertMatchesRegularExpression('/event: PaymentRefundRequestedEvent::class,\s+listener: PaymentRefundRequestedListener::class/', $registration);
		self::assertMatchesRegularExpression('/event: PaymentCreditRequestedEvent::class,\s+listener: PaymentCreditRequestedListener::class/', $registration);
	}//end testTheAppWiresBothListeners()
}//end class
