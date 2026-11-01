<?php

/**
 * Rule Test-Data Seed Run
 *
 * One run of RuleTestDataSeeder: the register, the admin user and the object
 * service it writes with, and the logger its failures go to. Each section
 * backfills one kind of test data and returns its counts.
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
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\Standards\RuleEngine;
use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * Backfills the local test data so it satisfies the enforced ledger rules.
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class RuleTestDataSeedRun {

	/**
	 * The two balance legs seeded for a transaction with fewer than two lines.
	 */
	private const BALANCE_LEGS = [['1000', 'debit'], ['8000', 'credit']];

	/**
	 * EN 16931 party fields and the NL example value a seeded invoice gets
	 * when it has none, in the order they are set.
	 */
	private const PARTY_DEFAULTS = [
		'specificationId' => 'urn:cen.eu:en16931:2017',
		'sellerName' => 'Seeded Seller B.V.',
		'sellerIdentifier' => 'NL-KVK-00000000',
		'sellerVatId' => 'NL123456789B01',
		'sellerAddress' => 'Seeded street 1, Amsterdam',
		'sellerCountryCode' => 'NL',
		'buyerAddress' => 'Buyer street 2, Rotterdam',
	];

	/**
	 * Construct one run.
	 *
	 * @param string          $register      Register slug.
	 * @param IUser|null      $admin         The user the writes run as.
	 * @param mixed           $objectService The OpenRegister ObjectService.
	 * @param LoggerInterface $logger        Logger for failed writes.
	 */
	public function __construct(
		private readonly string $register,
		private readonly ?IUser $admin,
		private readonly mixed $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run every section, in order, and return the counts.
	 *
	 * @return array<string, int> Counts per kind of backfill, and alreadyCompliant.
	 *
	 * @spec openspec/changes/glline-administration-scope/specs/glline-administration-scope/spec.md
	 */
	public function run(): array {
		$ledger    = $this->seedTransactions();
		$purchases = $this->seedPurchaseUris();
		$invoices  = $this->seedInvoices();
		return [
			'sourceReferencesAdded' => ($ledger[0] + $purchases[0]),
			'linesAdded' => $ledger[1],
			'invoiceLinesAdded' => $invoices[0],
			'providerObjectsCreated' => $this->seedProviderObjects(),
			'providerFieldsAdded' => $this->seedProviderFields(),
			'alreadyCompliant' => ($ledger[2] + $purchases[1] + $invoices[1]),
		];
	}//end run()

	/**
	 * Every GLTransaction gets a sourceReference and at least two lines.
	 *
	 * @return array{0: int, 1: int, 2: int} References added, lines added, already compliant.
	 */
	private function seedTransactions(): array {
		$tally = [0, 0, 0];
		foreach ($this->rows(schema: 'GLTransaction', limit: 10000) as $row) {
			[$references, $lines] = $this->seedTransaction(transaction: self::rowData(row: $row));
			$tally[0] += $references;
			$tally[1] += $lines;
			$tally[2] += (int) (($references + $lines) === 0);
		}

		return $tally;
	}//end seedTransactions()

	/**
	 * Backfill one transaction.
	 *
	 * @param array<string, mixed> $transaction The transaction.
	 *
	 * @return array{0: int, 1: int} References added, lines added.
	 */
	private function seedTransaction(array $transaction): array {
		$references = 0;
		if (trim((string) ($transaction['sourceReference'] ?? '')) === '') {
			$references = $this->backfillSourceReference(transaction: $transaction);
		}

		$lines = 0;
		if ($this->lineCount(transaction: $transaction) < 2) {
			$lines = $this->seedBalanceLines(transaction: $transaction);
		}

		return [$references, $lines];
	}//end seedTransaction()

	/**
	 * Give a transaction a `DOC-` sourceReference from its number or id.
	 *
	 * @param array<string, mixed> $transaction The transaction.
	 *
	 * @return int 1 when saved.
	 */
	private function backfillSourceReference(array $transaction): int {
		$id     = self::rowId(row: $transaction);
		$number = (string) ($transaction['transactionNumber'] ?? '');
		unset($transaction['@self']);
		$transaction['sourceReference'] = 'DOC-' . self::orElse(value: $number, fallback: substr($id, 0, 8));
		return $this->attempt(
			save: fn () => $this->save(schema: 'GLTransaction', write: ['object' => $transaction, 'uuid' => $id]),
			failure: 'RuleTestDataSeeder: sourceReference update failed for ' . $id . ': '
		);
	}//end backfillSourceReference()

	/**
	 * Count GLLines linked to a transaction by id or transaction number.
	 *
	 * @param array<string, mixed> $transaction The transaction.
	 *
	 * @return int Number of GLLines linked by the first key with at least two, else 0.
	 */
	private function lineCount(array $transaction): int {
		$keys = [self::rowId(row: $transaction), (string) ($transaction['transactionNumber'] ?? '')];
		foreach (array_values(array_unique(array_filter($keys))) as $key) {
			$query = ['filters' => ['transactionId' => $key]];
			$rows  = $this->objectService->setRegister($this->register)->setSchema('GLLine')->findAll($query);
			if (is_array($rows) === true && count($rows) >= 2) {
				return count($rows);
			}
		}

		return 0;
	}//end lineCount()

	/**
	 * Create the two balance legs for a transaction.
	 *
	 * @param array<string, mixed> $transaction The transaction.
	 *
	 * @return int Lines saved.
	 */
	private function seedBalanceLines(array $transaction): int {
		$added = 0;
		foreach (array_keys(self::BALANCE_LEGS) as $index) {
			$line   = self::balanceLine(transaction: $transaction, index: $index);
			$added += $this->attempt(
				save: fn () => $this->save(schema: 'GLLine', write: ['object' => $line]),
				failure: 'RuleTestDataSeeder: GLLine create failed for ' . $line['transactionId'] . ': '
			);
		}

		return $added;
	}//end seedBalanceLines()

	/**
	 * One balance leg of a transaction.
	 *
	 * The administrationId is DENORMALISED onto every seeded line from the
	 * parent transaction (REQ-GLS-001). Seeded rows are not exempt: they land
	 * in the same register the SpendAnalytics aggregations read, and one
	 * un-stamped seed row flips the backfill completeness gate red for the
	 * whole instance.
	 *
	 * @param array<string, mixed> $transaction The transaction.
	 * @param int                  $index       The leg, 0 or 1.
	 *
	 * @return array<string, mixed> The GLLine.
	 */
	private static function balanceLine(array $transaction, int $index): array {
		return [
			'transactionId' => self::orElse(value: (string) ($transaction['transactionNumber'] ?? ''), fallback: self::rowId(row: $transaction)),
			'administrationId' => (string) ($transaction['administrationId'] ?? ''),
			'lineNumber' => ($index + 1),
			'accountNumber' => self::BALANCE_LEGS[$index][0],
			'side' => self::BALANCE_LEGS[$index][1],
			'amount' => 100.00,
			'currency' => (string) ($transaction['currency'] ?? 'EUR'),
			'description' => 'rules test-data seeder',
		];
	}//end balanceLine()

	/**
	 * Purchase invoices: ensure a source-document URI (traceability).
	 *
	 * @return array{0: int, 1: int} URIs added, already compliant.
	 */
	private function seedPurchaseUris(): array {
		$tally = [0, 0];
		foreach ($this->rows(schema: 'APTransaction', limit: 10000) as $row) {
			$added = $this->seedPurchaseUri(purchase: self::rowData(row: $row));
			if ($added === null) {
				$tally[1]++;
				continue;
			}

			$tally[0] += $added;
		}

		return $tally;
	}//end seedPurchaseUris()

	/**
	 * Give one purchase invoice a `doc://ap/` URI from its number or id.
	 *
	 * @param array<string, mixed> $purchase The purchase invoice.
	 *
	 * @return int|null 1 when saved, 0 when the save failed, null when it already had one.
	 */
	private function seedPurchaseUri(array $purchase): ?int {
		$id = self::rowId(row: $purchase);
		if (trim((string) ($purchase['sourceDocumentUri'] ?? '')) !== '') {
			return null;
		}

		unset($purchase['@self']);
		$purchase['sourceDocumentUri'] = 'doc://ap/' . self::orElse(value: ($purchase['invoiceNumber'] ?? ''), fallback: substr($id, 0, 8));
		return $this->attempt(
			save: fn () => $this->save(schema: 'APTransaction', write: ['object' => $purchase, 'uuid' => $id]),
			failure: 'RuleTestDataSeeder: APTransaction sourceDocumentUri update failed for ' . $id . ': '
		);
	}//end seedPurchaseUri()

	/**
	 * Sales invoices: backfill EN 16931 line items and VAT breakdown.
	 *
	 * Derived from the header net/vat, which already reconcile (BR-CO-15 is
	 * enforced), so the structured-invoice rules (BR-12/16/21..26,
	 * BR-CO-04/10/13/14/17/18, BR-45..48, BR-DEC-09/19/20/23) can be enforced.
	 *
	 * @return array{0: int, 1: int} Invoices backfilled, already compliant.
	 */
	private function seedInvoices(): array {
		$tally = [0, 0];
		foreach ($this->rows(schema: 'ARInvoice', limit: 10000) as $row) {
			$added = $this->seedInvoice(invoice: self::rowData(row: $row));
			if ($added === null) {
				$tally[1]++;
				continue;
			}

			$tally[0] += $added;
		}

		return $tally;
	}//end seedInvoices()

	/**
	 * Backfill one sales invoice unless its full structure is present.
	 *
	 * @param array<string, mixed> $invoice The sales invoice.
	 *
	 * @return int|null 1 when saved, 0 when the save failed, null when already complete.
	 */
	private function seedInvoice(array $invoice): ?int {
		$id = self::rowId(row: $invoice);
		if (self::hasFullStructure(invoice: $invoice) === true) {
			return null;
		}

		$structured = self::structured(invoice: $invoice);
		return $this->attempt(
			save: fn () => $this->save(schema: 'ARInvoice', write: ['object' => $structured, 'uuid' => $id]),
			failure: 'RuleTestDataSeeder: ARInvoice line backfill failed for ' . $id . ': '
		);
	}//end seedInvoice()

	/**
	 * Whether an invoice already has lines with a per-line vatRate, document
	 * allowance totals, a seller VAT id and an invoice type code.
	 *
	 * @param array<string, mixed> $invoice The sales invoice.
	 *
	 * @return bool True when nothing needs backfilling.
	 */
	private static function hasFullStructure(array $invoice): bool {
		$firstLine = ($invoice['invoiceLines'][0] ?? null);
		return is_array($firstLine) === true && array_key_exists('vatRate', $firstLine) === true
			&& array_key_exists('allowancesTotal', $invoice) === true
			&& trim((string) ($invoice['sellerVatId'] ?? '')) !== ''
			&& trim((string) ($invoice['invoiceTypeCode'] ?? '')) !== '';
	}//end hasFullStructure()

	/**
	 * The invoice with one line item, its VAT breakdown, empty document
	 * allowances and charges, and the EN 16931 party defaults.
	 *
	 * No document-level allowances/charges on the seeded test data: the
	 * EN 16931 allowance/charge field rules are vacuously satisfied and the
	 * BT-107/108 totals reconcile to an empty sum (BR-CO-11/12).
	 *
	 * @param array<string, mixed> $invoice The sales invoice.
	 *
	 * @return array<string, mixed> The invoice to save.
	 */
	private static function structured(array $invoice): array {
		$tax = self::tax(invoice: $invoice);
		unset($invoice['@self']);
		$invoice['invoiceLines']    = [self::lineItem(tax: $tax)];
		$invoice['vatBreakdown']    = [self::breakdown(tax: $tax)];
		$invoice['lineNetTotal']    = $tax['net'];
		$invoice['allowances']      = [];
		$invoice['charges']         = [];
		$invoice['allowancesTotal'] = 0;
		$invoice['chargesTotal']    = 0;
		return self::withPartyDefaults(invoice: $invoice);
	}//end structured()

	/**
	 * Net, VAT, VAT category and rate from an invoice header: category Z at
	 * rate 0 when the VAT rounds to zero cents, else S at VAT over net.
	 *
	 * @param array<string, mixed> $invoice The sales invoice.
	 *
	 * @return array{net: float, vat: float, category: string, rate: float}
	 */
	private static function tax(array $invoice): array {
		$net = (float) ($invoice['netAmount'] ?? 0);
		$vat = (float) ($invoice['vatAmount'] ?? 0);
		if ((int) round($vat * 100) === 0) {
			return ['net' => $net, 'vat' => $vat, 'category' => 'Z', 'rate' => 0.0];
		}

		$rate = 0.0;
		if ($net > 0) {
			$rate = (($vat / $net) * 100);
		}

		return ['net' => $net, 'vat' => $vat, 'category' => 'S', 'rate' => $rate];
	}//end tax()

	/**
	 * The single seeded line item.
	 *
	 * @param array{net: float, vat: float, category: string, rate: float} $tax The header tax.
	 *
	 * @return array<string, mixed> The line.
	 */
	private static function lineItem(array $tax): array {
		return [
			'lineId' => '1',
			'quantity' => 1,
			'unitCode' => 'C62',
			'netAmount' => $tax['net'],
			'itemName' => 'Seeded line item',
			'netPrice' => $tax['net'],
			'vatCategory' => $tax['category'],
			'vatRate' => $tax['rate'],
		];
	}//end lineItem()

	/**
	 * The VAT breakdown row of the single seeded line.
	 *
	 * @param array{net: float, vat: float, category: string, rate: float} $tax The header tax.
	 *
	 * @return array<string, mixed> The breakdown row.
	 */
	private static function breakdown(array $tax): array {
		return [
			'category' => $tax['category'],
			'taxableAmount' => $tax['net'],
			'taxAmount' => $tax['vat'],
			'rate' => $tax['rate'],
		];
	}//end breakdown()

	/**
	 * Seller/buyer party identification (BG-4/BG-7) so the EN 16931 party and
	 * per-category seller-VAT rules (BR-01/06/08/09/10, BR-CO-09/26,
	 * BR-S/Z/E-02, vatdir-226-3) are satisfied. A value already set is kept;
	 * a blank one gets the default, as hasFullStructure() reads it as missing.
	 *
	 * @param array<string, mixed> $invoice The sales invoice.
	 *
	 * @return array<string, mixed> The invoice with its party fields set.
	 */
	private static function withPartyDefaults(array $invoice): array {
		$defaults = ['invoiceTypeCode' => '380', 'supplyDate' => ($invoice['invoiceDate'] ?? '2026-06-21')] + self::PARTY_DEFAULTS;
		foreach ($defaults as $field => $default) {
			if (self::isBlank(row: $invoice, field: $field) === true) {
				$invoice[$field] = $default;
			}
		}

		return $invoice;
	}//end withPartyDefaults()

	/**
	 * Provider object seeding: create compliant sample objects for object
	 * types that have no rows yet, so the provider's checks actually evaluate
	 * (RuleEngine::providerSeedObjects, from the SeedsObjects capability).
	 *
	 * @return int Samples created.
	 */
	private function seedProviderObjects(): int {
		$created = 0;
		foreach (RuleEngine::providerSeedObjects() as $objectType => $samples) {
			if ($samples !== [] && $this->hasNoRows(objectType: $objectType) === true) {
				$created += $this->createSamples(objectType: $objectType, samples: $samples);
			}
		}

		return $created;
	}//end seedProviderObjects()

	/**
	 * Whether an object type has no rows yet; false when it cannot be probed.
	 *
	 * @param string $objectType The schema.
	 *
	 * @return bool True when it is empty.
	 */
	private function hasNoRows(string $objectType): bool {
		try {
			$existing = $this->rows(schema: $objectType, limit: 1);
		} catch (\Throwable $e) {
			$this->logger->warning('RuleTestDataSeeder: cannot probe ' . $objectType . ' for object seeding: ' . $e->getMessage());
			return false;
		}

		return (is_array($existing) === true && $existing !== []) === false;
	}//end hasNoRows()

	/**
	 * Create an object type's sample objects.
	 *
	 * @param string                           $objectType The schema.
	 * @param array<int, array<string, mixed>> $samples    The samples.
	 *
	 * @return int Samples saved.
	 */
	private function createSamples(string $objectType, array $samples): int {
		$created = 0;
		foreach ($samples as $sample) {
			$created += $this->attempt(
				save: fn () => $this->save(schema: $objectType, write: ['object' => $sample]),
				failure: 'RuleTestDataSeeder: sample ' . $objectType . ' create failed: '
			);
		}

		return $created;
	}//end createSamples()

	/**
	 * Generic per-domain provider seeding: each CheckProvider may declare the
	 * test-data field defaults its checks expect (RuleEngine::providerSeedSpecs).
	 * Backfill any missing/empty field on the existing objects of that type.
	 *
	 * @return int Objects saved.
	 */
	private function seedProviderFields(): int {
		$added = 0;
		foreach (RuleEngine::providerSeedSpecs() as $objectType => $fields) {
			if ($fields !== []) {
				$added += $this->backfillFields(objectType: $objectType, fields: $fields);
			}
		}

		return $added;
	}//end seedProviderFields()

	/**
	 * Backfill one object type's rows with its declared field defaults.
	 *
	 * @param string               $objectType The schema.
	 * @param array<string, mixed> $fields     Field name to default.
	 *
	 * @return int Objects saved.
	 */
	private function backfillFields(string $objectType, array $fields): int {
		$rows = $this->providerRows(objectType: $objectType);
		$added = 0;
		foreach ($rows as $row) {
			$data   = self::rowData(row: $row);
			$filled = self::withDefaults(row: $data, fields: $fields);
			if ($filled !== null) {
				$added += $this->saveFilled(objectType: $objectType, filled: ['uuid' => self::rowId(row: $data), 'object' => $filled]);
			}
		}

		return $added;
	}//end backfillFields()

	/**
	 * An object type's rows for field seeding; none when they cannot be loaded.
	 *
	 * @param string $objectType The schema.
	 *
	 * @return mixed What findAll returned, [] when it threw.
	 */
	private function providerRows(string $objectType): mixed {
		try {
			return $this->rows(schema: $objectType, limit: 10000);
		} catch (\Throwable $e) {
			$this->logger->warning('RuleTestDataSeeder: cannot load ' . $objectType . ' for provider seeding: ' . $e->getMessage());
			return [];
		}
	}//end providerRows()

	/**
	 * The row with every missing or blank declared field set to its default,
	 * or null when it already had them all.
	 *
	 * @param array<string, mixed> $row    The row.
	 * @param array<string, mixed> $fields Field name to default.
	 *
	 * @return array<string, mixed>|null The filled row.
	 */
	private static function withDefaults(array $row, array $fields): ?array {
		$changed = false;
		foreach ($fields as $field => $default) {
			if (self::isBlank(row: $row, field: $field) === true) {
				$row[$field] = $default;
				$changed     = true;
			}
		}

		if ($changed === false) {
			return null;
		}

		return $row;
	}//end withDefaults()

	/**
	 * Whether a row lacks a field or holds only whitespace in it.
	 *
	 * @param array<string, mixed> $row   The row.
	 * @param string|int           $field The field.
	 *
	 * @return bool True when the default applies.
	 */
	private static function isBlank(array $row, string|int $field): bool {
		return array_key_exists($field, $row) === false || trim((string) ($row[$field] ?? '')) === '';
	}//end isBlank()

	/**
	 * Save a row filled by withDefaults().
	 *
	 * @param string                                          $objectType The schema.
	 * @param array{uuid: string, object: array<string, mixed>} $filled     The row's id and its filled data.
	 *
	 * @return int 1 when saved.
	 */
	private function saveFilled(string $objectType, array $filled): int {
		$object = $filled['object'];
		unset($object['@self']);
		return $this->attempt(
			save: fn () => $this->save(schema: $objectType, write: ['object' => $object, 'uuid' => $filled['uuid']]),
			failure: 'RuleTestDataSeeder: provider field backfill failed for ' . $objectType . ' ' . $filled['uuid'] . ': '
		);
	}//end saveFilled()

	/**
	 * Save an object in the register as the run's admin, with RBAC and
	 * multitenancy off.
	 *
	 * @param string               $schema The schema.
	 * @param array<string, mixed> $write  `object`, and `uuid` when updating.
	 *
	 * @return void
	 */
	private function save(string $schema, array $write): void {
		$this->objectService->saveObject(
			...$write,
			register: $this->register,
			schema: $schema,
			_rbac: false,
			_multitenancy: false,
			currentUser: $this->admin
		);
	}//end save()

	/**
	 * A schema's rows in the register.
	 *
	 * @param string $schema The schema.
	 * @param int    $limit  The most rows to read.
	 *
	 * @return mixed What findAll returned.
	 */
	private function rows(string $schema, int $limit): mixed {
		return $this->objectService->setRegister($this->register)->setSchema($schema)->findAll(['limit' => $limit]);
	}//end rows()

	/**
	 * Run one save; log the failure and count 0 when it throws.
	 *
	 * @param \Closure $save    The save.
	 * @param string   $failure The warning prefix; the exception message follows it.
	 *
	 * @return int 1 when saved, else 0.
	 */
	private function attempt(\Closure $save, string $failure): int {
		try {
			$save();
			return 1;
		} catch (\Throwable $e) {
			$this->logger->warning($failure . $e->getMessage());
			return 0;
		}
	}//end attempt()

	/**
	 * A stored row as an array.
	 *
	 * @param mixed $row An array, or an entity with jsonSerialize().
	 *
	 * @return array<string, mixed> The row.
	 */
	private static function rowData(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		return $row->jsonSerialize();
	}//end rowData()

	/**
	 * A row's id, from `id` or the `@self` envelope.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The id, '' when absent.
	 */
	private static function rowId(array $row): string {
		return (string) ($row['id'] ?? $row['@self']['id'] ?? '');
	}//end rowId()

	/**
	 * The value, or the fallback when the value is ''.
	 *
	 * @param mixed  $value    The value.
	 * @param string $fallback The fallback.
	 *
	 * @return mixed The value or the fallback.
	 */
	private static function orElse(mixed $value, string $fallback): mixed {
		if ($value !== '') {
			return $value;
		}

		return $fallback;
	}//end orElse()
}//end class
