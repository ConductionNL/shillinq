<?php

/**
 * Shillinq Invoice Batch Send Service
 *
 * Sends a selection of issued invoices in one action (REQ-SIS-003). queue()
 * checks the administration, records an InvoiceBatch of kind `send` and
 * queues SendInvoicesJob; run() is what the job does: each invoice goes out
 * through InvoiceSendingService by its customer's delivery method, and the
 * batch records one outcome per invoice (sent, skipped with a reason, or
 * failed with the error). An invoice that already carries `sending.sentAt`
 * is skipped unless the batch asks to resend. The batch ends in state
 * `done`, which notifies the bookkeeper who asked for it.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
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
 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DomainException;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\BackgroundJob\SendInvoicesJob;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IL10N;
use OutOfBoundsException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Queues and runs a batch send of issued invoices.
 *
 * @spec openspec/changes/sales-invoice-sending/specs/bookkeeping-accounts-receivable-core/spec.md#requirement-selected-invoices-are-sent-in-one-action-req-sis-003
 */
class InvoiceBatchSendService {

	/**
	 * The most invoices one batch sends.
	 */
	public const MAX_INVOICES = 200;

	/**
	 * The invoice went out (or its delivery method was recorded).
	 */
	public const OUTCOME_SENT = 'sent';

	/**
	 * The invoice was not sent, for the reason given.
	 */
	public const OUTCOME_SKIPPED = 'skipped';

	/**
	 * The send failed, with the error.
	 */
	public const OUTCOME_FAILED = 'failed';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface       $objectService         OpenRegister's object service.
	 * @param AdministrationContextService $administrationContext Whether the user may act on the administration.
	 * @param InvoiceSendingService        $sending               Sends one invoice.
	 * @param IJobList                     $jobList               Queues the job.
	 * @param IAppConfig                   $appConfig             The register slug.
	 * @param IL10N                        $l10n                  The reasons.
	 * @param LoggerInterface              $logger                A batch that could not be written.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly AdministrationContextService $administrationContext,
		private readonly InvoiceSendingService $sending,
		private readonly IJobList $jobList,
		private readonly IAppConfig $appConfig,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record a send batch and queue its job.
	 *
	 * @param string            $administrationId The administration the invoices belong to.
	 * @param array<int, mixed> $invoiceIds       The selected ARInvoice ids, 1 to 200.
	 * @param array{resend?: bool, requestedBy?: string} $options Whether already sent invoices go again, and who asked.
	 *
	 * @return array<string, mixed> The InvoiceBatch as stored, state `queued`.
	 *
	 * @throws OutOfBoundsException     When the user may not act on the administration (shown as not found).
	 * @throws InvalidArgumentException When no invoice or more than 200 are selected.
	 *
	 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.2
	 */
	public function queue(string $administrationId, array $invoiceIds, array $options = []): array {
		if ($administrationId === '' || $this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			throw new OutOfBoundsException('Administration not found');
		}

		$ids = self::invoiceIds(invoiceIds: $invoiceIds);
		if ($ids === []) {
			throw new InvalidArgumentException('Select at least one invoice.');
		}

		if (count($ids) > self::MAX_INVOICES) {
			throw new InvalidArgumentException('A batch sends at most 200 invoices.');
		}

		$batch = $this->save(
			batch: [
				'batchId' => 'SEND-' . bin2hex(random_bytes(6)),
				'kind' => 'send',
				'administrationId' => $administrationId,
				'requestedBy' => (string)($options['requestedBy'] ?? ''),
				'invoiceIds' => $ids,
				'resend' => (($options['resend'] ?? false) === true),
				'outcomes' => [],
				'state' => 'queued',
			]
		);
		$this->jobList->add(SendInvoicesJob::class, ['batchId' => ObjectIdentifier::resolve(saved: $batch)]);

		return $batch;
	}//end queue()

	/**
	 * Send every invoice of a queued batch and record the outcomes.
	 *
	 * The job calls this with no user signed in; the administration was
	 * checked when the batch was queued, and every invoice must belong to it.
	 * A batch that is already done is returned as it is.
	 *
	 * @param string $batchId The InvoiceBatch object id.
	 *
	 * @return array<string, mixed> The batch as stored, or [] when it does not exist.
	 *
	 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.1
	 */
	public function run(string $batchId): array {
		$batch = $this->find(schema: 'InvoiceBatch', id: $batchId);
		if ($batch === null || (string)($batch['kind'] ?? '') !== 'send') {
			return [];
		}

		if ((string)($batch['state'] ?? '') === 'done') {
			return $batch;
		}

		$batch['state'] = 'running';
		$batch = $this->save(batch: $batch);

		$outcomes = [];
		foreach (self::invoiceIds(invoiceIds: (array)($batch['invoiceIds'] ?? [])) as $invoiceId) {
			$outcomes[] = $this->sendOne(
				administrationId: (string)($batch['administrationId'] ?? ''),
				invoiceId: $invoiceId,
				resend: (($batch['resend'] ?? false) === true)
			);
		}

		$batch['outcomes'] = $outcomes;
		$batch['state'] = 'done';

		return $this->save(batch: $batch);
	}//end run()

