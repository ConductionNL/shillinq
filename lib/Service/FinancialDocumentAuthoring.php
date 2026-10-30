<?php

/**
 * Financial Document Authoring
 *
 * The acts of the financial documents panel that start a new document on a
 * host object: a draft quote with one line, a credit note for an invoice, and
 * a payout. Every act refuses before it writes: on its action (`quote.create`,
 * `invoice.credit`, `payable.raise`), then on the administration, then on the
 * document it starts from.
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
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-009)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Event\PayableRaisedEvent;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;

/**
 * Starts a quote, a credit note or a payout on a host object.
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-009)
 */
class FinancialDocumentAuthoring {
	/**
	 * The action that starts a quote.
	 *
	 * @var string
	 */
	public const ACTION_CREATE_QUOTE = 'quote.create';

	/**
	 * The action that credits an invoice.
	 *
	 * @var string
	 */
	public const ACTION_CREDIT_INVOICE = 'invoice.credit';

	/**
	 * The action that raises a payout.
	 *
	 * @var string
	 */
	public const ACTION_RAISE_PAYOUT = 'payable.raise';

	/**
	 * Invoice states a credit note can be issued for.
	 *
	 * @var array<int, string>
	 */
	private const CREDITABLE_STATES = ['issued', 'overdue', 'disputed', 'paid'];

	/**
	 * The amount fields a credit note negates.
	 *
	 * @var array<int, string>
	 */
	private const AMOUNTS = ['grossAmount', 'netAmount', 'vatAmount'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service (ADR-083).
	 * @param IAppConfig $appConfig App config, for the register slug.
	 * @param FinancialDocumentGuard $guard The refusals every act shares.
	 * @param ContributionDebtorResolver $debtors Finds or makes the customer a quote is for.
	 * @param IEventDispatcher $dispatcher Hands a payout to InboundPayableService.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly FinancialDocumentGuard $guard,
		private readonly ContributionDebtorResolver $debtors,
		private readonly IEventDispatcher $dispatcher,
	) {
	}//end __construct()

	/**
	 * Start a draft quote on the host object, with one line.
	 *
	 * Payload: description, amount, debtor (`customerMasterId`, or `name` and
	 * `email`), and optionally currency, administrationId and objectType.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param array<string, mixed> $payload The quote.
	 *
	 * @return array{id: string} The quote.
	 *
	 * @throws FinancialDocumentActionRefusedException When the caller, the administration or the payload is refused.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-009)
	 */
	public function createQuote(string $register, string $schema, string $objectId, array $payload): array {
		$this->guard->assertMay(action: self::ACTION_CREATE_QUOTE);
		$administrationId = $this->guard->administrationOr(given: (string)($payload['administrationId'] ?? ''));
		$this->guard->assertAdministration(administrationId: $administrationId);
		$amount = $this->amountOf(payload: $payload);
		$debtor = $payload['debtor'] ?? null;
		if (is_array($debtor) === false || ((string)($debtor['customerMasterId'] ?? '') === '' && (string)($debtor['email'] ?? '') === '')) {
			throw new FinancialDocumentActionRefusedException(message: 'A quote needs a customer, or a name and an email.', status: 400);
		}

		$customer = $this->debtors->resolve(debtor: $debtor, administrationId: $administrationId);
		$saved = $this->save(schema: 'Quote', object: [
			'quoteNumber' => $this->number(prefix: 'OFF'),
			'customerReference' => $customer['customerMasterId'],
			'administrationId' => $administrationId,
			'status' => 'draft',
			'currency' => (string)($payload['currency'] ?? 'EUR'),
			'validUntil' => gmdate('Y-m-d', strtotime('+30 days')),
			'linkedObjects' => [$this->link(register: $register, schema: $schema, objectId: $objectId, type: (string)($payload['objectType'] ?? ''))],
		]);
		$id = ObjectIdentifier::resolve(saved: $saved);
		$this->save(schema: 'QuoteLine', object: [
			'quote' => $id,
			'lineNumber' => 1,
			'description' => trim((string)($payload['description'] ?? '')),
			'quantity' => 1,
			'unitPrice' => $amount,
		]);

		return ['id' => $id];
	}//end createQuote()

	/**
	 * Issue a credit note for the whole of an invoice, on the same objects.
	 *
	 * @param string $invoiceId The invoice.
	 *
	 * @return array{id: string} The credit note.
	 *
	 * @throws FinancialDocumentActionRefusedException When the caller, the administration or the invoice refuses it.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-009)
	 */
	public function creditInvoice(string $invoiceId): array {
		$this->guard->assertMay(action: self::ACTION_CREDIT_INVOICE);
		$invoice = $this->guard->readableInvoice(invoiceId: $invoiceId);
		$this->guard->assertAdministration(administrationId: (string)($invoice['administrationId'] ?? ''));
		if ((string)($invoice['invoiceType'] ?? 'standard') === 'credit-note'
			|| in_array((string)($invoice['lifecycleState'] ?? ''), self::CREDITABLE_STATES, true) === false
		) {
			throw new FinancialDocumentActionRefusedException(message: 'Only an issued invoice can be credited.', status: 409);
		}

		$today = gmdate('Y-m-d');
		$credit = [
			'invoiceNumber' => $this->number(prefix: 'CRN'),
			'invoiceType' => 'credit-note',
			'lifecycleState' => 'issued',
			'customerId' => (string)($invoice['customerId'] ?? ''),
			'administrationId' => (string)($invoice['administrationId'] ?? ''),
			'currency' => (string)($invoice['currency'] ?? 'EUR'),
			'invoiceDate' => $today,
			'dueDate' => $today,
			'periodId' => substr($today, 0, 7),
			'precedingInvoiceReferences' => [(string)($invoice['invoiceNumber'] ?? $invoiceId)],
			'linkedObjects' => array_values((array)($invoice['linkedObjects'] ?? [])),
			'invoiceLines' => array_map(
				static fn (array $line): array => self::negated(values: $line, keys: ['netPrice', 'netAmount']),
				(array)($invoice['invoiceLines'] ?? [])
			),
		];
		$credit += array_intersect_key(self::negated(values: $invoice, keys: self::AMOUNTS), array_flip(self::AMOUNTS));

		return ['id' => ObjectIdentifier::resolve(saved: $this->save(schema: 'ARInvoice', object: $credit))];
	}//end creditInvoice()

