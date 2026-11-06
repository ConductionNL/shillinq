<?php

/**
 * Café De Zwaan, the payment plan fixture (receivables-payment-plans).
 *
 * One in-memory register behind the real services: PaymentPlanService,
 * PaymentPlanAllocator, the real DunningRunService for the pauses, the real
 * ManualMatchService for the bank match and the declared lifecycles run by
 * LifecycleFaithfulTransitionEngine. Only the mailer and the clock are doubles.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\PaymentPlan
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\PaymentPlan;

use DateTime;
use OCA\Shillinq\PaymentPlan\PaymentPlanAllocator;
use OCA\Shillinq\PaymentPlan\PaymentPlanBankMatcher;
use OCA\Shillinq\PaymentPlan\PaymentPlanMailer;
use OCA\Shillinq\PaymentPlan\PaymentPlanSchedule;
use OCA\Shillinq\PaymentPlan\PaymentPlanService;
use OCA\Shillinq\Service\Bank\ManualMatchService;
use OCA\Shillinq\Service\DunningRunService;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Bank\LifecycleFaithfulTransitionEngine;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the services over one store.
 */
trait PaymentPlanFixture {
	/**
	 * The store.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * The engine.
	 *
	 * @var LifecycleFaithfulTransitionEngine
	 */
	private LifecycleFaithfulTransitionEngine $engine;

	/**
	 * Mails handed to the mailer: to, subject, body.
	 *
	 * @var array<int,array{to:array<int,string>,subject:string,body:string}>
	 */
	private array $mails = [];

	/**
	 * Today for the clock.
	 *
	 * @var string
	 */
	private string $today = '2026-10-15';

	/**
	 * Seed the administration, the customer, two overdue invoices and six earlier plans.
	 *
	 * @return void
	 */
	private function seed(): void {
		$plans = [];
		for ($i = 1; $i <= 6; $i++) {
			$plans[] = ['id' => 'plan-old-' . $i, 'planNumber' => sprintf('RGL-2026-%04d', $i), 'lifecycleState' => 'completed', 'administrationId' => 'adm-hoekstra'];
		}

		$this->store = new InMemoryObjectServiceStub(
			[
				'Administration' => [
					['id' => 'adm-hoekstra', 'name' => 'Installatiebedrijf Hoekstra', 'primaryIban' => 'NL20INGB0001234567'],
				],
				'CustomerMaster' => [
					['id' => 'cust-zwaan', 'customerId' => 'D-1042', 'legalName' => 'De Zwaan Horeca B.V.', 'tradeName' => 'Café De Zwaan', 'email' => 'administratie@dezwaan.example', 'iban' => 'NL44RABO0123456789', 'administrationId' => 'adm-hoekstra'],
					['id' => 'cust-other', 'customerId' => 'D-2000', 'legalName' => 'Ander B.V.', 'administrationId' => 'adm-hoekstra'],
				],
				'ARInvoice' => [
					['id' => 'ar-0266', 'invoiceNumber' => '2026-0266', 'customerId' => 'cust-zwaan', 'invoiceDate' => '2026-07-20', 'dueDate' => '2026-08-19', 'lifecycleState' => 'overdue', 'grossAmount' => 605.00, 'amountDue' => 605.00, 'paidAmount' => 0, 'administrationId' => 'adm-hoekstra'],
					['id' => 'ar-0231', 'invoiceNumber' => '2026-0231', 'customerId' => 'cust-zwaan', 'invoiceDate' => '2026-06-15', 'dueDate' => '2026-07-15', 'lifecycleState' => 'overdue', 'grossAmount' => 1815.00, 'amountDue' => 1815.00, 'paidAmount' => 0, 'administrationId' => 'adm-hoekstra'],
					['id' => 'ar-0300', 'invoiceNumber' => '2026-0300', 'customerId' => 'cust-zwaan', 'invoiceDate' => '2026-10-01', 'dueDate' => '2026-10-31', 'lifecycleState' => 'issued', 'grossAmount' => 400.00, 'amountDue' => 400.00, 'paidAmount' => 0, 'administrationId' => 'adm-hoekstra'],
					['id' => 'ar-other', 'invoiceNumber' => '2026-0100', 'customerId' => 'cust-other', 'invoiceDate' => '2026-05-01', 'dueDate' => '2026-05-31', 'lifecycleState' => 'overdue', 'grossAmount' => 100.00, 'amountDue' => 100.00, 'paidAmount' => 0, 'administrationId' => 'adm-hoekstra'],
				],
				'PaymentPlan' => $plans,
				'BankStatement' => [
					['id' => 'stmt-1', 'statementId' => 'stmt-1', 'bankAccountIban' => 'NL20INGB0001234567', 'lifecycleState' => 'in-progress', 'administrationId' => 'adm-hoekstra'],
				],
			]
		);
		$this->engine = new LifecycleFaithfulTransitionEngine(
			store: $this->store,
			schemas: ['PaymentPlan', 'PaymentPlanInstalment', 'ARInvoice', 'ReconciliationMatch', 'BankStatementLine', 'DunningPauseDispute']
		);

	}//end seed()

