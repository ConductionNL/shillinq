<?php

/**
 * Direct Debit Batch Service
 *
 * Proposes a direct debit run for a collection date: one collection per open
 * invoice paid by direct debit whose customer has an active mandate, grouped
 * in a draft batch per scheme and sequence type. It writes drafts only: the
 * pre-notification and submission-window guards stay on the collections'
 * submit transition.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Receivables
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-sepa-direct-debit/specs/bookkeeping-sepa-direct-debit/spec.md#requirement-a-batch-is-proposed-from-open-invoices-with-a-mandate-req-sdd-011
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Receivables;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Selects the open direct debit invoices into draft collections and batches.
 *
 * @spec openspec/changes/receivables-sepa-direct-debit/specs/bookkeeping-sepa-direct-debit/spec.md#requirement-a-batch-is-proposed-from-open-invoices-with-a-mandate-req-sdd-011
 */
class DirectDebitBatchService {

	public const REASON_NO_MANDATE = 'no-mandate';
	public const REASON_MANDATE_INACTIVE = 'mandate-inactive';
	public const REASON_MANDATE_ENDED = 'mandate-ended';
	public const REASON_ALREADY_COLLECTED = 'already-collected';
	public const REASON_CURRENCY = 'not-euro';
	public const REASON_NOTHING_DUE = 'nothing-due';

	/**
	 * The `paymentMethod` of an invoice paid by direct debit.
	 *
	 * @var string
	 */
	public const PAYMENT_METHOD = 'direct_debit';

	/**
	 * Invoice states that still wait for their money.
	 *
	 * @var list<string>
	 */
	private const OPEN_STATES = ['issued', 'overdue'];

	/**
	 * Collection states that hold an invoice: a new collection would collect it twice.
	 *
	 * @var list<string>
	 */
	private const OCCUPYING_STATES = ['scheduled', 'submitted', 'accepted_by_bank', 'presented', 'succeeded'];

	/**
	 * Collection states after which a recurring mandate has been used.
	 *
	 * @var list<string>
	 */
	private const USED_STATES = ['submitted', 'accepted_by_bank', 'presented', 'succeeded', 'refunded'];

	/**
	 * The most records read of one schema for one administration.
	 *
	 * @var int
	 */
	private const READ_LIMIT = 5000;

