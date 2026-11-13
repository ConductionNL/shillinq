<?php

/**
 * Shillinq PayoutMatchingService.
 *
 * Takes a payment provider's payout apart: each payment line is matched by
 * provider payment id to the payment request, booking deposit or web shop
 * invoice it paid, each refund or chargeback line to the invoice of its
 * original payment, and every line nothing carries is left unmatched so the
 * bookkeeper can assign it.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Payout
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Payout;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\AppInfo\AppIdentity;
use OCP\IAppConfig;

/**
 * Matches the lines of a provider payout to what they paid.
 *
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.2
 */
class PayoutMatchingService {

	/**
	 * Where a provider payment id is kept, as [schema, property, kind], in lookup order.
	 *
	 * @var list<array{0: string, 1: string, 2: string}>
	 */
	public const SOURCES = [
		['PaymentRequest', 'providerPaymentId', 'payment-request'],
		['PaymentRequest', 'paymentIntentId', 'payment-request'],
		['DepositPayment', 'paymentIntentId', 'deposit'],
		['ARInvoice', 'webshop.paymentReference', 'invoice'],
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param IAppConfig             $appConfig     App config for the register slug.
	 *
	 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.2
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The payout with every line matched or marked unmatched, its counts, and
	 * state `matched` when it was `received`.
	 *
	 * A line keeps an earlier match: a bookkeeper's assignment is not undone.
	 *
	 * @param array<string, mixed> $payout The ProviderPayout.
	 *
	 * @return array<string, mixed> The payout as matched.
	 *
	 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.2
	 */
	public function match(array $payout): array {
		$administrationId = (string)($payout['administrationId'] ?? '');
		$lines = [];
		$matched = 0;
		foreach ((array)($payout['lines'] ?? []) as $line) {
			if (is_array($line) === false) {
				continue;
			}

			$line = $this->matchLine(line: $line, administrationId: $administrationId);
			if ($line['status'] === 'matched') {
				$matched++;
			}

			$lines[] = $line;
		}

		$payout['lines'] = $lines;
		$payout['matchedLineCount'] = $matched;
		$payout['unmatchedLineCount'] = (count($lines) - $matched);
		if ((string)($payout['state'] ?? 'received') === 'received') {
			$payout['state'] = 'matched';
		}

		return $payout;
	}//end match()

	/**
	 * The unmatched lines of a payout, for the bookkeeper's list.
	 *
	 * @param array<string, mixed> $payout The matched ProviderPayout.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.2
	 */
	public static function unmatched(array $payout): array {
		return array_values(
			array_filter(
				(array)($payout['lines'] ?? []),
				static fn ($line): bool => is_array($line) === true && ($line['status'] ?? '') !== 'matched'
			)
		);
	}//end unmatched()

	/**
	 * One line, matched to what it paid or marked unmatched.
	 *
	 * @param array<string, mixed> $line             The payout line.
	 * @param string               $administrationId The payout's administration.
	 *
	 * @return array<string, mixed>
	 */
	private function matchLine(array $line, string $administrationId): array {
		if (($line['status'] ?? '') === 'matched' && trim((string)($line['matchedObjectId'] ?? '')) !== '') {
			return $line;
		}

		$type = (string)($line['type'] ?? 'payment');
		$paymentId = trim((string)($line['providerPaymentId'] ?? ''));
		$found = null;
		if ($type === 'payment') {
			$found = $this->find(paymentId: $paymentId, administrationId: $administrationId);
		} else {
			// A refund or chargeback settles against the invoice its original payment paid.
			$found = $this->invoiceOf(
				paid: $this->find(paymentId: trim((string)($line['originalPaymentId'] ?? '')), administrationId: $administrationId)
			);
		}

		if ($found === null) {
			$line['status'] = 'unmatched';
			unset($line['matchedObjectId'], $line['matchedObjectType']);
			return $line;
		}

		$line['status'] = 'matched';
		$line['matchedObjectId'] = $found['id'];
		$line['matchedObjectType'] = $found['kind'];
		return $line;
	}//end matchLine()

	/**
	 * The request, deposit or invoice that carries a provider payment id.
	 *
	 * @param string $paymentId        The provider payment id.
	 * @param string $administrationId The administration it must belong to.
	 *
	 * @return array{id: string, kind: string, object: array<string, mixed>}|null
	 */
	private function find(string $paymentId, string $administrationId): ?array {
		if ($paymentId === '') {
			return null;
		}

		foreach (self::SOURCES as [$schema, $property, $kind]) {
			$rows = $this->objectService
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['filters' => [$property => $paymentId], 'limit' => 5]);
			foreach ($rows as $row) {
				$object = $this->asArray(row: $row);
				$id = (string)($object['id'] ?? ($object['@self']['id'] ?? ''));
				// The filter is re-checked: a filter the query layer ignores must not match every row.
				if ($id === '' || $this->valueAt(object: $object, path: $property) !== $paymentId) {
					continue;
				}

				if ($administrationId !== '' && (string)($object['administrationId'] ?? $administrationId) !== $administrationId) {
					continue;
				}

				return ['id' => $id, 'kind' => $kind, 'object' => $object];
			}
		}//end foreach

		return null;
	}//end find()

	/**
	 * The invoice a found payment paid: the invoice itself, or the one its request or deposit names.
	 *
	 * @param array{id: string, kind: string, object: array<string, mixed>}|null $paid The found payment.
	 *
	 * @return array{id: string, kind: string, object: array<string, mixed>}|null
	 */
	private function invoiceOf(?array $paid): ?array {
		if ($paid === null) {
			return null;
		}

		if ($paid['kind'] === 'invoice') {
			return $paid;
		}

		$invoiceId = trim((string)($paid['object']['invoiceReference'] ?? ($paid['object']['arInvoiceId'] ?? '')));
		if ($invoiceId === '') {
			return null;
		}

		return ['id' => $invoiceId, 'kind' => 'invoice', 'object' => []];
	}//end invoiceOf()

	/**
	 * A value at a dotted path.
	 *
	 * @param array<string, mixed> $object The object.
	 * @param string               $path   The dotted path.
	 *
	 * @return string
	 */
	private function valueAt(array $object, string $path): string {
		$value = $object;
		foreach (explode('.', $path) as $key) {
			if (is_array($value) === false || array_key_exists($key, $value) === false) {
				return '';
			}

			$value = $value[$key];
		}

		if (is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end valueAt()

	/**
	 * A search row as an array.
	 *
	 * @param mixed $row An entity or an array.
	 *
	 * @return array<string, mixed>
	 */
	private function asArray(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		if (is_array($row) === false) {
			return [];
		}

		return $row;
	}//end asArray()

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
