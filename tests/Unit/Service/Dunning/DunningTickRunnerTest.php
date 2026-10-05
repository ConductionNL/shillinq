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
	 * App-config values written by the runner.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

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

		$texts  = new DunningStageDefaultTexts();
		$stages = [];
		foreach ([1 => [7, 'EMAIL'], 2 => [21, 'EMAIL'], 3 => [35, 'EMAIL'], 4 => [56, 'REGISTERED_POST'], 5 => [70, 'COLLECTION_AGENCY_API']] as $nr => [$days, $channel]) {
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

		$this->store = new InMemoryObjectServiceStub(
			data: [
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
			],
			idFiltersMatchNothing: true
		);
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
		return ['id' => $id, 'customerId' => strtoupper($id), 'administrationId' => $administration, 'legalName' => $name, 'email' => $email, 'kvkNumber' => '12345678', 'lifecycleState' => 'active'];
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
			fn (string $app, string $key, string $default = ''): string => (['register' => 'shillinq'] + $this->config)[$key] ?? $default
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
		$adapter    = new MailDunningChannelAdapter(mailer: $this->mailer(), pdfGenerator: new InvoicePdfGenerator(), ublMapper: new ArInvoiceUblMapper(), logger: new NullLogger());
		$dispatcher = new DunningStageDispatcher(adapter: $adapter, logger: new NullLogger());
		$store      = $this->store;
		$container  = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => ($id === DunningStageDispatcher::class ? $dispatcher : $store));

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
		foreach ($this->store->setSchema($schema)->findAll() as $row) {
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
		return array_values(array_filter($this->store->setSchema('DunningRun')->findAll(), static fn (array $run): bool => $run['invoiceId'] === $invoiceId));
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
		self::assertSame(['administrationId' => 'ADM-KADE', 'markedOverdue' => 1, 'sent' => 1, 'failed' => 0, 'manual' => 0, 'skipped' => 0, 'errors' => 0, 'locked' => false], array_diff_key($reports[0], ['ranAt' => true]));
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
		$this->store->setSchema('ARInvoice')->saveObject(self::invoice(id: 'inv-0388', number: '2026-0388', dueDate: '2026-10-10', customer: 'cm-molen', administration: 'ADM-KADE'));
		$this->store->setSchema('ARInvoice')->saveObject(self::invoice(id: 'inv-0399', number: '2026-0399', dueDate: '2026-10-12', customer: 'cm-molen', administration: 'ADM-KADE'));
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
			$this->store->setSchema('ARInvoice')->saveObject(self::invoice(id: sprintf('inv-p%03d', $i), number: sprintf('2026-1%03d', $i), dueDate: '2026-10-21', customer: 'cm-molen', administration: 'ADM-KADE'));
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
		self::assertSame([], RegisterSchema::errors('Administration', self::administration(code: 'ADM-KADE', name: 'Adviesbureau Kade B.V.', enabled: true)));
	}//end testTheAdministrationSchemaDeclaresTheSwitch()
}//end class