	/**
	 * The selected ids as trimmed, unique, non-empty strings, in order.
	 *
	 * @param array<int, mixed> $invoiceIds The ids as given.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.2
	 */
	public static function invoiceIds(array $invoiceIds): array {
		$ids = [];
		foreach ($invoiceIds as $id) {
			if (is_string($id) === false && is_int($id) === false) {
				continue;
			}

			$id = trim((string)$id);
			if ($id !== '' && in_array($id, $ids, true) === false) {
				$ids[] = $id;
			}
		}

		return $ids;
	}//end invoiceIds()

	/**
	 * Send one invoice of the batch and say how it went.
	 *
	 * @param string $administrationId The batch's administration.
	 * @param string $invoiceId        The ARInvoice id.
	 * @param bool   $resend           Whether an already sent invoice goes again.
	 *
	 * @return array{invoiceId: string, status: string, reason: string}
	 */
	private function sendOne(string $administrationId, string $invoiceId, bool $resend): array {
		$invoice = $this->find(schema: 'ARInvoice', id: $invoiceId);
		if ($invoice === null || (string)($invoice['administrationId'] ?? '') !== $administrationId) {
			return $this->skipped(invoiceId: $invoiceId, reason: 'The invoice was not found in this administration.');
		}

		if ($resend === false && (string)(((array)($invoice['sending'] ?? []))['sentAt'] ?? '') !== '') {
			return $this->skipped(invoiceId: $invoiceId, reason: 'The invoice was already sent.');
		}

		try {
			$result = $this->sending->sendUnattended(administrationId: $administrationId, invoiceId: $invoiceId);
		} catch (OutOfBoundsException $e) {
			return $this->skipped(invoiceId: $invoiceId, reason: 'The invoice was not found in this administration.');
		} catch (DomainException $e) {
			return $this->skipped(invoiceId: $invoiceId, reason: 'Only an issued invoice can be sent.');
		} catch (Throwable $e) {
			$this->logger->warning('InvoiceBatchSendService: invoice not sent', ['invoiceId' => $invoiceId, 'exception' => $e->getMessage()]);
			return self::outcome(invoiceId: $invoiceId, status: self::OUTCOME_FAILED, reason: $this->l10n->t('The invoice could not be sent.'));
		}

		if ($result['status'] === InvoiceSendingService::STATUS_FAILED) {
			return self::outcome(invoiceId: $invoiceId, status: self::OUTCOME_FAILED, reason: $result['error']);
		}

		return self::outcome(invoiceId: $invoiceId, status: self::OUTCOME_SENT, reason: (string)($result['sending']['note'] ?? ''));
	}//end sendOne()

	/**
	 * A skipped outcome with its reason, translated.
	 *
	 * @param string $invoiceId The invoice.
	 * @param string $reason    The English reason, a catalogue key.
	 *
	 * @return array{invoiceId: string, status: string, reason: string}
	 */
	private function skipped(string $invoiceId, string $reason): array {
		return self::outcome(invoiceId: $invoiceId, status: self::OUTCOME_SKIPPED, reason: $this->l10n->t($reason));
	}//end skipped()

	/**
	 * One outcome row.
	 *
	 * @param string $invoiceId The invoice.
	 * @param string $status    sent, skipped or failed.
	 * @param string $reason    Why, or what the user should know.
	 *
	 * @return array{invoiceId: string, status: string, reason: string}
	 */
	private static function outcome(string $invoiceId, string $status, string $reason): array {
		return ['invoiceId' => $invoiceId, 'status' => $status, 'reason' => $reason];
	}//end outcome()

	/**
	 * One record by id.
	 *
	 * @param string $schema The schema.
	 * @param string $id     The id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function find(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$scoped = $this->objectService->setRegister($this->register())->setSchema($schema);
			return ObjectIdentifier::findOne(scoped: $scoped, id: $id);
		} catch (Throwable $e) {
			return null;
		}
	}//end find()

	/**
	 * Save the batch and return it as stored.
	 *
	 * @param array<string, mixed> $batch The InvoiceBatch.
	 *
	 * @return array<string, mixed>
	 */
	private function save(array $batch): array {
		$uuid = null;
		$id = (string)($batch['id'] ?? ($batch['@self']['id'] ?? ''));
		if ($id !== '') {
			$uuid = $id;
		}

		$saved = $this->objectService->setRegister($this->register())->setSchema('InvoiceBatch')->saveObject(object: $batch, uuid: $uuid);
		$out = $saved->jsonSerialize();
		if (is_array($out) === false) {
			return $batch;
		}

		return $out;
	}//end save()

	/**
	 * The register slug.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
