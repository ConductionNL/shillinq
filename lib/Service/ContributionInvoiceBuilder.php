<?php

/**
 * Contribution Invoice Builder
 *
 * The shape half of a school contribution raise, with no reads and no writes.
 * It checks a raise call whole before anything is written, and builds the
 * ARInvoice and the PaymentRequest one recipient becomes. Keeping it free of
 * OpenRegister lets the exact payload a guardian is billed with be tested on
 * its own, including the sentence the Wet vrijwillige ouderbijdrage asks for.
 *
 * Nothing here reads the owning app's schema. The caller passes the reference,
 * the text, the amount and the voluntary flag; shillinq stamps the reference.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-001)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCP\L10N\IFactory;

/**
 * Validates a raise call and builds the invoice and request per recipient.
 *
 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-001)
 */
final class ContributionInvoiceBuilder {
	/**
	 * What a school contribution can be for.
	 *
	 * @var array<int, string>
	 */
	public const KINDS = ['parental-contribution', 'lunch-supervision', 'school-trip', 'activity', 'other'];

	/**
	 * The most recipients one call may carry. The owning app chunks above it;
	 * the raise is idempotent per child, so a retried chunk bills nobody twice.
	 *
	 * @var integer
	 */
	public const MAX_RECIPIENTS = 200;

	/**
	 * The parts of the chargeable that must be present.
	 *
	 * @var array<int, string>
	 */
	public const CHARGEABLE_PARTS = ['app', 'register', 'schema', 'id'];

	/**
	 * The request type every raised request carries.
	 *
	 * @var string
	 */
	public const REQUEST_TYPE = 'contribution';

	/**
	 * Days between the invoice date and the due date when none is given.
	 *
	 * @var integer
	 */
	private const DEFAULT_TERM_DAYS = 30;

	/**
	 * Constructor.
	 *
	 * @param IFactory $l10nFactory Nextcloud's translation factory, for the
	 *                              invoice text in the guardian's language.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IFactory $l10nFactory,
	) {
	}//end __construct()

	/**
	 * Check a raise call whole and return it normalised.
	 *
	 * A call that fails here writes nothing: a missing part of the chargeable or
	 * an amount of zero is a mistake in the caller, not in one guardian's row.
	 *
	 * @param array<string, mixed> $payload The call as the caller sent it.
	 * @param string $today Today as Y-m-d, the default invoice date.
	 *
	 * @return array<string, mixed> The normalised charge, with `recipients`.
	 *
	 * @throws InvalidArgumentException When the call cannot be raised as a whole.
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-001)
	 */
	public function normaliseCharge(array $payload, string $today): array {
		$chargeable = $this->normaliseChargeable(chargeable: ($payload['chargeable'] ?? null));

		$kind = (string)($payload['kind'] ?? '');
		if (in_array($kind, self::KINDS, true) === false) {
			throw new InvalidArgumentException(
				sprintf('Unknown kind "%s"; expected one of %s.', $kind, implode(', ', self::KINDS))
			);
		}

		$description = trim((string)($payload['description'] ?? ''));
		if ($description === '') {
			throw new InvalidArgumentException('A contribution needs a description; it is the line on the invoice.');
		}

		$amount = $this->money(value: ($payload['amount'] ?? null));
		if ($amount === null) {
			throw new InvalidArgumentException('A contribution needs an amount above zero.');
		}

		if (is_bool($payload['voluntary'] ?? null) === false) {
			throw new InvalidArgumentException('Say whether the contribution is voluntary: voluntary is true or false.');
		}

		$administrationId = trim((string)($payload['administrationId'] ?? ''));
		if (preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $administrationId) !== 1) {
			throw new InvalidArgumentException('A contribution needs the administrationId of the school.');
		}

