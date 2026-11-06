<?php

/**
 * Quick Draft Invoice Service
 *
 * Saves a draft sales invoice from the quick-draft dialog. The browser sends
 * the customer, the dates, a reference and the lines; the server checks the
 * customer belongs to the administration, totals the lines, gives the draft
 * a provisional number and stamps administration and lifecycle state.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Sales
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/shillinq-invoice-quick-draft/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Sales;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Util\ObjectIdentifier;
use DateTimeImmutable;
use OCP\Security\ISecureRandom;
use Throwable;

/**
 * Drafts an ARInvoice on the server.
 *
 * @spec openspec/specs/shillinq-invoice-quick-draft/spec.md
 */
class QuickDraftInvoiceService {

	/**
	 * How often a provisional number is drawn before giving up.
	 *
	 * @var int
	 */
	private const NUMBER_ATTEMPTS = 5;

	/**
	 * Payment term in days when the customer has none.
	 *
	 * @var int
	 */
	private const DEFAULT_TERM_DAYS = 30;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service (ADR-083).
	 * @param SettingsService        $settings      The register slug.
	 * @param ISecureRandom          $random        The provisional number's suffix.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly SettingsService $settings,
		private readonly ISecureRandom $random,
	) {
	}//end __construct()

	/**
	 * Save a draft invoice for a customer of the administration.
	 *
	 * @param string              $administrationId The caller's administration, resolved on the server.
	 * @param array<string,mixed> $request          customerId, invoiceDate, dueDate, reference, glAccount, lines.
	 *
	 * @return array<string,mixed> The saved invoice with its id.
	 *
	 * @throws QuickDraftRefusedException When the request cannot become a draft.
	 *
	 * @spec openspec/specs/shillinq-invoice-quick-draft/spec.md
	 */
	public function draft(string $administrationId, array $request): array {
		if ($administrationId === '') {
			throw new QuickDraftRefusedException('Choose an administration before drafting an invoice.');
		}

		$customer = $this->customer(administrationId: $administrationId, customerId: trim((string)($request['customerId'] ?? '')));
		if ($customer === null) {
			throw new QuickDraftRefusedException('This customer is not part of your administration.');
		}

		$invoiceDate = trim((string)($request['invoiceDate'] ?? ''));
		if (self::isDate(value: $invoiceDate) === false) {
			throw new QuickDraftRefusedException('Enter a valid invoice date.');
		}

		$lines = self::lines(given: $request['lines'] ?? [], glAccount: trim((string)($request['glAccount'] ?? '')));
		if ($lines === []) {
			throw new QuickDraftRefusedException('Add at least one line with a description and a price.');
		}

		$dueDate = trim((string)($request['dueDate'] ?? ''));
		if (self::isDate(value: $dueDate) === false || $dueDate < $invoiceDate) {
			$dueDate = self::addDays(date: $invoiceDate, days: self::termDays(terms: (string)($customer['paymentTerms'] ?? '')));
		}

		$invoice = array_merge(
			[
				'invoiceNumber' => $this->provisionalNumber(administrationId: $administrationId, invoiceDate: $invoiceDate),
				'administrationId' => $administrationId,
				'periodId' => substr($invoiceDate, 0, 7),
				'customerId' => (string)($customer['customerId'] ?? $customer['id']),
				'invoiceDate' => $invoiceDate,
				'dueDate' => $dueDate,
				'currency' => 'EUR',
				'lifecycleState' => 'draft',
				'customerReference' => trim((string)($request['reference'] ?? '')),
				'invoiceLines' => $lines,
			],
			self::totals(lines: $lines)
		);

		$saved = ObjectIdentifier::recordWithId(candidate: $this->scoped(schema: 'ARInvoice')->saveObject($invoice));
		$invoice['id'] = (string)($saved['id'] ?? '');

		return $invoice;
	}//end draft()

	/**
	 * The customer, by business key or id, only when it is the administration's.
	 *
	 * @param string $administrationId The administration.
	 * @param string $customerId       The customer's key or id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function customer(string $administrationId, string $customerId): ?array {
		if ($customerId === '') {
			return null;
		}

		try {
			$row = ObjectIdentifier::findOne(scoped: $this->scoped(schema: 'CustomerMaster'), id: $customerId, fallbackProperty: 'customerId');
		} catch (Throwable $e) {
			return null;
		}

		if ($row === null || (string)($row['administrationId'] ?? '') !== $administrationId) {
			return null;
		}

		return $row;
	}//end customer()

	/**
	 * The lines with a description or a price, in the EN 16931 BG-25 shape.
	 * Empty when none has both a description and a price.
	 *
	 * @param mixed  $given     The lines the browser sent.
	 * @param string $glAccount The draft's default ledger account.
	 *
	 * @return list<array<string,mixed>>
	 *
	 * @throws QuickDraftRefusedException When a line has an unusable number.
	 */
	private static function lines(mixed $given, string $glAccount): array {
		$lines = [];
		$priced = false;
		foreach ((array)$given as $line) {
			if (is_array($line) === false) {
				continue;
			}

			$built = self::line(line: $line, lineId: (string)(count($lines) + 1), glAccount: $glAccount);
			if ($built === null) {
				continue;
			}

			$lines[] = $built;
			$priced = ($priced === true || ($built['itemName'] !== '' && $built['netPrice'] > 0));
		}

		if ($priced === false) {
			return [];
		}

		return $lines;
	}//end lines()

	/**
	 * One line, or null when it has neither a description nor a price.
	 *
	 * @param array<string,mixed> $line      The line the browser sent.
	 * @param string              $lineId    The line's position.
	 * @param string              $glAccount The draft's default ledger account.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @throws QuickDraftRefusedException When the line has an unusable number.
	 */
	private static function line(array $line, string $lineId, string $glAccount): ?array {
		$description = trim((string)($line['description'] ?? ''));
		$quantity = $line['quantity'] ?? 1;
		$price = $line['unitPrice'] ?? 0;
		$rate = $line['vatRate'] ?? 0;
		if (is_numeric($quantity) === false || is_numeric($price) === false || is_numeric($rate) === false) {
			throw new QuickDraftRefusedException('Every line needs a number for quantity, price and VAT rate.');
		}

		if ($description === '' && (float)$price <= 0) {
			return null;
		}

		if ((float)$rate < 0 || (float)$rate > 100) {
			throw new QuickDraftRefusedException('A VAT rate lies between 0 and 100 percent.');
		}

		$lineGlAccount = trim((string)($line['glAccount'] ?? ''));
		if ($lineGlAccount === '') {
			$lineGlAccount = $glAccount;
		}

		$category = 'Z';
		if ((float)$rate > 0) {
			$category = 'S';
		}

		return [
			'lineId' => $lineId,
			'itemName' => $description,
			'quantity' => (float)$quantity,
			'unitCode' => 'C62',
			'netPrice' => (float)$price,
			'netAmount' => round((float)$quantity * (float)$price, 2),
			'vatRate' => (float)$rate,
			'vatCategory' => $category,
			'glAccount' => self::nullIfEmpty(value: $lineGlAccount),
		];
	}//end line()

	/**
	 * Null for an empty string, else the string.
	 *
	 * @param string $value The value.
	 *
	 * @return string|null
	 */
	private static function nullIfEmpty(string $value): ?string {
		if ($value === '') {
			return null;
		}

		return $value;
	}//end nullIfEmpty()

	/**
	 * Net, VAT and gross: VAT is rounded once per rate, as EN 16931 does.
	 *
	 * @param list<array<string,mixed>> $lines The lines.
	 *
	 * @return array{netAmount: float, vatAmount: float, grossAmount: float}
	 */
	private static function totals(array $lines): array {
		$netCents = 0;
		$netCentsPerRate = [];
		foreach ($lines as $line) {
			$cents = (int)round($line['netAmount'] * 100);
			$netCents += $cents;
			$key = (string)$line['vatRate'];
			$netCentsPerRate[$key] = (($netCentsPerRate[$key] ?? 0) + $cents);
		}

		$vatCents = 0;
		foreach ($netCentsPerRate as $rate => $cents) {
			$vatCents += (int)round($cents * (float)$rate / 100);
		}

		return [
			'netAmount' => round($netCents / 100, 2),
			'vatAmount' => round($vatCents / 100, 2),
			'grossAmount' => round(($netCents + $vatCents) / 100, 2),
		];
	}//end totals()

	/**
	 * A provisional number not yet used in the administration. The final
	 * sequential number is given when the draft is posted.
	 *
	 * @param string $administrationId The administration.
	 * @param string $invoiceDate      The invoice date.
	 *
	 * @return string
	 *
	 * @throws QuickDraftRefusedException When no free number was drawn.
	 */
	private function provisionalNumber(string $administrationId, string $invoiceDate): string {
		$prefix = 'DRAFT-' . str_replace('-', '', $invoiceDate) . '-';
		for ($attempt = 0; $attempt < self::NUMBER_ATTEMPTS; $attempt++) {
			$number = $prefix . $this->random->generate(8, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
			$taken = $this->scoped(schema: 'ARInvoice')->findAll(
				['filters' => ['administrationId' => $administrationId, 'invoiceNumber' => $number], 'limit' => 1]
			);
			if ($taken === []) {
				return $number;
			}
		}

		throw new QuickDraftRefusedException('No free draft number was found. Try again.');
	}//end provisionalNumber()

	/**
	 * Days in a payment term such as `net14` or `30`.
	 *
	 * @param string $terms The term.
	 *
	 * @return int
	 */
	private static function termDays(string $terms): int {
		if (preg_match('/(\d+)/', $terms, $match) === 1) {
			return (int)$match[1];
		}

		return self::DEFAULT_TERM_DAYS;
	}//end termDays()

	/**
	 * Whether a value is a calendar date (YYYY-MM-DD).
	 *
	 * @param string $value The value.
	 *
	 * @return bool
	 */
	private static function isDate(string $value): bool {
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $part) !== 1) {
			return false;
		}

		return checkdate((int)$part[2], (int)$part[3], (int)$part[1]);
	}//end isDate()

	/**
	 * A date plus a number of days.
	 *
	 * @param string $date The date (YYYY-MM-DD).
	 * @param int    $days The days.
	 *
	 * @return string
	 */
	private static function addDays(string $date, int $days): string {
		return (new DateTimeImmutable($date . 'T00:00:00Z'))->modify('+' . $days . ' days')->format('Y-m-d');
	}//end addDays()

	/**
	 * The object service on one schema of shillinq's register.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectServiceInterface
	 */
	private function scoped(string $schema): ObjectServiceInterface {
		return $this->objectService->setRegister($this->settings->getRegisterSlug())->setSchema($schema);
	}//end scoped()
}//end class
