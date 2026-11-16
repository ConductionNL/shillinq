<?php

/**
 * Unit tests for InvoiceBatchSendService.
 *
 * REQ-SIS-003: selected invoices are sent in a queued batch, one outcome per
 * invoice; an invoice already sent is skipped unless the batch resends.
 * OpenRegister is doubled at the object-service boundary, the single send
 * at InvoiceSendingService.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-invoice-sending/specs/bookkeeping-accounts-receivable-core/spec.md#requirement-selected-invoices-are-sent-in-one-action-req-sis-003
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DomainException;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\BackgroundJob\SendInvoicesJob;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\InvoiceBatchSendService;
use OCA\Shillinq\Service\InvoiceSendingService;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IL10N;
use OutOfBoundsException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Twelve invoices of September in the scenario are three here: one already sent.
 */
class InvoiceBatchSendServiceTest extends TestCase {

	/**
	 * Stored objects by schema and id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * Saved objects, in order, as [schema, data].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $saved = [];

	private InvoiceSendingService&MockObject $sending;

	private IJobList&MockObject $jobList;

	/**
	 * The service over an in-memory object service.
	 *
	 * @return InvoiceBatchSendService
	 */
	private function service(): InvoiceBatchSendService {
		$schema = '';
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnCallback(
			function (string|int $value) use (&$schema, $objects): ObjectServiceInterface {
				$schema = (string)$value;
				return $objects;
			}
		);
		$objects->method('find')->willReturnCallback(
			function (int|string $id) use (&$schema): ?ObjectEntityInterface {
				$row = ($this->store[$schema][(string)$id] ?? null);
				if ($row === null) {
					throw new RuntimeException('not found');
				}

				return $this->entity(row: $row);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object) use (&$schema): ObjectEntityInterface {
				$object['id'] = ($object['id'] ?? ($schema . '-' . count($this->saved)));
				$this->saved[] = [$schema, $object];
				$this->store[$schema][$object['id']] = $object;
				return $this->entity(row: $object);
			}
		);

		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(static fn (string $id): bool => $id === 'adm-1');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new InvoiceBatchSendService($objects, $context, $this->sending, $this->jobList, $appConfig, $l10n, new NullLogger());
	}//end service()

	/**
	 * An entity double over a row.
	 *
	 * @param array<string, mixed> $row The object.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(array $row): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getObject')->willReturn($row);
		$entity->method('jsonSerialize')->willReturn($row);
		return $entity;
	}//end entity()

	protected function setUp(): void {
		$this->sending = $this->createMock(InvoiceSendingService::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->store['ARInvoice'] = [
			'inv-1' => ['id' => 'inv-1', 'administrationId' => 'adm-1', 'lifecycleState' => 'issued'],
			'inv-2' => ['id' => 'inv-2', 'administrationId' => 'adm-1', 'lifecycleState' => 'issued', 'sending' => ['sentAt' => '2026-09-30T10:00:00Z']],
			'inv-3' => ['id' => 'inv-3', 'administrationId' => 'adm-1', 'lifecycleState' => 'issued'],
			'inv-other' => ['id' => 'inv-other', 'administrationId' => 'adm-2', 'lifecycleState' => 'issued'],
		];
	}//end setUp()

	/**
	 * A queued send batch, stored as the job would find it.
	 *
	 * @param array<int, string> $invoiceIds The invoices.
	 * @param bool               $resend     Whether to resend.
	 *
	 * @return string The batch id.
	 */
	private function givenABatch(array $invoiceIds, bool $resend = false): string {
		$this->store['InvoiceBatch']['batch-1'] = ['id' => 'batch-1', 'batchId' => 'SEND-1', 'kind' => 'send', 'administrationId' => 'adm-1', 'invoiceIds' => $invoiceIds, 'resend' => $resend, 'outcomes' => [], 'state' => 'queued'];
		return 'batch-1';
	}//end givenABatch()

	/**
	 * The statuses of the batch's outcomes, by invoice.
	 *
	 * @param array<string, mixed> $batch The batch.
	 *
	 * @return array<string, string>
	 */
	private static function statuses(array $batch): array {
		return array_column($batch['outcomes'], 'status', 'invoiceId');
	}//end statuses()

	/**
	 * Queueing records the batch and queues its job with the batch's id.
	 *
	 * @return void
	 */
	public function testQueueRecordsTheBatchAndQueuesTheJob(): void {
		$this->jobList->expects($this->once())->method('add')->with(SendInvoicesJob::class, ['batchId' => 'InvoiceBatch-0']);

		$batch = $this->service()->queue(administrationId: 'adm-1', invoiceIds: [' inv-1 ', 'inv-2', 'inv-1', '', ['x']], options: ['resend' => true, 'requestedBy' => 'bookkeeper']);

		$this->assertSame('queued', $batch['state']);
		$this->assertSame('send', $batch['kind']);
		$this->assertSame(['inv-1', 'inv-2'], $batch['invoiceIds']);
		$this->assertTrue($batch['resend']);
		$this->assertSame('bookkeeper', $batch['requestedBy']);
		$this->assertStringStartsWith('SEND-', $batch['batchId']);
	}//end testQueueRecordsTheBatchAndQueuesTheJob()

