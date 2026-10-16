<?php

/**
 * DunningTickRunnerTest
 *
 * Task 3.1 of receivables-automatic-dunning: the daily job marks issued
 * invoices past their due date overdue and sends every overdue invoice the
 * stage that is due, for each administration with automatic reminders on, in
 * pages of 100, under a lock per administration. One failing invoice does not
 * stop the run, and the run report counts sent, failed, manual and skipped
 * (REQ-RAD-001).
 *
 * Built on the real classes: DunningTickJob, DunningTickRunner,
 * DunningRunService, DunningStageDispatcher and MailDunningChannelAdapter over
 * the real InvoicePdfGenerator, and ObjectTransitionRunner over an engine that
 * applies the ARInvoice lifecycle declared in the merged register. Only
 * Nextcloud's mailer, lock provider, app config and clock are doubles.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Dunning
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Dunning;

use DateTimeImmutable;
use OCA\Shillinq\BackgroundJob\DunningTickJob;
use OCA\Shillinq\Service\Dunning\DunningStageDefaultTexts;
use OCA\Shillinq\Service\Dunning\DunningStageDispatcher;
use OCA\Shillinq\Service\Dunning\DunningTickRunner;
use OCA\Shillinq\Service\Dunning\MailDunningChannelAdapter;
use OCA\Shillinq\Service\DunningRunService;
use OCA\Shillinq\Service\EInvoice\ArInvoiceUblMapper;
use OCA\Shillinq\Service\InvoicePdfGenerator;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Tests\Unit\Service\Bank\LifecycleFaithfulTransitionEngine;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\Mail\IAttachment;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * The daily dunning pass.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class DunningTickRunnerTest extends TestCase {

	/**
	 * The day of the spec's scenario.
	 */
	private const TODAY = '2026-10-22T06:00:00+00:00';

	/**
	 * The mails the mailer double was handed.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $mails = [];

	/**
	 * Recipients the mailer refuses.
	 *
	 * @var array<int, string>
	 */
	private array $refused = [];

	/**
	 * Invoice ids the engine refuses to move.
	 *
	 * @var array<int, string>
	 */
	private array $engineRefuses = [];

	/**
	 * Whether resolving the dispatcher fails, once, as a broken container would.
	 *
	 * @var bool
	 */
	private bool $dispatcherFailsOnce = false;

	/**
	 * App-config values written by the runner.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * The records the store starts with.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $seed = [];

	/**
	 * The object store.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * Adviesbureau Kade B.V. with dunning on, the Standaard ladder and its customer.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mails         = [];
		$this->refused       = [];
		$this->engineRefuses = [];
		$this->config        = [];
		$this->dispatcherFailsOnce = false;

		$texts  = new DunningStageDefaultTexts();
		$stages = [];
		$ladder = [1 => [7, 'EMAIL'], 2 => [21, 'EMAIL'], 3 => [35, 'EMAIL'], 4 => [56, 'REGISTERED_POST'], 5 => [70, 'COLLECTION_AGENCY_API']];
		foreach ($ladder as $nr => [$days, $channel]) {
			$nl       = $texts->for(stageNr: $nr, language: 'nl');
			$en       = $texts->for(stageNr: $nr, language: 'en');
			$stages[] = [
				'nr' => $nr,
				'daysAfterExpiryDate' => $days,
				'channel' => $channel,
				'statutoryEffect' => ([3 => '14_DAYS_BRIEF_BIK'][$nr] ?? null),
				'subject' => ['nl' => $nl['subject'], 'en' => $en['subject']],
				'body' => ['nl' => $nl['body'], 'en' => $en['body']],
			];
		}

		$this->seed = [
			'Administration' => [
				self::administration(code: 'ADM-KADE', name: 'Adviesbureau Kade B.V.', enabled: true),
				self::administration(code: 'ADM-LOODS', name: 'Werkplaats De Loods B.V.', enabled: false),
			],
			'DunningLadder' => [
				['id' => 'ladder-std', 'administrationId' => 'ADM-KADE', 'customerGroup' => 'DEFAULT', 'lifecycleState' => 'active', 'stages' => $stages],
				['id' => 'ladder-loods', 'administrationId' => 'ADM-LOODS', 'customerGroup' => 'DEFAULT', 'lifecycleState' => 'active', 'stages' => $stages],
			],
			'CustomerMaster' => [
				self::customer(id: 'cm-korenaar', name: 'Bakkerij De Korenaar B.V.', email: 'administratie@korenaar.nl', administration: 'ADM-KADE'),
				self::customer(id: 'cm-molen', name: 'Molenaar Installatietechniek B.V.', email: 'facturen@molenaar.nl', administration: 'ADM-KADE'),
				self::customer(id: 'cm-loods', name: 'Garage Van Dijk', email: 'info@garagevandijk.nl', administration: 'ADM-LOODS'),
			],
			'ARInvoice' => [
				self::invoice(id: 'inv-0412', number: '2026-0412', dueDate: '2026-10-15', customer: 'cm-korenaar', administration: 'ADM-KADE'),
				self::invoice(id: 'inv-loods', number: '2026-0077', dueDate: '2026-10-01', customer: 'cm-loods', administration: 'ADM-LOODS'),
			],
		];
		$this->store = new InMemoryObjectServiceStub(data: $this->seed, idFiltersMatchNothing: true);
	}//end setUp()

	/**
	 * An Administration as the register stores it.
	 *
	 * @param string $code    The administration code.
	 * @param string $name    The name.
	 * @param bool   $enabled Whether automatic reminders are on.
	 *
	 * @return array<string, mixed>
	 */
	private static function administration(string $code, string $name, bool $enabled): array {
		return [
			'id' => 'uuid-' . strtolower($code),
			'administrationCode' => $code,
			'name' => $name,
			'legalForm' => 'bv',
			'status' => 'actief',
			'dunningEnabled' => $enabled,
		];
	}//end administration()

	/**
	 * A customer.
	 *
	 * @param string $id             The record id.
	 * @param string $name           The legal name.
	 * @param string $email          The invoice email address.
	 * @param string $administration The administration code.
	 *
	 * @return array<string, mixed>
	 */
	private static function customer(string $id, string $name, string $email, string $administration): array {
		return [
			'id' => $id,
			'customerId' => strtoupper($id),
			'administrationId' => $administration,
			'legalName' => $name,
			'email' => $email,
			'kvkNumber' => '12345678',
			'lifecycleState' => 'active',
		];
	}//end customer()

	/**
	 * An issued, unpaid invoice.
	 *
	 * @param string $id             The record id.
	 * @param string $number         The invoice number.
	 * @param string $dueDate        The due date.
	 * @param string $customer       The customer record id.
	 * @param string $administration The administration code.
	 *
	 * @return array<string, mixed>
	 */
	private static function invoice(string $id, string $number, string $dueDate, string $customer, string $administration): array {
		return [
			'id' => $id,
			'administrationId' => $administration,
			'invoiceNumber' => $number,
			'invoiceDate' => '2026-09-15',
			'dueDate' => $dueDate,
			'lifecycleState' => 'issued',
			'currency' => 'EUR',
			'netAmount' => 1000.0,
			'vatAmount' => 210.0,
			'grossAmount' => 1210.0,
			'sellerName' => 'Adviesbureau Kade B.V.',
			'buyerName' => 'Klant',
			'buyerCountryCode' => 'NL',
			'customerId' => $customer,
			'invoiceLines' => [['itemName' => 'Advies september', 'quantity' => 1, 'unitPrice' => 1000.0, 'lineNetAmount' => 1000.0, 'vatRate' => 21]],
		];
	}//end invoice()

	/**
	 * Add an issued invoice of Molenaar Installatietechniek B.V. to Adviesbureau Kade B.V.
	 *
	 * @param string $id      The record id.
	 * @param string $number  The invoice number.
	 * @param string $dueDate The due date.
	 *
	 * @return void
	 */
	private function addInvoice(string $id, string $number, string $dueDate): void {
		$invoice = self::invoice(id: $id, number: $number, dueDate: $dueDate, customer: 'cm-molen', administration: 'ADM-KADE');
		$this->store->setSchema('ARInvoice')->saveObject($invoice);
	}//end addInvoice()

	/**
	 * Nextcloud's mailer, recording each message and refusing the addresses in $this->refused.
	 *
	 * @return IMailer
	 */
	private function mailer(): IMailer {
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturnCallback(
			function (): IMessage {
				$index = count($this->mails);
				$this->mails[$index] = [];
				$message = $this->createMock(IMessage::class);
				foreach (['setTo' => 'to', 'setSubject' => 'subject', 'setPlainBody' => 'body'] as $method => $key) {
					$message->method($method)->willReturnCallback(
						function (mixed $value) use ($message, $index, $key): IMessage {
							$this->mails[$index][$key] = $value;
							return $message;
						}
					);
				}

				$message->method('attach')->willReturnSelf();
				return $message;
			}
		);
		$mailer->method('createAttachment')->willReturnCallback(fn (): IAttachment => $this->createMock(IAttachment::class));
		$mailer->method('send')->willReturnCallback(
			function (IMessage $message): array {
				$to = array_keys((array)($this->mails[(count($this->mails) - 1)]['to'] ?? []));
				return array_values(array_intersect($to, $this->refused));
			}
		);
		return $mailer;
	}//end mailer()

	/**
	 * App config: the register slug, and the values the runner writes.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(): IAppConfig {
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config + ['register' => 'shillinq'])[$key] ?? $default
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);
		return $appConfig;
	}//end appConfig()

	/**
	 * The runner as the container builds it.
	 *
	 * @param ILockingProvider|null $locks The lock provider; a free one when null.
	 *
	 * @return DunningTickRunner
	 */
	private function runner(?ILockingProvider $locks = null): DunningTickRunner {
		$adapter    = new MailDunningChannelAdapter(
			mailer: $this->mailer(),
			pdfGenerator: new InvoicePdfGenerator(),
			ublMapper: new ArInvoiceUblMapper(),
			logger: new NullLogger()
		);
		$dispatcher = new DunningStageDispatcher(adapter: $adapter, logger: new NullLogger());
		$store      = $this->store;
		$container  = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($dispatcher, $store): object {
				if ($id !== DunningStageDispatcher::class) {
					return $store;
				}

				if ($this->dispatcherFailsOnce === true) {
					$this->dispatcherFailsOnce = false;
					throw new RuntimeException('The dispatcher could not be built.');
				}

				return $dispatcher;
			}
		);

		$appConfig = $this->appConfig();
		$dunning   = new DunningRunService(container: $container, appConfig: $appConfig, logger: new NullLogger(), objectService: $store);

		$faithful = new LifecycleFaithfulTransitionEngine(store: $store, schemas: ['ARInvoice']);
		$refuses  = &$this->engineRefuses;
		$engine   = new class ($faithful, $refuses) {
			/**
			 * @param LifecycleFaithfulTransitionEngine $faithful The engine applying the declared lifecycle.
			 * @param array<int,string>                 $refuses  Ids the engine refuses, as a guard would.
			 */
			public function __construct(private LifecycleFaithfulTransitionEngine $faithful, private array &$refuses) {
			}

			/**
			 * @param string              $objectId The object.
			 * @param string              $action   The transition.
			 * @param array<string,mixed> $data     Transition data.
			 *
			 * @return object
			 */
			public function transition(string $objectId, string $action, array $data = []): object {
				if (in_array($objectId, $this->refuses, true) === true) {
					throw new RuntimeException('Transition refused by a guard.');
				}

				return $this->faithful->transition($objectId, $action, $data);
			}
		};
		$engineContainer = $this->createStub(ContainerInterface::class);
		$engineContainer->method('has')->willReturn(true);
		$engineContainer->method('get')->willReturn($engine);

		if ($locks === null) {
			$locks = $this->createMock(ILockingProvider::class);
		}

		return new DunningTickRunner(
			objectService: $store,
			dunning: $dunning,
			transitions: new ObjectTransitionRunner(container: $engineContainer),
			locks: $locks,
			appConfig: $appConfig,
			logger: new NullLogger()
		);
	}//end runner()

	/**
	 * A stored record.
	 *
	 * @param string $schema The schema.
	 * @param string $id     The id.
	 *
	 * @return array<string, mixed>
	 */
	private function stored(string $schema, string $id): array {
		foreach ($this->store->setSchema($schema)->findAll([], _rbac: false, _multitenancy: false) as $row) {
			if ($row['id'] === $id) {
				return $row;
			}
		}

		self::fail($schema . ' ' . $id . ' is not stored.');
	}//end stored()

	/**
	 * The runs saved for an invoice.
	 *
	 * @param string $invoiceId The invoice.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function runs(string $invoiceId): array {
		$runs = $this->store->setSchema('DunningRun')->findAll([], _rbac: false, _multitenancy: false);
		return array_values(array_filter($runs, static fn (array $run): bool => $run['invoiceId'] === $invoiceId));
	}//end runs()

	/**
	 * The spec's scenario: with dunning on for Adviesbureau Kade B.V., invoice
	 * 2026-0412, due on 15 October and unpaid, is overdue on 22 October and
	 * stage 1 went out by email, without anyone acting.
	 *
	 * @return void
	 */
	public function testTheFirstReminderGoesOutWithoutAnyoneActing(): void {
		$reports = $this->runner()->runAll(now: new DateTimeImmutable(self::TODAY));

		self::assertSame('overdue', $this->stored('ARInvoice', 'inv-0412')['lifecycleState']);
		$runs = $this->runs('inv-0412');
		self::assertCount(1, $runs);
		self::assertSame(1, (int)$runs[0]['stageNr']);
		self::assertSame('EMAIL', $runs[0]['channel']);
		self::assertSame('DELIVERED', $runs[0]['deliveryStatus']);
		$run = $runs[0];
		unset($run['id'], $run['lifecycleState']);
		self::assertSame([], RegisterSchema::errors('DunningRun', $run), 'The job saved a run the DunningRun schema refuses.');
		self::assertSame(['administratie@korenaar.nl' => 'Bakkerij De Korenaar B.V.'], $this->mails[0]['to']);
		self::assertStringContainsString('2026-0412', $this->mails[0]['subject']);

		self::assertCount(1, $reports, 'Only the administration with dunning on is run.');
		self::assertSame(
			[
				'administrationId' => 'ADM-KADE',
				'markedOverdue' => 1,
				'sent' => 1,
				'failed' => 0,
				'manual' => 0,
				'skipped' => 0,
				'errors' => 0,
				'locked' => false,
			],
			array_diff_key($reports[0], ['ranAt' => true])
		);
	}//end testTheFirstReminderGoesOutWithoutAnyoneActing()

	/**
	 * An administration with dunning off is not touched: its overdue invoice
	 * stays issued and gets no reminder.
	 *
	 * @return void
	 */
	public function testAnAdministrationWithDunningOffIsLeftAlone(): void {
		$this->runner()->runAll(now: new DateTimeImmutable(self::TODAY));

		self::assertSame('issued', $this->stored('ARInvoice', 'inv-loods')['lifecycleState']);
		self::assertSame([], $this->runs('inv-loods'));
		foreach ($this->mails as $mail) {
			self::assertArrayNotHasKey('info@garagevandijk.nl', $mail['to']);
		}
	}//end testAnAdministrationWithDunningOffIsLeftAlone()

	/**
	 * One invoice the mailer refuses and one the engine refuses to move do not
	 * stop the run: the third invoice still gets its reminder, and the report
	 * counts the failure and the error.
	 *
	 * @return void
	 */
	public function testAFailingInvoiceDoesNotStopTheRun(): void {
		$this->addInvoice(id: 'inv-0388', number: '2026-0388', dueDate: '2026-10-10');
		$this->addInvoice(id: 'inv-0399', number: '2026-0399', dueDate: '2026-10-12');
		$this->refused       = ['facturen@molenaar.nl'];
		$this->engineRefuses = ['inv-0399'];

		$reports = $this->runner()->runAll(now: new DateTimeImmutable(self::TODAY));

		self::assertSame('FAILED', $this->runs('inv-0388')[0]['deliveryStatus']);
		self::assertSame('issued', $this->stored('ARInvoice', 'inv-0399')['lifecycleState']);
		self::assertSame([], $this->runs('inv-0399'));
		self::assertSame('DELIVERED', $this->runs('inv-0412')[0]['deliveryStatus'], 'The run stopped at the failing invoice.');
		self::assertSame(2, $reports[0]['markedOverdue']);
		self::assertSame(1, $reports[0]['sent']);
		self::assertSame(1, $reports[0]['failed']);
		self::assertSame(1, $reports[0]['errors']);
	}//end testAFailingInvoiceDoesNotStopTheRun()

	/**
	 * The job reads in pages of 100: all 150 invoices are moved to overdue and
	 * all 150 are looked at, none due for a reminder yet.
	 *
	 * @return void
	 */
	public function testEveryPageIsRead(): void {
		for ($i = 1; $i <= 150; $i++) {
			$this->addInvoice(id: sprintf('inv-p%03d', $i), number: sprintf('2026-1%03d', $i), dueDate: '2026-10-21');
		}

		$this->store->deleteObject('inv-0412', schema: 'ARInvoice');

		$reports = $this->runner()->runAll(now: new DateTimeImmutable(self::TODAY));

		self::assertSame(150, $reports[0]['markedOverdue']);
		self::assertSame(150, $reports[0]['skipped']);
		self::assertSame('overdue', $this->stored('ARInvoice', 'inv-p150')['lifecycleState']);
		self::assertSame([], $this->mails);
	}//end testEveryPageIsRead()

	/**
	 * An administration another run holds the lock of is skipped and reported locked.
	 *
	 * @return void
	 */
	public function testAnAdministrationAnotherRunHoldsIsSkipped(): void {
		$locks = $this->createMock(ILockingProvider::class);
		$locks->method('acquireLock')->willThrowException(new LockedException('shillinq/dunning-tick/ADM-KADE'));
		$locks->expects(self::never())->method('releaseLock');

		$reports = $this->runner(locks: $locks)->runAll(now: new DateTimeImmutable(self::TODAY));

		self::assertTrue($reports[0]['locked']);
		self::assertSame('issued', $this->stored('ARInvoice', 'inv-0412')['lifecycleState']);
		self::assertSame([], $this->mails);
	}//end testAnAdministrationAnotherRunHoldsIsSkipped()

	/**
	 * The lock is taken per administration and released after the run.
	 *
	 * @return void
	 */
	public function testTheLockIsReleasedAfterTheRun(): void {
		$locks = $this->createMock(ILockingProvider::class);
		$locks->expects(self::once())->method('acquireLock')->with('shillinq/dunning-tick/ADM-KADE', ILockingProvider::LOCK_EXCLUSIVE);
		$locks->expects(self::once())->method('releaseLock')->with('shillinq/dunning-tick/ADM-KADE', ILockingProvider::LOCK_EXCLUSIVE);

		$this->runner(locks: $locks)->runAll(now: new DateTimeImmutable(self::TODAY));
	}//end testTheLockIsReleasedAfterTheRun()

	/**
	 * The run report is kept, so the Dunning runs page can show it, and a
	 * second run on the same day sends nothing again.
	 *
	 * @return void
	 */
	public function testTheReportIsKeptAndASecondRunSendsNothingAgain(): void {
		$runner = $this->runner();
		$runner->runAll(now: new DateTimeImmutable(self::TODAY));
		$second = $runner->runAll(now: new DateTimeImmutable(self::TODAY));

		self::assertCount(1, $this->mails);
		self::assertSame(1, $second[0]['skipped']);
		self::assertSame(0, $second[0]['sent']);
		$kept = $runner->lastReports();
		self::assertSame(0, $kept['ADM-KADE']['sent']);
		self::assertSame(1, $kept['ADM-KADE']['skipped']);
	}//end testTheReportIsKeptAndASecondRunSendsNothingAgain()

	/**
	 * The background job runs the pass on the clock's day, once a day, never in parallel.
	 *
	 * @return void
	 */
	public function testTheDailyJobRunsThePass(): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable(self::TODAY));
		$job = new DunningTickJob($time, $this->runner(), new NullLogger());

		(new ReflectionMethod($job, 'run'))->invoke($job, null);

		self::assertSame(86400, $job->getInterval());
		self::assertFalse($job->getAllowParallelRuns());
		self::assertSame('overdue', $this->stored('ARInvoice', 'inv-0412')['lifecycleState']);
		self::assertCount(1, $this->mails);
	}//end testTheDailyJobRunsThePass()

	/**
	 * The switch lives on the Administration schema, off unless set, and the
	 * seeded administration is one the schema accepts.
	 *
	 * @return void
	 */
	public function testTheAdministrationSchemaDeclaresTheSwitch(): void {
		$property = (RegisterSchema::schema('Administration')['properties']['dunningEnabled'] ?? null);

		self::assertNotNull($property, 'Administration declares no dunningEnabled.');
		self::assertSame('boolean', $property['type']);
		self::assertFalse($property['default']);
		$kade = self::administration(code: 'ADM-KADE', name: 'Adviesbureau Kade B.V.', enabled: true);
		self::assertSame([], RegisterSchema::errors('Administration', $kade));
	}//end testTheAdministrationSchemaDeclaresTheSwitch()

	/**
	 * A customer without an email address is handed to a person: the run
	 * reads MANUAL and the report counts it as manual.
	 *
	 * @return void
	 */
	public function testACustomerWithoutAnEmailAddressIsCountedManual(): void {
		$customer          = $this->stored('CustomerMaster', 'cm-korenaar');
		$customer['email'] = '';
		$this->store->setSchema('CustomerMaster')->saveObject($customer);

		$reports = $this->runner()->runAll(now: new DateTimeImmutable(self::TODAY));

		self::assertSame('MANUAL', $this->runs('inv-0412')[0]['deliveryStatus']);
		self::assertSame(1, $reports[0]['manual']);
		self::assertSame(0, $reports[0]['sent']);
		self::assertSame([], $this->mails);
	}//end testACustomerWithoutAnEmailAddressIsCountedManual()

	/**
	 * An invoice whose sending throws is counted as failed and an error, and
	 * the next invoice still gets its reminder.
	 *
	 * @return void
	 */
	public function testAnInvoiceWhoseSendingThrowsDoesNotStopTheRun(): void {
		$this->addInvoice(id: 'inv-0388', number: '2026-0388', dueDate: '2026-10-10');
		$this->dispatcherFailsOnce = true;

		$reports = $this->runner()->runAll(now: new DateTimeImmutable(self::TODAY));

		self::assertSame([], $this->runs('inv-0412'), 'The first invoice threw and saved no run.');
		self::assertSame('DELIVERED', $this->runs('inv-0388')[0]['deliveryStatus']);
		self::assertSame(1, $reports[0]['sent']);
		self::assertSame(1, $reports[0]['failed']);
		self::assertSame(1, $reports[0]['errors']);
	}//end testAnInvoiceWhoseSendingThrowsDoesNotStopTheRun()

	/**
	 * An enabled administration without a code or an id has no invoices to
	 * find: it is reported, with nothing done.
	 *
	 * @return void
	 */
	public function testAnAdministrationWithoutIdentifiersIsReportedEmpty(): void {
		$this->store = new InMemoryObjectServiceStub(
			data: ['Administration' => [['name' => 'Naamloos', 'dunningEnabled' => true]]],
			idFiltersMatchNothing: true
		);

		$reports = $this->runner()->runAll(now: new DateTimeImmutable(self::TODAY));

		self::assertCount(1, $reports);
		self::assertSame('', $reports[0]['administrationId']);
		self::assertSame(0, $reports[0]['markedOverdue'] + $reports[0]['sent'] + $reports[0]['skipped']);
	}//end testAnAdministrationWithoutIdentifiersIsReportedEmpty()

	/**
	 * A stored report nobody can read is replaced by this run's, and an empty
	 * register setting falls back to the shillinq register.
	 *
	 * @return void
	 */
	public function testAnUnreadableReportIsReplacedAndAnEmptyRegisterFallsBack(): void {
		$this->config = ['dunning.job_report' => '{not json', 'register' => ''];

		$runner = $this->runner();
		$runner->runAll(now: new DateTimeImmutable(self::TODAY));

		self::assertSame(['ADM-KADE'], array_keys($runner->lastReports()));
		self::assertSame(1, $runner->lastReports()['ADM-KADE']['sent']);
	}//end testAnUnreadableReportIsReplacedAndAnEmptyRegisterFallsBack()

	/**
	 * When the lock backend itself fails, the job logs the failed pass and
	 * returns, so cron goes on with the next job.
	 *
	 * @return void
	 */
	public function testTheJobLogsAPassThatFails(): void {
		$locks = $this->createMock(ILockingProvider::class);
		$locks->method('acquireLock')->willThrowException(new RuntimeException('The lock backend is down.'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with('DunningTickJob: daily pass failed', ['exception' => 'The lock backend is down.']);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable(self::TODAY));
		$job = new DunningTickJob($time, $this->runner(locks: $locks), $logger);

		(new ReflectionMethod($job, 'run'))->invoke($job, null);

		self::assertSame('issued', $this->stored('ARInvoice', 'inv-0412')['lifecycleState']);
	}//end testTheJobLogsAPassThatFails()

	/**
	 * The store as OpenRegister answers a caller with NO session user, as cron
	 * runs the job (lane 35's open question).
	 *
	 * OpenRegister origin/development: a read with organisation scoping on
	 * (`_multitenancy` true, the default) goes through
	 * MagicOrganizationHandler::resolveOrganizationScope(). With no user it is a
	 * system context only on the command line or inside runAsSystem()
	 * (isSystemContext(), the same rule as
	 * MagicRbacHandler::isTrustedSystemCaller()); otherwise a userless caller
	 * has no active organisation, the scope is SCOPE_NONE, and the query gets
	 * `1 = 0`. The dunning schemas declare no `authorization`, so
	 * MagicSearchHandler::multitenancyApplies() keeps that filter on. A read
	 * with `_multitenancy` false skips it, as the runner's own listing does.
	 *
	 * @param bool $commandLine Whether PHP runs under the CLI SAPI (system cron, occ) or a web request (AJAX or webcron).
	 *
	 * @return InMemoryObjectServiceStub
	 */
	private function userlessStore(bool $commandLine): InMemoryObjectServiceStub {
		return new class ($this->seed, $commandLine) extends InMemoryObjectServiceStub {
			/**
			 * How deep the current call sits inside runAsSystem().
			 *
			 * @var int
			 */
			private int $system = 0;

			/**
			 * @param array<string,array<int,array<string,mixed>>> $seed        The records.
			 * @param bool                                         $commandLine Whether PHP runs under the CLI SAPI.
			 */
			public function __construct(array $seed, private bool $commandLine) {
				parent::__construct(data: $seed, idFiltersMatchNothing: true);
			}

			/**
			 * Whether OpenRegister treats this userless caller as the system.
			 *
			 * @return bool
			 */
			private function trusted(): bool {
				return $this->commandLine === true || $this->system > 0;
			}

			/**
			 * @param callable $operation The operation.
			 *
			 * @return mixed
			 */
			public function runAsSystem(callable $operation) {
				$this->system++;
				try {
					return $operation();
				} finally {
					$this->system--;
				}
			}

			/**
			 * @param array $config        The query.
			 * @param bool  $_rbac         Register RBAC.
			 * @param bool  $_multitenancy Organisation scoping.
			 *
			 * @return array
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				if ($_multitenancy === true && $this->trusted() === false) {
					return [];
				}

				return parent::findAll($config, $_rbac, $_multitenancy);
			}

			/**
			 * @param int|string      $id            The id.
			 * @param array|null      $_extend       Extends.
			 * @param bool            $files         Files.
			 * @param string|int|null $register      Register.
			 * @param string|int|null $schema        Schema.
			 * @param bool            $_rbac         Register RBAC.
			 * @param bool            $_multitenancy Organisation scoping.
			 * @param bool            $_render       Render.
			 * @param bool            $_audit        Audit.
			 *
			 * @return \OCA\OpenRegister\Contract\ObjectEntityInterface|null
			 */
			public function find(
				int|string $id,
				?array $_extend = [],
				bool $files = false,
				string|int|null $register = null,
				string|int|null $schema = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
				bool $_render = true,
				bool $_audit = true
			): ?\OCA\OpenRegister\Contract\ObjectEntityInterface {
				if ($_multitenancy === true && $this->trusted() === false) {
					return null;
				}

				return parent::find($id, $_extend, $files, $register, $schema, $_rbac, $_multitenancy, $_render, $_audit);
			}
		};
	}//end userlessStore()

	/**
	 * Run the daily job with no session user, as cron does.
	 *
	 * @param bool $commandLine Whether cron runs on the command line.
	 *
	 * @return void
	 */
	private function runJobWithoutAUser(bool $commandLine): void {
		$this->store = $this->userlessStore(commandLine: $commandLine);
		$time        = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable(self::TODAY));
		$job = new DunningTickJob($time, $this->runner(), new NullLogger());

		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end runJobWithoutAUser()

	/**
	 * System cron on the command line: OpenRegister treats a userless CLI
	 * caller as the system, so the service's own reads (ladder, customer,
	 * earlier runs, pauses) return rows and stage 1 goes out.
	 *
	 * @return void
	 */
	public function testOnTheCommandLineTheJobsReadsReturnRows(): void {
		$this->runJobWithoutAUser(commandLine: true);

		self::assertSame('overdue', $this->stored('ARInvoice', 'inv-0412')['lifecycleState']);
		self::assertCount(1, $this->runs('inv-0412'));
		self::assertCount(1, $this->mails);
	}//end testOnTheCommandLineTheJobsReadsReturnRows()

	/**
	 * AJAX or webcron: cron runs in a web request with no user. The runner's
	 * listing turns scoping off, but every read inside DunningRunService keeps
	 * it on, so without a system context it found no ladder and sent nothing.
	 * The pass now runs as the system, so the reads return rows in every cron
	 * mode.
	 *
	 * @return void
	 */
	public function testInAWebCronTheJobsReadsReturnRowsToo(): void {
		$this->runJobWithoutAUser(commandLine: false);

		self::assertSame('overdue', $this->stored('ARInvoice', 'inv-0412')['lifecycleState']);
		$runs = $this->runs('inv-0412');
		self::assertCount(1, $runs, 'The service read no ladder: nothing was sent.');
		self::assertSame(1, (int)$runs[0]['stageNr']);
		self::assertCount(1, $this->mails);
	}//end testInAWebCronTheJobsReadsReturnRowsToo()

	/**
	 * REQ-RAD-008: before switching dunning on, the Next run panel lists per
	 * invoice the stage and channel the job would send, by the job's own
	 * choice, and writes nothing. De Loods has dunning off; its invoice due on
	 * 1 October would get stage 1 by email.
	 *
	 * @return void
	 */
	public function testTheNextRunPreviewListsWhatTheJobWouldSendAndWritesNothing(): void {
		$this->store->setSchema('ARInvoice')->saveObject(
			self::invoice(id: 'inv-later', number: '2026-0099', dueDate: '2026-11-30', customer: 'cm-loods', administration: 'ADM-LOODS')
		);
		$loods = $this->store->setSchema('Administration')->findAll(['filters' => ['administrationCode' => 'ADM-LOODS']])[0];

		$rows = $this->runner()->previewAdministration(administration: $loods, now: new DateTimeImmutable(self::TODAY));

		self::assertSame(
			[
				[
					'invoiceId' => 'inv-loods',
					'invoiceNumber' => '2026-0077',
					'customer' => 'Klant',
					'dueDate' => '2026-10-01',
					'amount' => 1210.0,
					'stageNr' => 1,
					'channel' => 'EMAIL',
					'ladderId' => 'ladder-loods',
				],
			],
			$rows
		);
		self::assertSame('issued', $this->stored('ARInvoice', 'inv-loods')['lifecycleState'], 'The preview moved an invoice.');
		self::assertSame([], $this->runs('inv-loods'));
		self::assertSame([], $this->mails);
	}//end testTheNextRunPreviewListsWhatTheJobWouldSendAndWritesNothing()

	/**
	 * The preview names the stage the job will send next, not one already sent:
	 * after the run sent stage 1, an invoice whose stage 2 is not due yet is
	 * not listed.
	 *
	 * @return void
	 */
	public function testThePreviewLeavesOutAnInvoiceWhoseNextStageIsNotDue(): void {
		$runner = $this->runner();
		$runner->runAll(now: new DateTimeImmutable(self::TODAY));
		$kade = $this->store->setSchema('Administration')->findAll(['filters' => ['administrationCode' => 'ADM-KADE']])[0];

		self::assertSame([], $runner->previewAdministration(administration: $kade, now: new DateTimeImmutable(self::TODAY)));
		self::assertCount(1, $this->runs('inv-0412'), 'The preview sent a stage.');
	}//end testThePreviewLeavesOutAnInvoiceWhoseNextStageIsNotDue()

	/**
	 * The job report of the administration, found under the code or the id.
	 *
	 * @return void
	 */
	public function testTheLastReportIsFoundForTheAdministration(): void {
		$runner = $this->runner();
		$kade   = $this->store->setSchema('Administration')->findAll(['filters' => ['administrationCode' => 'ADM-KADE']])[0];
		$loods  = $this->store->setSchema('Administration')->findAll(['filters' => ['administrationCode' => 'ADM-LOODS']])[0];
		self::assertNull($runner->lastReportFor(administration: $kade));

		$runner->runAll(now: new DateTimeImmutable(self::TODAY));

		self::assertSame(1, $runner->lastReportFor(administration: $kade)['sent']);
		self::assertNull($runner->lastReportFor(administration: $loods), 'De Loods has dunning off and never ran.');
	}//end testTheLastReportIsFoundForTheAdministration()
}//end class