	/**
	 * The longest identifier a pain.008 message carries (Max35Text).
	 *
	 * @var int
	 */
	private const MAX_ID = 35;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService    OpenRegister's object service.
	 * @param RegisterLocation       $registerLocation Where the register lives.
	 * @param ITimeFactory           $time             The clock, for the batch's creation time.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocation $registerLocation,
		private readonly ITimeFactory $time,
	) {

	}//end __construct()

	/**
	 * Draft the collections and batches for one collection date.
	 *
	 * @param string $administrationId The administration.
	 * @param string $collectionDate   The requested collection date (Y-m-d).
	 *
	 * @return array{batches: list<array<string, mixed>>, skipped: list<array<string, string>>} The draft batches, each
	 *         with its `collections`, and every invoice left out with its reason.
	 *
	 * @spec openspec/changes/receivables-sepa-direct-debit/tasks.md#task-1.1
	 */
	public function propose(string $administrationId, string $collectionDate): array {
		$mandates = $this->byId(rows: $this->read(schema: 'SepaMandate', administrationId: $administrationId));
		$customers = $this->read(schema: 'CustomerMaster', administrationId: $administrationId);
		$collections = $this->read(schema: 'DirectDebitCollection', administrationId: $administrationId);
		$occupied = $this->invoicesIn(collections: $collections, states: self::OCCUPYING_STATES);
		$used = $this->mandatesIn(collections: $collections, states: self::USED_STATES);

		$groups = [];
		$skipped = [];
		foreach ($this->openInvoices(administrationId: $administrationId) as $invoice) {
			$outcome = $this->collectionFor(
				invoice: $invoice,
				mandate: $this->mandateFor(invoice: $invoice, mandates: $mandates, customers: $customers),
				occupied: $occupied,
				used: $used,
				collectionDate: $collectionDate
			);
			if (isset($outcome['reason']) === true) {
				$skipped[] = $outcome;
				continue;
			}

			$scheme = $outcome['scheme'];
			unset($outcome['scheme']);
			$groups[$scheme . '-' . $outcome['sequenceType']][] = $outcome;
			// A first collection uses the mandate: the next invoice on it in this run is recurring.
			$used[$outcome['mandateId']] = true;
		}

		ksort($groups);
		$batches = [];
		foreach ($groups as $key => $lines) {
			[$scheme, $sequenceType] = explode('-', $key, 2);
			$batches[] = $this->persistBatch(
				administrationId: $administrationId,
				collectionDate: $collectionDate,
				scheme: $scheme,
				sequenceType: $sequenceType,
				lines: $lines
			);
		}

		return ['batches' => $batches, 'skipped' => $skipped];

	}//end propose()

	/**
	 * The collection for one invoice, or the reason it is left out.
	 *
	 * @param array<string, mixed>      $invoice        The ARInvoice.
	 * @param array<string, mixed>|null $mandate        Its mandate, if one is named.
	 * @param array<string, true>       $occupied       Invoice ids already on a live collection.
	 * @param array<string, true>       $used           Mandate ids already used for a collection.
	 * @param string                    $collectionDate The requested collection date.
	 *
	 * @return array<string, mixed>
	 */
	private function collectionFor(array $invoice, ?array $mandate, array $occupied, array $used, string $collectionDate): array {
		$invoiceId = (string)($invoice['id'] ?? '');
		$number = (string)($invoice['invoiceNumber'] ?? $invoiceId);
		$skip = ['invoiceId' => $invoiceId, 'invoiceNumber' => $number, 'detail' => ''];

		if (isset($occupied[$invoiceId]) === true) {
			return ['reason' => self::REASON_ALREADY_COLLECTED] + $skip;
		}

		if (strtoupper((string)($invoice['currency'] ?? 'EUR')) !== 'EUR') {
			return ['reason' => self::REASON_CURRENCY] + $skip;
		}

		$amount = $this->amountDue(invoice: $invoice);
		if ($amount <= 0.0) {
			return ['reason' => self::REASON_NOTHING_DUE] + $skip;
		}

		if ($mandate === null) {
			return ['reason' => self::REASON_NO_MANDATE] + $skip;
		}

		$status = (string)($mandate['status'] ?? '');
		if ($status !== 'active') {
			return ['reason' => self::REASON_MANDATE_INACTIVE, 'detail' => $status] + $skip;
		}

		$last = (string)($mandate['lastCollectionDate'] ?? '');
		if ($last !== '' && $last < $collectionDate) {
			return ['reason' => self::REASON_MANDATE_ENDED, 'detail' => $last] + $skip;
		}

		$mandateId = (string)$mandate['id'];

		return [
			'mandateId' => $mandateId,
			'invoiceId' => $invoiceId,
			'amount' => $amount,
			'currency' => 'EUR',
			'sequenceType' => $this->sequenceType(mandate: $mandate, used: isset($used[$mandateId])),
			'requestedCollectionDate' => $collectionDate,
			'endToEndId' => substr('DD-' . $number, 0, self::MAX_ID),
			'status' => 'scheduled',
			'scheme' => (string)($mandate['scheme'] ?? 'CORE'),
		];

	}//end collectionFor()

	/**
	 * The sequence type of the next collection on a mandate.
	 *
	 * @param array<string, mixed> $mandate The mandate.
	 * @param bool                 $used    Whether a collection already used it.
	 *
	 * @return string OOFF for a one-off mandate, FRST for the first collection on a recurring one, else RCUR.
	 */
	private function sequenceType(array $mandate, bool $used): string {
		if ((string)($mandate['type'] ?? '') === 'oneoff') {
			return 'OOFF';
		}

		if ($used === true || trim((string)($mandate['lastUsedAt'] ?? '')) !== '') {
			return 'RCUR';
		}

		return 'FRST';

	}//end sequenceType()

	/**
	 * The invoice's own mandate, else its customer's default mandate.
	 *
	 * @param array<string, mixed>                $invoice   The ARInvoice.
	 * @param array<string, array<string, mixed>> $mandates  The mandates by id.
	 * @param list<array<string, mixed>>          $customers The administration's customers.
	 *
	 * @return array<string, mixed>|null
	 */
	private function mandateFor(array $invoice, array $mandates, array $customers): ?array {
		$mandateId = trim((string)($invoice['directDebitMandateId'] ?? ''));
		if ($mandateId === '') {
			$customerId = trim((string)($invoice['customerId'] ?? ''));
			foreach ($customers as $customer) {
				if ($customerId !== '' && ((string)($customer['id'] ?? '') === $customerId || (string)($customer['customerId'] ?? '') === $customerId)) {
					$mandateId = trim((string)($customer['defaultMandateId'] ?? ''));
					break;
				}
			}
		}

		if ($mandateId === '') {
			return null;
		}

		return ($mandates[$mandateId] ?? null);

	}//end mandateFor()

	/**
	 * What is still to be collected on an invoice.
	 *
	 * @param array<string, mixed> $invoice The ARInvoice.
	 *
	 * @return float The amount due, rounded to cents.
	 */
	private function amountDue(array $invoice): float {
		if (is_numeric($invoice['amountDue'] ?? null) === true) {
			return round((float)$invoice['amountDue'], 2);
		}

		return round((float)($invoice['grossAmount'] ?? 0) - (float)($invoice['paidAmount'] ?? 0), 2);

	}//end amountDue()

	/**
	 * Save one draft batch and its collections, the collections pointing at the batch.
	 *
	 * @param string                     $administrationId The administration.
	 * @param string                     $collectionDate   The requested collection date.
	 * @param string                     $scheme           CORE or B2B.
	 * @param string                     $sequenceType     FRST, RCUR or OOFF.
	 * @param list<array<string, mixed>> $lines            The collections.
	 *
	 * @return array<string, mixed> The saved batch with its `collections`.
	 */
	private function persistBatch(
		string $administrationId,
		string $collectionDate,
		string $scheme,
		string $sequenceType,
		array $lines
	): array {
		$now = $this->time->getDateTime();
		$batch = [
			'messageId' => substr('DD-' . $now->format('YmdHis') . '-' . $scheme . '-' . $sequenceType, 0, self::MAX_ID),
			'creationDateTime' => $now->format('Y-m-d\TH:i:sP'),
			'requestedCollectionDate' => $collectionDate,
			'scheme' => $scheme,
			'sequenceType' => $sequenceType,
			'collectionCount' => count($lines),
			'controlSum' => round(array_sum(array_column($lines, 'amount')), 2),
			'status' => 'draft',
			'administrationId' => $administrationId,
		];
		$saved = $this->save(schema: 'DirectDebitBatch', object: $batch);

		$collections = [];
		foreach ($lines as $line) {
			$line['submittedInBatchId'] = (string)($saved['id'] ?? '');
			$line['administrationId'] = $administrationId;
			$collections[] = $this->save(schema: 'DirectDebitCollection', object: $line);
		}

		return $saved + ['collections' => $collections];

	}//end persistBatch()

	/**
	 * Save one object; the saved record with its id, else the object itself.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $object The object.
	 *
	 * @return array<string, mixed>
	 */
	private function save(string $schema, array $object): array {
		$saved = $this->scoped(schema: $schema)->saveObject(object: $object);

		return (ObjectIdentifier::recordWithId(candidate: $saved) ?? $object);

	}//end save()

	/**
	 * Open invoices paid by direct debit, oldest due date first.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function openInvoices(string $administrationId): array {
		$open = [];
		foreach ($this->read(schema: 'ARInvoice', administrationId: $administrationId) as $invoice) {
			if ((string)($invoice['paymentMethod'] ?? '') !== self::PAYMENT_METHOD) {
				continue;
			}

			if (in_array((string)($invoice['lifecycleState'] ?? ''), self::OPEN_STATES, true) === true) {
				$open[] = $invoice;
			}
		}

		usort(
			$open,
			static fn (array $a, array $b): int => [(string)($a['dueDate'] ?? ''), (string)($a['invoiceNumber'] ?? '')]
				<=> [(string)($b['dueDate'] ?? ''), (string)($b['invoiceNumber'] ?? '')]
		);

		return $open;

	}//end openInvoices()

	/**
	 * Invoice ids of collections in one of the given states.
	 *
	 * @param list<array<string, mixed>> $collections The administration's collections.
	 * @param list<string>               $states      The states that count.
	 *
	 * @return array<string, true>
	 */
	private function invoicesIn(array $collections, array $states): array {
		$ids = [];
		foreach ($collections as $collection) {
			$invoiceId = trim((string)($collection['invoiceId'] ?? ''));
			if ($invoiceId !== '' && in_array((string)($collection['status'] ?? ''), $states, true) === true) {
				$ids[$invoiceId] = true;
			}
		}

		return $ids;

	}//end invoicesIn()

	/**
	 * Mandate ids of collections in one of the given states.
	 *
	 * @param list<array<string, mixed>> $collections The administration's collections.
	 * @param list<string>               $states      The states that count.
	 *
	 * @return array<string, true>
	 */
	private function mandatesIn(array $collections, array $states): array {
		$ids = [];
		foreach ($collections as $collection) {
			$mandateId = trim((string)($collection['mandateId'] ?? ''));
			if ($mandateId !== '' && in_array((string)($collection['status'] ?? ''), $states, true) === true) {
				$ids[$mandateId] = true;
			}
		}

		return $ids;

	}//end mandatesIn()

	/**
	 * Rows keyed by their id.
	 *
	 * @param list<array<string, mixed>> $rows The rows.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function byId(array $rows): array {
		$keyed = [];
		foreach ($rows as $row) {
			$id = (string)($row['id'] ?? '');
			if ($id !== '') {
				$keyed[$id] = $row;
			}
		}

		return $keyed;

	}//end byId()

	/**
	 * Every record of a schema for the administration, with its id.
	 *
	 * @param string $schema           The schema slug.
	 * @param string $administrationId The administration.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function read(string $schema, string $administrationId): array {
		$rows = $this->scoped(schema: $schema)->findAll(['filters' => ['administrationId' => $administrationId], 'limit' => self::READ_LIMIT]);
		$records = [];
		foreach ($rows as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			if ($record !== null) {
				$records[] = $record;
			}
		}

		return $records;

	}//end read()

	/**
	 * The object service scoped to one schema of the shillinq register.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectServiceInterface
	 */
	private function scoped(string $schema): ObjectServiceInterface {
		return $this->objectService->setRegister($this->registerLocation->getRegisterSlug())->setSchema($schema);

	}//end scoped()
}//end class