	/**
	 * Raise a payout on the host object, with shillinq as the raising app.
	 *
	 * Payload: amount, payeeName, iban, and optionally description, dueOn,
	 * category, administrationId and objectType.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param array<string, mixed> $payload The payout.
	 *
	 * @return array{payableId: string, state: string} The payout.
	 *
	 * @throws FinancialDocumentActionRefusedException When the caller, the administration or finance refuses it.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-009)
	 */
	public function raisePayout(string $register, string $schema, string $objectId, array $payload): array {
		$this->guard->assertMay(action: self::ACTION_RAISE_PAYOUT);
		$administrationId = $this->guard->administrationOr(given: (string)($payload['administrationId'] ?? ''));
		$this->guard->assertAdministration(administrationId: $administrationId);

		$event = new PayableRaisedEvent(
			sourceApp: 'shillinq',
			amount: $this->amountOf(payload: $payload),
			payeeName: trim((string)($payload['payeeName'] ?? '')),
			payeeIban: trim((string)($payload['iban'] ?? '')),
			reference: $this->number(prefix: 'PAY'),
			description: trim((string)($payload['description'] ?? '')),
			category: trim((string)($payload['category'] ?? '')),
			administrationId: $administrationId,
			dueOn: trim((string)($payload['dueOn'] ?? '')),
			linkedObjects: [$this->link(register: $register, schema: $schema, objectId: $objectId, type: (string)($payload['objectType'] ?? ''))],
		);
		$this->dispatcher->dispatchTyped($event);
		if ($event->isHandled() === false) {
			throw new FinancialDocumentActionRefusedException(message: (string)($event->getError() ?? 'The payout was not taken.'), status: 400);
		}

		$result = (array)$event->getResult();

		return ['payableId' => (string)($result['payableId'] ?? ''), 'state' => (string)($result['state'] ?? '')];
	}//end raisePayout()

	/**
	 * The amount a payload names, refused when it is not above zero.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return float The amount.
	 *
	 * @throws FinancialDocumentActionRefusedException When there is no amount above zero.
	 */
	private function amountOf(array $payload): float {
		$amount = round((float)($payload['amount'] ?? 0), 2);
		if ($amount <= 0.0) {
			throw new FinancialDocumentActionRefusedException(message: 'This needs an amount above zero.', status: 400);
		}

		return $amount;
	}//end amountOf()

	/**
	 * The host object as a linked object.
	 *
	 * @param string $register The register.
	 * @param string $schema The schema.
	 * @param string $objectId The id.
	 * @param string $type The semantic type, or empty.
	 *
	 * @return array<string, string> The link.
	 */
	private function link(string $register, string $schema, string $objectId, string $type): array {
		$link = ['register' => $register, 'schema' => $schema, 'id' => $objectId];
		if (trim($type) !== '') {
			$link = ['type' => trim($type)] + $link;
		}

		return $link;
	}//end link()

	/**
	 * A document number with a prefix, the year and a random part.
	 *
	 * @param string $prefix The prefix: OFF, CRN or PAY.
	 *
	 * @return string The number.
	 */
	private function number(string $prefix): string {
		return sprintf('%s-%s-%s', $prefix, gmdate('Y'), strtoupper(bin2hex(random_bytes(4))));
	}//end number()

	/**
	 * Save one object in shillinq's register.
	 *
	 * @param string $schema The schema.
	 * @param array<string, mixed> $object The object.
	 *
	 * @return mixed The saved object.
	 */
	private function save(string $schema, array $object): mixed {
		return $this->objectService->saveObject(
			object: $object,
			register: $this->appConfig->getValueString('shillinq', 'register', 'shillinq'),
			schema: $schema,
			_rbac: false,
		);
	}//end save()

	/**
	 * The named numeric fields of a row, negated; other fields as they are.
	 *
	 * @param array<string, mixed> $values The row.
	 * @param array<int, string> $keys The fields to negate.
	 *
	 * @return array<string, mixed> The row.
	 */
	private static function negated(array $values, array $keys): array {
		foreach ($keys as $key) {
			if (is_numeric($values[$key] ?? null) === true) {
				$values[$key] = -1 * (float)$values[$key];
			}
		}

		return $values;
	}//end negated()
}//end class
