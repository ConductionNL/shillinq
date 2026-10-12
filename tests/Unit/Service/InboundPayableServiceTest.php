<?php

/**
 * Tests for a payable another app raises: raise, approve, pay, reject, callback.
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
use OCA\Shillinq\AppInfo\ObjectRequestSettlementRegistration;
use OCA\Shillinq\Controller\InboundPayableController;
use OCA\Shillinq\Event\PayableRaisedEvent;
use OCA\Shillinq\Listener\PayableRaisedListener;
use OCA\Shillinq\Service\InboundPayableCallbackSender;
use OCA\Shillinq\Service\InboundPayableService;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\PaymentActionAuthorizer;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\App\IAppManager;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A case app owes a citizen money and asks shillinq to pay it out
 * (inbound-payable-from-another-app, REQ-IPY-001 to REQ-IPY-004).
 */
final class InboundPayableServiceTest extends TestCase {

	/**
	 * A valid Dutch IBAN.
	 * @var string
	 */
	private const IBAN = 'NL91ABNA0417164300';

	/**
	 * The store the service reads and writes.
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * The transitions the bookings ran.
	 * @var list<array{0: string, 1: string}>
	 */
	private array $runs = [];

	/**
	 * The callbacks posted, as [url, options].
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $posts = [];

	/**
	 * Build the service over an empty store.
	 * @param array<string, string> $config      App config values by key.
	 * @param list<string>          $installed   Installed apps.
	 * @return InboundPayableService The service.
	 */
	private function service(array $config = [], array $installed = ['dossiq']): InboundPayableService {
		$this->store = new InMemoryObjectServiceStub(data: ['InboundPayable' => []]);
		$config += [
			'register' => 'shillinq',
			InboundPayableService::CONFIG_PAYABLE_ACCOUNT => '1610',
			InboundPayableService::CONFIG_EXPENSE_ACCOUNTS => '{"dwangsom":"4390","dossiq":"4999"}',
			InboundPayableCallbackSender::CONFIG_SECRETS => '{"dossiq":"s3cret"}',
		];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(static fn (string $id): bool => in_array($id, $installed, true));
		$transitions = $this->createMock(ObjectTransitionRunner::class);
		$transitions->method('run')->willReturnCallback(
			function (string $objectId, string $action): void {
				$this->runs[] = [$objectId, $action];
			}
		);
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $url, array $options) {
				$this->posts[] = [$url, $options];
				return $this->createMock(\OCP\Http\Client\IResponse::class);
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		return new InboundPayableService(
			objectService: $this->store,
			appConfig: $appConfig,
			appManager: $apps,
			transitions: $transitions,
			callbacks: new InboundPayableCallbackSender(clientService: $clients, appConfig: $appConfig, logger: $this->createMock(LoggerInterface::class)),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end service()

	/**
	 * Dossiq's payout of an Awb 4:17 penalty to the applicant.
	 * @param array<string, mixed> $override Fields to change.
	 * @return PayableRaisedEvent The command.
	 */
	private static function raised(array $override = []): PayableRaisedEvent {
		$args = $override + [
			'sourceApp' => 'dossiq',
			'amount' => 1442.0,
			'payeeName' => 'J. Jansen',
			'payeeIban' => 'nl91 abna 0417 1643 00',
			'reference' => 'DWS-2026-0042',
			'description' => 'Dwangsom niet tijdig beslissen, zaak Z-2026-17',
			'callbackUrl' => 'https://dossiq.example/api/payable-callback',
			'category' => 'dwangsom',
			'correlationId' => 'calc-17',
			'administrationId' => 'adm-1',
		];
		return new PayableRaisedEvent(...$args);
	}//end raised()

	/**
	 * Raise through the real listener and return the answer.
	 * @param InboundPayableService $service The service.
	 * @param PayableRaisedEvent    $event   The command.
	 * @return PayableRaisedEvent The answered command.
	 */
	private static function dispatch(InboundPayableService $service, PayableRaisedEvent $event): PayableRaisedEvent {
		(new PayableRaisedListener(payables: $service))->handle(event: $event);
		return $event;
	}//end dispatch()

	/**
	 * The saves the store recorded for one schema.
	 * @param string $schema The schema.
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
	 * Dossiq raises a payout: it is stored as requested with the payee, the
	 * reference and the callback, and the answer names the new payable (REQ-IPY-001).
	 * @return void
	 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-001)
	 */
	public function testAnAppRaisesAPayable(): void {
		$service = $this->service();

		$event = self::dispatch(service: $service, event: self::raised());

		self::assertTrue($event->isHandled(), (string)$event->getError());
		$saved = $this->savedIn(schema: 'InboundPayable');
		self::assertCount(1, $saved);
		self::assertSame('requested', $saved[0]['state']);
		self::assertSame(1442.0, $saved[0]['amount']);
		self::assertSame(['name' => 'J. Jansen', 'iban' => self::IBAN], $saved[0]['payee']);
		self::assertSame('dossiq', $saved[0]['sourceApp']);
		self::assertSame('DWS-2026-0042', $saved[0]['reference']);
		self::assertSame('https://dossiq.example/api/payable-callback', $saved[0]['callbackUrl']);
		self::assertSame(
			['contractVersion' => 1, 'payableId' => $saved[0]['id'], 'state' => 'requested'],
			$event->getResult()
		);
		self::assertSame([], RegisterSchema::errors(slug: 'InboundPayable', object: $saved[0]));
		self::assertSame([], $this->posts, 'raising does not call back');
	}//end testAnAppRaisesAPayable()

	/**
	 * A command with a bad amount, IBAN, payee, reference or callback, or from
	 * an app that is not installed, is refused and writes nothing (REQ-IPY-001).
	 * @return void
	 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-001)
	 */
	public function testABadCommandIsRefusedAndWritesNothing(): void {
		$cases = [
			'amount' => ['amount' => 0.0],
			'iban' => ['payeeIban' => 'NL91ABNA0417164301'],
			'payee' => ['payeeName' => '  '],
			'reference' => ['reference' => 'X1'],
			'callback' => ['callbackUrl' => 'ftp://dossiq.example/cb'],
			'app' => ['sourceApp' => 'ghostapp'],
		];
		foreach ($cases as $label => $override) {
			$service = $this->service();
			$event = self::dispatch(service: $service, event: self::raised(override: $override));
			self::assertFalse($event->isHandled(), $label . ' was accepted');
			self::assertNotSame('', (string)$event->getError(), $label);
			self::assertSame([], $this->store->saved, $label . ' wrote something');
		}
	}//end testABadCommandIsRefusedAndWritesNothing()

	/**
	 * The same reference raised twice with the same amount and payee answers the
	 * first payable (a retry); with another amount it is refused (REQ-IPY-001).
	 * @return void
	 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-001)
	 */
	public function testARetryAnswersTheFirstPayableAndAClashIsRefused(): void {
		$service = $this->service();
		$first = self::dispatch(service: $service, event: self::raised());
		$retry = self::dispatch(service: $service, event: self::raised());
		self::assertSame($first->getResult(), $retry->getResult());
		self::assertCount(1, $this->savedIn(schema: 'InboundPayable'));

		$clash = self::dispatch(service: $service, event: self::raised(override: ['amount' => 99.0]));
		self::assertFalse($clash->isHandled());
		self::assertStringContainsString('DWS-2026-0042', (string)$clash->getError());
	}//end testARetryAnswersTheFirstPayableAndAClashIsRefused()

	/**
	 * Finance approves: the expense of the category is booked against the
	 * payables account, and the app hears `approved` on its signed callback (REQ-IPY-002, REQ-IPY-004).
	 * @return void
	 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-002)
	 */
	public function testApproveBooksTheExpenseAndCallsBack(): void {
		$service = $this->service();
		$id = (string)self::dispatch(service: $service, event: self::raised())->getResult()['payableId'];
		$this->store->saved = [];

		$payable = $service->approve(payableId: $id);

		$journal = $this->savedIn(schema: 'JournalEntry');
		self::assertCount(1, $journal);
		$lines = array_map(static fn (array $l): array => [$l['accountNumber'], $l['side'], $l['amount']], $journal[0]['lines']);
		self::assertSame([['4390', 'debit', 1442.0], ['1610', 'credit', 1442.0]], $lines);
		self::assertSame('adm-1', $journal[0]['administrationId']);
		self::assertSame([[$journal[0]['id'], 'postDirect']], $this->runs);
		self::assertSame('approved', $payable['state']);

		self::assertCount(1, $this->posts);
		[$url, $options] = $this->posts[0];
		self::assertSame('https://dossiq.example/api/payable-callback', $url);
		$body = (string)$options['body'];
		self::assertSame(hash_hmac('sha256', $body, 's3cret'), $options['headers']['X-Shillinq-Signature']);
		$decoded = json_decode($body, true);
		self::assertSame('approved', $decoded['status']);
		self::assertSame('DWS-2026-0042', $decoded['reference']);
		self::assertSame('calc-17', $decoded['correlationId']);

		$saved = $this->savedIn(schema: 'InboundPayable');
		$last = $saved[count($saved) - 1];
		self::assertNotSame('', (string)($last['callbackDeliveredAt'] ?? ''));
		foreach (['JournalEntry' => $journal[0], 'InboundPayable' => $last] as $schema => $object) {
			self::assertSame([], RegisterSchema::errors(slug: $schema, object: $object), $schema . ' payload refused by the merged register');
		}
	}//end testApproveBooksTheExpenseAndCallsBack()

	/**
	 * Marking it paid clears the payables account against the bank, records the
	 * bank reference and the date, and calls back `paid` (REQ-IPY-003).
	 * @return void
	 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-003)
	 */
	public function testMarkPaidClearsAgainstTheBankAndCallsBack(): void {
		$service = $this->service();
		$id = (string)self::dispatch(service: $service, event: self::raised())->getResult()['payableId'];
		$service->approve(payableId: $id);
		$this->store->saved = [];
		$this->posts = [];
		$this->runs = [];

		$payable = $service->markPaid(payableId: $id, bankReference: 'SEPA-777', bankAccount: '1100', paidOn: '2026-10-20');

		$journal = $this->savedIn(schema: 'JournalEntry');
		$lines = array_map(static fn (array $l): array => [$l['accountNumber'], $l['side'], $l['amount']], $journal[0]['lines']);
		self::assertSame([['1610', 'debit', 1442.0], ['1100', 'credit', 1442.0]], $lines);
		self::assertSame('paid', $payable['state']);
		self::assertSame('SEPA-777', $payable['bankReference']);
		self::assertSame('2026-10-20', $payable['paidOn']);
		$decoded = json_decode((string)$this->posts[0][1]['body'], true);
		self::assertSame(['paid', '2026-10-20', 'SEPA-777'], [$decoded['status'], $decoded['actualPaymentDate'], $decoded['bankReference']]);
		$saved = $this->savedIn(schema: 'InboundPayable');
		self::assertSame([], RegisterSchema::errors(slug: 'InboundPayable', object: $saved[count($saved) - 1]));

		$this->expectException(InvalidArgumentException::class);
		$service->markPaid(payableId: $id, bankReference: 'SEPA-778', bankAccount: '1100');
	}//end testMarkPaidClearsAgainstTheBankAndCallsBack()

	/**
	 * Finance rejects a requested payable: nothing is booked and the app hears
	 * `rejected` with the reason (REQ-IPY-003).
	 * @return void
	 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-003)
	 */
	public function testRejectBooksNothingAndCallsBackWithTheReason(): void {
		$service = $this->service();
		$id = (string)self::dispatch(service: $service, event: self::raised())->getResult()['payableId'];
		$this->store->saved = [];

		$payable = $service->reject(payableId: $id, reason: 'IBAN belongs to someone else');

		self::assertSame([], $this->savedIn(schema: 'JournalEntry'));
		self::assertSame('rejected', $payable['state']);
		$decoded = json_decode((string)$this->posts[0][1]['body'], true);
		self::assertSame(['rejected', 'IBAN belongs to someone else'], [$decoded['status'], $decoded['reason']]);
	}//end testRejectBooksNothingAndCallsBackWithTheReason()

	/**
	 * Without a secret for the app the callback is not sent unsigned: the step
	 * still succeeds and the payable records why (REQ-IPY-004).
	 * @return void
	 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-004)
	 */
	public function testNoSecretMeansNoUnsignedCallback(): void {
		$service = $this->service(config: [InboundPayableCallbackSender::CONFIG_SECRETS => '{}']);
		$id = (string)self::dispatch(service: $service, event: self::raised())->getResult()['payableId'];

		$payable = $service->approve(payableId: $id);

		self::assertSame([], $this->posts);
		self::assertSame('approved', $payable['state']);
		self::assertStringContainsString('secret', (string)$payable['callbackError']);
	}//end testNoSecretMeansNoUnsignedCallback()

	/**
	 * The expense account falls back from the category to the app, and with
	 * neither the approval is refused before anything is booked (REQ-IPY-002).
	 * @return void
	 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-002)
	 */
	public function testTheExpenseAccountFallsBackToTheAppThenRefuses(): void {
		$service = $this->service();
		$id = (string)self::dispatch(service: $service, event: self::raised(override: ['category' => 'other']))->getResult()['payableId'];
		$service->approve(payableId: $id);
		self::assertSame('4999', $this->savedIn(schema: 'JournalEntry')[0]['lines'][0]['accountNumber']);

		$service = $this->service(config: [InboundPayableService::CONFIG_EXPENSE_ACCOUNTS => '{}']);
		$id = (string)self::dispatch(service: $service, event: self::raised())->getResult()['payableId'];
		$this->store->saved = [];
		try {
			$service->approve(payableId: $id);
			self::fail('approved without an expense account');
		} catch (InvalidArgumentException $e) {
			self::assertStringContainsString(InboundPayableService::CONFIG_EXPENSE_ACCOUNTS, $e->getMessage());
		}

		self::assertSame([], $this->store->saved);
	}//end testTheExpenseAccountFallsBackToTheAppThenRefuses()

	/**
	 * The steps need payment.administer, and the listener is wired (REQ-IPY-002).
	 * @return void
	 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-002)
	 */
	public function testStepsNeedTheAdministerActionAndTheListenerIsWired(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('clerk');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturn(false);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('{"payment.administer":["finance"]}');
		$authorizer = new PaymentActionAuthorizer(appConfig: $config, userSession: $session, groupManager: $groups);
		$controller = new InboundPayableController(
			appName: 'shillinq',
			request: $this->createMock(IRequest::class),
			authorizer: $authorizer,
			payables: $this->service(),
			logger: $this->createMock(LoggerInterface::class),
		);
		self::assertSame(Http::STATUS_FORBIDDEN, $controller->approve(id: 'x')->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $controller->markPaid(id: 'x')->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $controller->reject(id: 'x')->getStatus());

		$wired = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$wired): void {
				$wired[] = [$event, $listener];
			}
		);
		(new ObjectRequestSettlementRegistration())->register(context: $context);
		self::assertContains([PayableRaisedEvent::class, PayableRaisedListener::class], $wired);
	}//end testStepsNeedTheAdministerActionAndTheListenerIsWired()
}//end class