	/**
	 * A bank line on the store.
	 *
	 * @param string $id         The line id.
	 * @param float  $amount     The amount.
	 * @param string $remittance The remittance.
	 * @param string $iban       The counterparty IBAN.
	 *
	 * @return array<string,mixed>
	 */
	private function line(string $id, float $amount, string $remittance, string $iban = 'NL44RABO0123456789'): array {
		$line = [
			'id' => $id, 'lineId' => 'L-' . $id, 'statementId' => 'stmt-1', 'lineNumber' => 1, 'valueDate' => '2026-11-01',
			'amount' => $amount, 'currency' => 'EUR', 'remittanceInfo' => $remittance, 'counterpartyName' => 'Cafe De Zwaan',
			'counterpartyIban' => $iban, 'status' => 'unmatched', 'matchState' => 'unmatched', 'administrationId' => 'adm-hoekstra',
		];
		$this->store->setSchema('BankStatementLine')->saveObject($line);
		return $line;

	}//end line()

	/**
	 * The runner with the faithful engine.
	 *
	 * @return ObjectTransitionRunner
	 */
	private function runner(): ObjectTransitionRunner {
		$engine = $this->engine;
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturnCallback(static fn (): object => $engine);
		return new ObjectTransitionRunner(container: $container);

	}//end runner()

	/**
	 * Settings answering the register slug.
	 *
	 * @return SettingsService
	 */
	private function settings(): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		return $settings;

	}//end settings()

	/**
	 * The real dunning service over the store.
	 *
	 * @return DunningRunService
	 */
	private function dunning(): DunningRunService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
		return new DunningRunService(
			$this->createMock(ContainerInterface::class),
			$config,
			$this->createMock(LoggerInterface::class),
			$this->store
		);

	}//end dunning()

	/**
	 * The mailer with a capturing IMailer and a pass-through IL10N.
	 *
	 * @return PaymentPlanMailer
	 */
	private function mailer(): PaymentPlanMailer {
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturnCallback(
			function (): IMessage {
				$index = count($this->mails);
				$this->mails[$index] = ['to' => [], 'subject' => '', 'body' => ''];
				$message = $this->createMock(IMessage::class);
				$message->method('setTo')->willReturnCallback(
					function (array $to) use ($index, $message): IMessage {
						$this->mails[$index]['to'] = $to;
						return $message;
					}
				);
				$message->method('setSubject')->willReturnCallback(
					function (string $subject) use ($index, $message): IMessage {
						$this->mails[$index]['subject'] = $subject;
						return $message;
					}
				);
				$message->method('setPlainBody')->willReturnCallback(
					function (string $body) use ($index, $message): IMessage {
						$this->mails[$index]['body'] = $body;
						return $message;
					}
				);
				return $message;
			}
		);
		$mailer->method('send')->willReturn([]);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		return new PaymentPlanMailer($mailer, $l10n, $this->createMock(LoggerInterface::class));

	}//end mailer()

	/**
	 * The clock.
	 *
	 * @return ITimeFactory
	 */
	private function clock(): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(fn (): DateTime => new DateTime($this->today . 'T09:00:00Z'));
		return $time;

	}//end clock()

	/**
	 * The allocator.
	 *
	 * @return PaymentPlanAllocator
	 */
	private function allocator(): PaymentPlanAllocator {
		return new PaymentPlanAllocator($this->store, $this->settings(), $this->runner(), $this->createMock(LoggerInterface::class));

	}//end allocator()

	/**
	 * The service under test.
	 *
	 * @return PaymentPlanService
	 */
	private function service(): PaymentPlanService {
		return new PaymentPlanService(
			$this->store,
			$this->settings(),
			new PaymentPlanSchedule(),
			$this->allocator(),
			$this->dunning(),
			$this->runner(),
			$this->mailer(),
			$this->clock(),
			$this->createMock(LoggerInterface::class)
		);

	}//end service()

	/**
	 * The bank matcher.
	 *
	 * @return PaymentPlanBankMatcher
	 */
	private function matcher(): PaymentPlanBankMatcher {
		$matches = new ManualMatchService($this->store, $this->runner(), $this->settings(), $this->createMock(LoggerInterface::class));
		return new PaymentPlanBankMatcher($this->store, $this->settings(), $matches, $this->service(), $this->createMock(LoggerInterface::class));

	}//end matcher()

	/**
	 * Draw up and activate the Café De Zwaan plan: six monthly from 2026-11-01.
	 *
	 * @return array<string,mixed> The active plan.
	 */
	private function activeZwaanPlan(): array {
		$service = $this->service();
		$draft = $service->draft(
			[
				'administrationId' => 'adm-hoekstra',
				'customerId' => 'cust-zwaan',
				'invoiceIds' => ['ar-0266', 'ar-0231'],
				'instalmentCount' => 6,
				'frequency' => 'monthly',
				'firstDueDate' => '2026-11-01',
				'graceDays' => 14,
				'agreedWith' => 'J. de Zwaan',
			],
			'bookkeeper'
		);
		return $service->activate((string)$draft['plan']['id'], 'bookkeeper');

	}//end activeZwaanPlan()

	/**
	 * A stored object by schema and id.
	 *
	 * @param string $schema The schema.
	 * @param string $id     The id.
	 *
	 * @return array<string,mixed>
	 */
	private function stored(string $schema, string $id): array {
		return $this->store->find($id, schema: $schema)->getObject();

	}//end stored()

	/**
	 * Every stored object of a schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function all(string $schema): array {
		return $this->store->setSchema($schema)->findAll();

	}//end all()
}//end trait
