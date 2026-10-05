<?php

/**
 * DunningStageSendingTest
 *
 * Task 2.3 of receivables-automatic-dunning: executeStage() renders the stage
 * from the ladder, addresses it to the customer, sends it through the bound
 * adapter with the invoice, and records DELIVERED, FAILED or MANUAL with the
 * reason. A MANUAL run tells the credit controllers through the DunningRun
 * schema's notification rule (REQ-RAD-003, REQ-RAD-006, design D6).
 *
 * Built on the real classes: DunningRunService, DunningLetterComposer,
 * DunningStageRenderer, DunningStageDispatcher and MailDunningChannelAdapter
 * over the real InvoicePdfGenerator; only Nextcloud's mailer is a double. Every
 * saved run is validated against the real DunningRun schema of the merged
 * register.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-2.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Shillinq\Service\EInvoice\ArInvoiceUblMapper;
use OCA\Shillinq\Service\Dunning\DunningStageDefaultTexts;
use OCA\Shillinq\Service\Dunning\DunningStageDispatcher;
use OCA\Shillinq\Service\Dunning\MailDunningChannelAdapter;
use OCA\Shillinq\Service\DunningRunService;
use OCA\Shillinq\Service\InvoicePdfGenerator;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCA\Shillinq\Tests\Unit\Service\Support\OpenRegisterFaithfulObjectService;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\IAppConfig;
use OCP\Mail\IAttachment;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

require_once __DIR__ . '/InMemoryObjectService.php';

/**
 * executeStage() renders, addresses, sends and records the truth.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class DunningStageSendingTest extends TestCase {

	/**
	 * The mails the mailer double was handed: to, subject, body, attachments.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $mails = [];

	/**
	 * Whether the mailer double refuses every recipient.
	 *
	 * @var bool
	 */
	private bool $refuse = false;

	/**
	 * The object store.
	 *
	 * @var OpenRegisterFaithfulObjectService
	 */
	private OpenRegisterFaithfulObjectService $os;

	/**
	 * Seed the administration of the design's Seed Data: the Standaard ladder
	 * with its seeded texts, invoice 2026-0412 and its customer.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mails  = [];
		$this->refuse = false;
		$this->fresh();
	}//end setUp()

	/**
	 * A fresh store with the ladder, the customer and the invoice.
	 *
	 * @param array<string, mixed> $customer Customer fields to replace.
	 * @param array<string, mixed> $invoice  Invoice fields to replace.
	 *
	 * @return void
	 */
	private function fresh(array $customer = [], array $invoice = []): void {
		$this->os = new OpenRegisterFaithfulObjectService();

		$texts  = new DunningStageDefaultTexts();
		$stages = [];
		$ladder = [
			1 => [7, 'EMAIL'],
			2 => [21, 'EMAIL'],
			3 => [35, 'EMAIL'],
			4 => [56, 'REGISTERED_POST'],
			5 => [70, 'COLLECTION_AGENCY_API'],
		];
		foreach ($ladder as $nr => [$days, $channel]) {
			$nl       = $texts->for(stageNr: $nr, language: 'nl');
			$en       = $texts->for(stageNr: $nr, language: 'en');
			$stages[] = [
				'nr' => $nr,
				'daysAfterExpiryDate' => $days,
				'channel' => $channel,
				'subject' => ['nl' => $nl['subject'], 'en' => $en['subject']],
				'body' => ['nl' => $nl['body'], 'en' => $en['body']],
			];
		}

		$this->os->seed(schema: 'DunningLadder', rows: [[
			'id' => 'ladder-std',
			'administrationId' => 'adm-1',
			'customerGroup' => 'DEFAULT',
			'lifecycleState' => 'active',
			'stages' => $stages,
		],
		]);
		$this->os->seed(schema: 'CustomerMaster', rows: [self::customer($customer)]);
		$this->os->seed(schema: 'ARInvoice', rows: [array_merge(self::invoice(), $invoice)]);
	}//end fresh()

	/**
	 * Bakkerij De Korenaar B.V., the customer of the spec's scenarios.
	 *
	 * @param array<string, mixed> $extra Fields to add or replace.
	 *
	 * @return array<string, mixed>
	 */
	private static function customer(array $extra = []): array {
		return array_merge(
			[
				'id' => 'cm-korenaar',
				'customerId' => 'KOR-001',
				'administrationId' => 'adm-1',
				'legalName' => 'Bakkerij De Korenaar B.V.',
				'email' => 'administratie@korenaar.nl',
				'lifecycleState' => 'active',
			],
			$extra
		);
	}//end customer()

	/**
	 * Invoice 2026-0412, overdue since 12 March 2026.
	 *
	 * @return array<string, mixed>
	 */
	private static function invoice(): array {
		return [
			'id' => 'inv-0412',
			'administrationId' => 'adm-1',
			'invoiceNumber' => '2026-0412',
			'invoiceDate' => '2026-02-10',
			'dueDate' => '2026-03-12',
			'lifecycleState' => 'overdue',
			'currency' => 'EUR',
			'netAmount' => 1000.0,
			'vatAmount' => 210.0,
			'grossAmount' => 1210.0,
			'sellerName' => 'Adviesbureau Kade B.V.',
			'buyerName' => 'Bakkerij De Korenaar B.V.',
			'buyerCountryCode' => 'NL',
			'customerId' => 'cm-korenaar',
			'invoiceLines' => [['itemName' => 'Advies maart', 'quantity' => 1, 'unitPrice' => 1000.0, 'lineNetAmount' => 1000.0, 'vatRate' => 21]],
		];
	}//end invoice()

	/**
	 * Nextcloud's mailer, recording each message; it refuses every
	 * recipient when $this->refuse is set.
	 *
	 * @return IMailer
	 */
	private function mailer(): IMailer {
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturnCallback(
			function (): IMessage {
				$index = count($this->mails);
				$this->mails[$index] = ['attachments' => 0];
				$message = $this->createMock(IMessage::class);
				foreach (['setTo' => 'to', 'setSubject' => 'subject', 'setPlainBody' => 'body'] as $method => $key) {
					$message->method($method)->willReturnCallback(
						function (mixed $value) use ($message, $index, $key): IMessage {
							$this->mails[$index][$key] = $value;
							return $message;
						}
					);
				}

				$message->method('attach')->willReturnCallback(
					function () use ($message, $index): IMessage {
						$this->mails[$index]['attachments']++;
						return $message;
					}
				);
				return $message;
			}
		);
		$mailer->method('createAttachment')->willReturnCallback(fn (): IAttachment => $this->createMock(IAttachment::class));
		$mailer->method('send')->willReturnCallback(
			function (IMessage $message): array {
				if ($this->refuse === true) {
					return ['administratie@korenaar.nl'];
				}

				return [];
			}
		);
		return $mailer;
	}//end mailer()

	/**
	 * The service as Application.php binds it: the mail adapter behind the
	 * dispatcher.
	 *
	 * @return DunningRunService
	 */
	private function service(): DunningRunService {
		$adapter    = new MailDunningChannelAdapter(
			mailer: $this->mailer(),
			pdfGenerator: new InvoicePdfGenerator(),
			ublMapper: new ArInvoiceUblMapper(),
			logger: new NullLogger()
		);
		$dispatcher = new DunningStageDispatcher(adapter: $adapter, logger: new NullLogger());
		$os         = $this->os;
		$container  = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($dispatcher, $os) {
				if ($id === DunningStageDispatcher::class) {
					return $dispatcher;
				}

				return $os;
			}
		);

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ['register' => 'shillinq'][$key] ?? $default
		);

		return new DunningRunService(
			container: $container,
			appConfig: $appConfig,
			logger: new NullLogger(),
			objectService: new DuckObjectServiceAdapter($this->os),
		);
	}//end service()

	/**
	 * Run the stage the job would send for invoice 2026-0412 after a history.
	 *
	 * @param int $sentStages How many stages already went out (delivered).
	 *
	 * @return array<string, mixed> The saved run.
	 */
	private function tickAfter(int $sentStages): array {
		$runs = [];
		for ($nr = 1; $nr <= $sentStages; $nr++) {
			$runs[] = [
				'id' => 'run-' . $nr,
				'administrationId' => 'adm-1',
				'invoiceId' => 'inv-0412',
				'ladderId' => 'ladder-std',
				'stageNr' => $nr,
				'channel' => 'EMAIL',
				'deliveryStatus' => 'DELIVERED',
				'executedOn' => '2026-03-01T09:00:00+00:00',
			];
		}

		$this->os->seed(schema: 'DunningRun', rows: $runs);

		$run = $this->service()->tickInvoice(
			administrationId: 'adm-1',
			invoice: self::invoice(),
			now: new DateTimeImmutable('2026-06-10T09:00:00Z')
		);
		self::assertNotNull($run, 'The job sent nothing.');
		self::assertSame(($sentStages + 1), (int)$run['stageNr']);
		self::assertSame([], RegisterSchema::errors('DunningRun', self::withoutEntityColumns($run)), 'The saved run is one the DunningRun schema refuses.');
		return $run;
	}//end tickAfter()

	/**
	 * A saved record without the columns the engine adds itself.
	 *
	 * @param array<string, mixed> $run The saved run.
	 *
	 * @return array<string, mixed>
	 */
	private static function withoutEntityColumns(array $run): array {
		unset($run['id'], $run['uuid'], $run['@self'], $run['lifecycleState']);
		return $run;
	}//end withoutEntityColumns()

	/**
	 * The spec's scenario: stage 3 goes to the customer with the ladder's
	 * wording, the invoice number and the outstanding amount, the invoice
	 * attached, and the run reads DELIVERED.
	 *
	 * @return void
	 */
	public function testTheThirdStageIsRenderedFromTheLadderAndDelivered(): void {
		$run = $this->tickAfter(sentStages: 2);

		self::assertCount(1, $this->mails);
		$mail = $this->mails[0];
		self::assertSame(['administratie@korenaar.nl' => 'Bakkerij De Korenaar B.V.'], $mail['to']);
		self::assertStringContainsString('2026-0412', $mail['subject'] . $mail['body']);
		self::assertStringContainsString('1.210,00', $mail['body']);
		self::assertStringNotContainsString('{', $mail['subject'] . $mail['body'], 'A merge field went out unfilled.');
		self::assertSame(1, $mail['attachments'], 'The invoice PDF is not attached.');

		self::assertSame('DELIVERED', $run['deliveryStatus']);
		self::assertSame($mail['subject'], $run['renderedSubject']);
		self::assertSame($mail['body'], $run['renderedBody']);
		self::assertSame('administratie@korenaar.nl', $run['recipientEmail']);
		self::assertSame('Bakkerij De Korenaar B.V.', $run['recipientName']);
	}//end testTheThirdStageIsRenderedFromTheLadderAndDelivered()

	/**
	 * An English-speaking customer gets the English text of the stage.
	 *
	 * @return void
	 */
	public function testACustomerAbroadGetsTheEnglishText(): void {
		$this->fresh(invoice: ['buyerCountryCode' => 'DE']);
		$en = (new DunningStageDefaultTexts())->for(stageNr: 1, language: 'en');

		$run = $this->service()->tickInvoice(
			administrationId: 'adm-1',
			invoice: array_merge(self::invoice(), ['buyerCountryCode' => 'DE']),
			now: new DateTimeImmutable('2026-06-10T09:00:00Z')
		);

		self::assertNotNull($run);
		self::assertSame('Friendly reminder: invoice 2026-0412', $run['renderedSubject'], 'The English subject is ' . $en['subject'] . ', filled.');
	}//end testACustomerAbroadGetsTheEnglishText()

	/**
	 * A refused mail is FAILED with the reason, so the next run tries again.
	 *
	 * @return void
	 */
	public function testARefusedMailIsFailedWithTheReason(): void {
		$this->refuse = true;

		$run = $this->tickAfter(sentStages: 0);

		self::assertSame('FAILED', $run['deliveryStatus']);
		self::assertNotEmpty($run['deliveryNote'] ?? null, 'A failed run does not say why.');
	}//end testARefusedMailIsFailedWithTheReason()

	/**
	 * A customer without an email address is MANUAL with the reason, and no
	 * mail goes out.
	 *
	 * @return void
	 */
	public function testACustomerWithoutAnEmailAddressIsManual(): void {
		$this->fresh(customer: ['email' => '']);

		$run = $this->tickAfter(sentStages: 0);

		self::assertSame([], $this->mails);
		self::assertSame('MANUAL', $run['deliveryStatus']);
		self::assertNotEmpty($run['deliveryNote'] ?? null, 'A manual run does not say why.');
	}//end testACustomerWithoutAnEmailAddressIsManual()

	/**
	 * Task 2.3's verify: no channel other than mail ever records DELIVERED;
	 * the registered letter and the collection agency are MANUAL, and no mail
	 * goes out for them.
	 *
	 * @return void
	 */
	public function testNoNonMailChannelEverRecordsDelivered(): void {
		$run = $this->tickAfter(sentStages: 3);
		self::assertSame('REGISTERED_POST', $run['channel']);
		self::assertSame('MANUAL', $run['deliveryStatus']);

		self::assertSame([], $this->mails, 'The registered letter stage sent a mail.');

		$this->fresh();
		$run = $this->tickAfter(sentStages: 4);
		self::assertSame('COLLECTION_AGENCY_API', $run['channel']);
		self::assertSame('MANUAL', $run['deliveryStatus']);
		self::assertSame([], $this->mails, 'A non-mail stage sent a mail.');
	}//end testNoNonMailChannelEverRecordsDelivered()

	/**
	 * A text the caller already rendered (the voluntary contribution's own
	 * letter) is sent as it is, not replaced by the ladder's.
	 *
	 * @return void
	 */
	public function testARenderedTextFromTheCallerIsKept(): void {
		$run = $this->service()->executeStage(administrationId: 'adm-1', params: [
			'invoiceId' => 'inv-0412',
			'ladderId' => 'ladder-std',
			'stageNr' => 1,
			'channel' => 'EMAIL',
			'renderedSubject' => 'Herinnering vrijwillige bijdrage',
			'renderedBody' => 'Beste ouder, ...',
		]);

		self::assertSame('Herinnering vrijwillige bijdrage', $this->mails[0]['subject']);
		self::assertSame('DELIVERED', $run['deliveryStatus']);
	}//end testARenderedTextFromTheCallerIsKept()

	/**
	 * The DunningRun schema tells the credit controllers about a MANUAL run in
	 * the canonical dialect: a `created` trigger whose `filter` (the key
	 * OpenRegister evaluates for `created`; a `condition` there is ignored and
	 * fires on every run) matches deliveryStatus MANUAL, sent to the
	 * ar-controller group, with a subject in both languages naming only fields
	 * the run has.
	 *
	 * @return void
	 */
	public function testAManualRunNotifiesTheCreditControllers(): void {
		$schema = RegisterSchema::schema('DunningRun');
		$rules  = ($schema['x-openregister-notifications'] ?? []);
		self::assertArrayHasKey('onManual', $rules);
		$rule = $rules['onManual'];

		self::assertSame('created', $rule['trigger']['type']);
		self::assertSame(['field' => 'deliveryStatus', 'operator' => 'equals', 'value' => 'MANUAL'], $rule['trigger']['filter']);
		self::assertArrayNotHasKey('condition', $rule['trigger']);
		self::assertContains('MANUAL', $schema['properties']['deliveryStatus']['enum']);
		self::assertSame(['nc-notification'], $rule['channels']);
		self::assertSame([['kind' => 'groups', 'groups' => ['ar-controller']]], $rule['recipients']);
		self::assertTrue($rule['enabled']);

		foreach (['subject', 'message'] as $key) {
			self::assertEqualsCanonicalizing(['nl', 'en'], array_keys($rule[$key]), $key . ' is not in both languages.');
			foreach ($rule[$key] as $text) {
				preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $text, $fields);
				foreach ($fields[1] as $field) {
					self::assertArrayHasKey($field, $schema['properties'], $key . ' names {{' . $field . '}}, which a DunningRun does not have.');
				}
			}
		}

		// A MANUAL run with its reason is a run the schema accepts.
		$run = $this->tickAfter(sentStages: 3);
		self::assertSame('MANUAL', $run['deliveryStatus']);
	}//end testAManualRunNotifiesTheCreditControllers()

	/**
	 * Without the invoice there is no customer to address and no PDF to attach:
	 * the run waits for a person, with the reason, and no mail goes out.
	 *
	 * @return void
	 */
	public function testAStageForAnUnknownInvoiceSendsNothing(): void {
		$run = $this->service()->executeStage(
			administrationId: 'adm-1',
			params: ['invoiceId' => 'inv-gone', 'ladderId' => 'ladder-std', 'stageNr' => 1, 'channel' => 'EMAIL']
		);

		self::assertSame([], $this->mails);
		self::assertSame('MANUAL', $run['deliveryStatus']);
		self::assertNotEmpty($run['deliveryNote'] ?? null);
	}//end testAStageForAnUnknownInvoiceSendsNothing()
}//end class