		$currency = strtoupper((string)($payload['currency'] ?? 'EUR'));
		if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
			throw new InvalidArgumentException('The currency is a three-letter ISO 4217 code.');
		}

		$invoiceDate = $this->date(value: (string)($payload['invoiceDate'] ?? $today), field: 'invoiceDate');
		$dueDate = $this->dueDate(payload: $payload, invoiceDate: $invoiceDate);

		return [
			'chargeable' => $chargeable,
			'kind' => $kind,
			'description' => $description,
			'amount' => $amount,
			'currency' => $currency,
			'voluntary' => $payload['voluntary'],
			'administrationId' => $administrationId,
			'invoiceDate' => $invoiceDate,
			'dueDate' => $dueDate,
			'revenueAccount' => trim((string)($payload['revenueAccount'] ?? '')),
			'language' => $this->language(value: (string)($payload['language'] ?? 'nl')),
			'recipients' => $this->recipients(value: ($payload['recipients'] ?? null)),
		];
	}//end normaliseCharge()

	/**
	 * The amount one recipient is billed: their own when given, else the charge's.
	 *
	 * @param array<string, mixed> $charge The normalised charge.
	 * @param array<string, mixed> $recipient One recipient.
	 *
	 * @return float The amount.
	 *
	 * @throws InvalidArgumentException When the recipient's own amount is not above zero.
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-001)
	 */
	public function amountFor(array $charge, array $recipient): float {
		if (array_key_exists('amount', $recipient) === false || $recipient['amount'] === null) {
			return (float)$charge['amount'];
		}

		$own = $this->money(value: $recipient['amount']);
		if ($own === null) {
			throw new InvalidArgumentException('This recipient carries an amount that is not above zero.');
		}

		return $own;
	}//end amountFor()

	/**
	 * Who the charge is for: the recipient's child, or the debtor's customer
	 * when the caller bills per household.
	 *
	 * @param array<string, mixed> $recipient One recipient.
	 * @param string $customerMasterId The debtor's resolved customer.
	 * @param string $registerSlug Shillinq's own register slug.
	 *
	 * @return array<string, string> The beneficiary reference.
	 *
	 * @throws InvalidArgumentException When a beneficiary is given without a type or an id.
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-003)
	 */
	public function beneficiaryFor(array $recipient, string $customerMasterId, string $registerSlug): array {
		$given = ($recipient['beneficiary'] ?? null);
		if ($given === null || $given === []) {
			return [
				'type' => 'customer',
				'register' => $registerSlug,
				'schema' => 'CustomerMaster',
				'id' => $customerMasterId,
			];
		}

		if (is_array($given) === false
			|| trim((string)($given['type'] ?? '')) === ''
			|| trim((string)($given['id'] ?? '')) === ''
		) {
			throw new InvalidArgumentException('A beneficiary names at least a type and an id.');
		}

		$beneficiary = [];
		foreach (['type', 'register', 'schema', 'id'] as $part) {
			$value = trim((string)($given[$part] ?? ''));
			if ($value !== '') {
				$beneficiary[$part] = $value;
			}
		}

		return $beneficiary;
	}//end beneficiaryFor()

	/**
	 * The issued invoice one recipient is billed with. No order stands behind it.
	 *
	 * @param array<string, mixed> $charge The normalised charge.
	 * @param float $amount The amount for this recipient.
	 * @param string $customerMasterId The debtor's customer.
	 * @param array<string, string> $beneficiary Who the charge is for.
	 * @param string $batchId The raise batch.
	 * @param int $sequence The recipient's number inside the batch, from 1.
	 *
	 * @return array<string, mixed> The ARInvoice payload.
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-001)
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-007)
	 */
	public function buildInvoice(
		array $charge,
		float $amount,
		string $customerMasterId,
		array $beneficiary,
		string $batchId,
		int $sequence,
	): array {
		$invoice = [
			'invoiceNumber' => $this->invoiceNumber(batchId: $batchId, sequence: $sequence, invoiceDate: (string)$charge['invoiceDate']),
			'administrationId' => (string)$charge['administrationId'],
			'periodId' => substr((string)$charge['invoiceDate'], 0, 7),
			'customerId' => $customerMasterId,
			'invoiceDate' => (string)$charge['invoiceDate'],
			'dueDate' => (string)$charge['dueDate'],
			'currency' => (string)$charge['currency'],
			'netAmount' => $amount,
			'vatAmount' => 0.0,
			'grossAmount' => $amount,
			'lifecycleState' => 'issued',
			'invoiceType' => 'standard',
			'lines' => [
				[
					'lineNumber' => 1,
					'description' => $this->describe(charge: $charge),
					'quantity' => 1,
					'unitPrice' => $amount,
					'vatRate' => 0,
					'glAccount' => (string)$charge['revenueAccount'],
				],
			],
			'contribution' => [
				'kind' => (string)$charge['kind'],
				'voluntary' => (bool)$charge['voluntary'],
				'chargeable' => $charge['chargeable'],
				'beneficiary' => $beneficiary,
				'raiseBatchId' => $batchId,
			],
		];

		if ((bool)$charge['voluntary'] === true) {
			$invoice['invoiceNote'] = $this->voluntaryNotice(language: (string)$charge['language']);
		}

		return $invoice;
	}//end buildInvoice()

	/**
	 * The payment request that stands on the chargeable and on the invoice.
	 *
	 * @param array<string, mixed> $charge The normalised charge.
	 * @param float $amount The amount for this recipient.
	 * @param string $customerMasterId The debtor's customer.
	 * @param array<string, string> $beneficiary Who the charge is for.
	 * @param string $invoiceId The raised invoice.
	 * @param string $batchId The raise batch.
	 *
	 * @return array<string, mixed> The PaymentRequest payload.
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-004)
	 */
	public function buildRequest(
		array $charge,
		float $amount,
		string $customerMasterId,
		array $beneficiary,
		string $invoiceId,
		string $batchId,
	): array {
		return [
			'subjectKind' => 'object',
			'subject' => $charge['chargeable'],
			'requestType' => self::REQUEST_TYPE,
			'beneficiary' => $beneficiary,
			'invoiceReference' => $invoiceId,
			'amount' => $amount,
			'currency' => (string)$charge['currency'],
			'description' => $this->describe(charge: $charge),
			'debtor' => ['customerMasterId' => $customerMasterId],
			'dueAt' => (string)$charge['dueDate'] . 'T00:00:00Z',
			'voluntary' => (bool)$charge['voluntary'],
			'raiseBatchId' => $batchId,
			'state' => 'pending',
			'paymentGateway' => 'mollie',
			'administrationId' => (string)$charge['administrationId'],
		];
	}//end buildRequest()

	/**
	 * The sentence a voluntary contribution carries on its invoice.
	 *
	 * @param string $language The guardian's language.
	 *
	 * @return string The notice, translated.
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-007)
	 */
	public function voluntaryNotice(string $language): string {
		return $this->l10nFactory->get('shillinq', $language)
			->t('This contribution is voluntary. Your child takes part whether you pay or not.');
	}//end voluntaryNotice()

	/**
	 * A new batch id: `ctb-<yyyymmdd>-<8 hex>`.
	 *
	 * @param string $invoiceDate The invoice date as Y-m-d.
	 * @param string $random Eight hexadecimal characters.
	 *
	 * @return string The batch id.
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-001)
	 */
	public function batchId(string $invoiceDate, string $random): string {
		return sprintf('ctb-%s-%s', str_replace('-', '', $invoiceDate), strtolower($random));
	}//end batchId()

	/**
	 * The invoice number of one recipient in a batch: `CTB-<yyyy>-<8 hex>-<nnnn>`.
	 *
	 * @param string $batchId The batch id.
	 * @param int $sequence The recipient's number inside the batch, from 1.
	 * @param string $invoiceDate The invoice date as Y-m-d.
	 *
	 * @return string The invoice number.
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-001)
	 */
	public function invoiceNumber(string $batchId, int $sequence, string $invoiceDate): string {
		$random = substr($batchId, (strrpos($batchId, '-') + 1));

		return sprintf('CTB-%s-%s-%04d', substr($invoiceDate, 0, 4), strtoupper($random), $sequence);
	}//end invoiceNumber()

	/**
	 * The line and checkout text: the description, marked when voluntary.
	 *
	 * @param array<string, mixed> $charge The normalised charge.
	 *
	 * @return string The text.
	 */
	private function describe(array $charge): string {
		$description = (string)$charge['description'];
		if ((bool)$charge['voluntary'] === false) {
			return $description;
		}

		$suffix = $this->l10nFactory->get('shillinq', (string)$charge['language'])->t('(voluntary)');

		return $description . ' ' . $suffix;
	}//end describe()

	/**
	 * Check the chargeable reference.
	 *
	 * @param mixed $chargeable The reference as given.
	 *
	 * @return array<string, string> The reference with its type defaulted.
	 *
	 * @throws InvalidArgumentException When a part is missing.
	 */
	private function normaliseChargeable(mixed $chargeable): array {
		if (is_array($chargeable) === false) {
			throw new InvalidArgumentException('A contribution needs the chargeable it is raised from.');
		}

		$missing = [];
		$reference = [];
		foreach (self::CHARGEABLE_PARTS as $part) {
			$value = trim((string)($chargeable[$part] ?? ''));
			if ($value === '') {
				$missing[] = $part;
			}

			$reference[$part] = $value;
		}

		if ($missing !== []) {
			throw new InvalidArgumentException(
				sprintf('The chargeable is missing %s; it names the app, the register, the schema and the id.', implode(', ', $missing))
			);
		}

		$type = trim((string)($chargeable['type'] ?? ''));
		if ($type === '') {
			$type = 'chargeable';
		}

		return [
			'app' => $reference['app'],
			'type' => $type,
			'register' => $reference['register'],
			'schema' => $reference['schema'],
			'id' => $reference['id'],
		];
	}//end normaliseChargeable()

	/**
	 * Check the recipient list.
	 *
	 * @param mixed $value The list as given.
	 *
	 * @return array<int, array<string, mixed>> The recipients.
	 *
	 * @throws InvalidArgumentException When the list is empty, too long or holds a non-object.
	 */
	private function recipients(mixed $value): array {
		if (is_array($value) === false || $value === []) {
			throw new InvalidArgumentException('A contribution needs at least one recipient.');
		}

		if (count($value) > self::MAX_RECIPIENTS) {
			throw new InvalidArgumentException(
				sprintf('One call takes at most %d recipients; send the rest in a next call.', self::MAX_RECIPIENTS)
			);
		}

		$recipients = [];
		foreach (array_values($value) as $recipient) {
			if (is_array($recipient) === false || is_array($recipient['debtor'] ?? null) === false) {
				throw new InvalidArgumentException('Every recipient is an object with a debtor.');
			}

			$recipients[] = $recipient;
		}

		return $recipients;
	}//end recipients()

	/**
	 * A money value above zero, rounded to cents, or null.
	 *
	 * @param mixed $value The value as given.
	 *
	 * @return float|null The amount, or null when it is missing, not a number or not above zero.
	 */
	private function money(mixed $value): ?float {
		if (is_bool($value) === true || is_numeric($value) === false) {
			return null;
		}

		$amount = round((float)$value, 2);
		if (is_finite($amount) === false || $amount <= 0.0) {
			return null;
		}

		return $amount;
	}//end money()

	/**
	 * Check an ISO date.
	 *
	 * @param string $value The date as given.
	 * @param string $field The field name, for the message.
	 *
	 * @return string The date as Y-m-d.
	 *
	 * @throws InvalidArgumentException When it is not a real Y-m-d date.
	 */
	private function date(string $value, string $field): string {
		$parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
			throw new InvalidArgumentException(sprintf('%s is a date as YYYY-MM-DD.', $field));
		}

		return $value;
	}//end date()

	/**
	 * The due date: the given one, or the invoice date plus the default term.
	 *
	 * @param array<string, mixed> $payload The call.
	 * @param string $invoiceDate The checked invoice date.
	 *
	 * @return string The due date as Y-m-d.
	 *
	 * @throws InvalidArgumentException When it is malformed or before the invoice date.
	 */
	private function dueDate(array $payload, string $invoiceDate): string {
		$given = (string)($payload['dueDate'] ?? '');
		if ($given === '') {
			return (new DateTimeImmutable($invoiceDate))
				->modify(sprintf('+%d days', self::DEFAULT_TERM_DAYS))
				->format('Y-m-d');
		}

		$dueDate = $this->date(value: $given, field: 'dueDate');
		if ($dueDate < $invoiceDate) {
			throw new InvalidArgumentException('The due date falls before the invoice date.');
		}

		return $dueDate;
	}//end dueDate()

	/**
	 * The invoice language: a two-letter code with an optional region, else Dutch.
	 *
	 * @param string $value The language as given.
	 *
	 * @return string The language.
	 */
	private function language(string $value): string {
		if (preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $value) !== 1) {
			return 'nl';
		}

		return $value;
	}//end language()
}//end class