	/**
	 * Another administration is not found; no or too many invoices are refused; nothing is queued.
	 *
	 * @return void
	 */
	public function testQueueRefusesBeforeWritingAnything(): void {
		$this->jobList->expects($this->never())->method('add');
		$service = $this->service();

		try {
			$service->queue(administrationId: 'adm-2', invoiceIds: ['inv-other']);
			$this->fail('another administration was queued');
		} catch (OutOfBoundsException $e) {
			$this->assertSame('Administration not found', $e->getMessage());
		}

		foreach ([[], array_map(static fn (int $i): string => 'inv-' . $i, range(1, 201))] as $ids) {
			try {
				$service->queue(administrationId: 'adm-1', invoiceIds: $ids);
				$this->fail('the selection was queued');
			} catch (InvalidArgumentException $e) {
				$this->assertNotSame('', $e->getMessage());
			}
		}

		$this->assertSame([], $this->saved);
	}//end testQueueRefusesBeforeWritingAnything()

	/**
	 * The scenario: the already sent invoice is skipped, the others are sent, the batch is done.
	 *
	 * @return void
	 */
	public function testAnAlreadySentInvoiceIsSkippedAndTheRestAreSent(): void {
		$this->sending->expects($this->exactly(2))->method('sendUnattended')
			->willReturnCallback(static fn (string $administrationId, string $invoiceId): array => ['status' => 'sent', 'sending' => ['sentVia' => 'email', 'note' => ''], 'error' => '']);

		$batch = $this->service()->run(batchId: $this->givenABatch(invoiceIds: ['inv-1', 'inv-2', 'inv-3']));

		$this->assertSame('done', $batch['state']);
		$this->assertSame(['inv-1' => 'sent', 'inv-2' => 'skipped', 'inv-3' => 'sent'], self::statuses($batch));
		$this->assertSame('The invoice was already sent.', $batch['outcomes'][1]['reason']);
		$this->assertSame(['running', 'done'], array_map(static fn (array $save): string => $save[1]['state'], $this->saved));
	}//end testAnAlreadySentInvoiceIsSkippedAndTheRestAreSent()

	/**
	 * With resend, the already sent invoice goes again.
	 *
	 * @return void
	 */
	public function testResendSendsAnAlreadySentInvoice(): void {
		$this->sending->expects($this->once())->method('sendUnattended')->with('adm-1', 'inv-2')
			->willReturn(['status' => 'sent', 'sending' => ['sentVia' => 'email'], 'error' => '']);

		$batch = $this->service()->run(batchId: $this->givenABatch(invoiceIds: ['inv-2'], resend: true));

		$this->assertSame(['inv-2' => 'sent'], self::statuses($batch));
	}//end testResendSendsAnAlreadySentInvoice()

	/**
	 * A failed mail is recorded with its error; the batch goes on to the next invoice.
	 *
	 * @return void
	 */
	public function testAFailedMailInsideABatchIsRecordedAndTheBatchGoesOn(): void {
		$this->sending->method('sendUnattended')->willReturnCallback(
			static function (string $administrationId, string $invoiceId): array {
				if ($invoiceId === 'inv-1') {
					return ['status' => 'failed', 'sending' => ['triedVia' => 'email'], 'error' => 'The customer has no email address to send the invoice to.'];
				}

				if ($invoiceId === 'inv-3') {
					throw new RuntimeException('smtp down');
				}

				return ['status' => 'recorded', 'sending' => ['sentVia' => 'post', 'note' => 'The letter is printed and posted by hand.'], 'error' => ''];
			}
		);
		$this->store['ARInvoice']['inv-4'] = ['id' => 'inv-4', 'administrationId' => 'adm-1', 'lifecycleState' => 'issued'];

		$batch = $this->service()->run(batchId: $this->givenABatch(invoiceIds: ['inv-1', 'inv-3', 'inv-4']));

		$this->assertSame(['inv-1' => 'failed', 'inv-3' => 'failed', 'inv-4' => 'sent'], self::statuses($batch));
		$this->assertSame('The customer has no email address to send the invoice to.', $batch['outcomes'][0]['reason']);
		$this->assertSame('The invoice could not be sent.', $batch['outcomes'][1]['reason']);
		$this->assertSame('The letter is printed and posted by hand.', $batch['outcomes'][2]['reason']);
	}//end testAFailedMailInsideABatchIsRecordedAndTheBatchGoesOn()

	/**
	 * Another administration's, a missing and a draft invoice are skipped with the reason.
	 *
	 * @return void
	 */
	public function testInvoicesThatCannotBeSentAreSkipped(): void {
		$this->store['ARInvoice']['inv-draft'] = ['id' => 'inv-draft', 'administrationId' => 'adm-1', 'lifecycleState' => 'draft'];
		$this->sending->expects($this->once())->method('sendUnattended')->with('adm-1', 'inv-draft')
			->willThrowException(new DomainException('Only an issued invoice can be sent.'));

		$batch = $this->service()->run(batchId: $this->givenABatch(invoiceIds: ['inv-other', 'inv-missing', 'inv-draft']));

		$this->assertSame(['inv-other' => 'skipped', 'inv-missing' => 'skipped', 'inv-draft' => 'skipped'], self::statuses($batch));
		$this->assertSame('The invoice was not found in this administration.', $batch['outcomes'][0]['reason']);
		$this->assertSame('Only an issued invoice can be sent.', $batch['outcomes'][2]['reason']);
	}//end testInvoicesThatCannotBeSentAreSkipped()

	/**
	 * A batch that is done runs again as itself, and an unknown batch is [].
	 *
	 * @return void
	 */
	public function testADoneOrMissingBatchSendsNothing(): void {
		$this->sending->expects($this->never())->method('sendUnattended');
		$this->givenABatch(invoiceIds: ['inv-1']);
		$this->store['InvoiceBatch']['batch-1']['state'] = 'done';

		$service = $this->service();
		$this->assertSame('done', $service->run(batchId: 'batch-1')['state']);
		$this->assertSame([], $service->run(batchId: 'batch-missing'));
		$this->assertSame([], $this->saved);
	}//end testADoneOrMissingBatchSendsNothing()
}//end class
