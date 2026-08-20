<?php

/**
 * model-or-references-pilot schema + seed-data integration test.
 *
 * `model-or-references-pilot` is a `kind:config` head (ADR-032) — no shillinq
 * PHP ships in this change; the declarative `$ref`/`inversedBy` idiom is
 * resolved entirely by OpenRegister's relation graph
 * (`RelationHandler::getUses/getUsedBy`, `BulkValidationHandler`'s
 * `inverseProperties` analysis, both driven by `ObjectEntity::getRelations()`
 * which is populated from stored property VALUES that equal another object's
 * UUID). This test therefore exercises only the declarative surface: it loads
 * `lib/Settings/shillinq_register.json` (no OpenRegister runtime, no HTTP)
 * and asserts that
 *
 *  - both pilot clusters declare the `$ref` (+ `inversedBy`) idiom exactly as
 *    pinned in design.md (Tasks 1, 2, 3, 5, 6);
 *  - (Task 5 originally also pinned that the descriptive
 *    `x-openregister-relations` block on ARInvoice was left intact, on the
 *    grounds that it was NOT the resolving idiom. That block is gone: ADR-062
 *    rule 7 retired the per-schema dialect fleet-wide on 2026-07-08, and its
 *    ARInvoice entries — which pointed at SepaMandate and ARInvoice by object
 *    id — were migrated to the property-level `$ref` this test already
 *    asserts. The clause is dropped rather than restated because the block it
 *    described no longer exists anywhere in the register set; nothing in this
 *    file ever asserted it, so no assertion is lost.)
 *  - the seed clusters (Tasks 4, 7) carry real, distinct, non-nil UUIDs in
 *    `@self.id` and that every reference field's stored value equals the
 *    referenced object's `@self.id` — i.e. the exact matching OpenRegister's
 *    `getUses()`/`getUsedBy()` perform against `ObjectEntity::getRelations()`
 *    values, proving `/uses`/`/used` would resolve non-empty post-import
 *    (Task 8), where before the pilot they were empty for every shillinq
 *    object;
 *  - documentation/example placeholder UUIDs stay the nil UUID, never a
 *    realistic-looking UUID (spec "Seed data uses the nil UUID for
 *    placeholders" scenario).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/model-or-references-pilot/tasks.md#verification-tasks
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Declarative-only schema + seed round-trip lock for model-or-references-pilot.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ModelOrReferencesPilotSeedIntegrationTest extends TestCase {
	private const NIL_UUID = '00000000-0000-0000-0000-000000000000';

	/**
	 * Load the base register config once.
	 *
	 * @return array<string,mixed>
	 */
	private function register(): array {
		$path = __DIR__ . '/../../lib/Settings/shillinq_register.json';
		$raw = file_get_contents($path);
		if ($raw === false) {
			self::fail('Could not read shillinq_register.json.');
		}

		$data = json_decode($raw, true);
		if (is_array($data) === false) {
			self::fail('shillinq_register.json is not valid JSON.');
		}

		return $data;
	}//end register()

	/**
	 * The EFFECTIVE register config — the monolith with every
	 * `register.d/*.json` fragment deep-merged over it, which is what
	 * `SettingsService::loadRegisterConfigData()` hands to
	 * `ConfigurationService::importFromApp()`.
	 *
	 * Reading the monolith alone is what let the Cluster-B pilot look
	 * declared for months while OpenRegister never saw it: the declaration
	 * sat at `components.<Name>`, one level above `components.schemas`, and
	 * the only assertions on it read it back from that same dead path. A
	 * schema question can only be answered against the merged result.
	 *
	 * Mirrors `deepMergeConfig()`: maps recurse, lists CONCATENATE.
	 *
	 * @return array<string,mixed>
	 */
	private function effectiveRegister(): array {
		$merged = $this->register();

		$fragments = glob(__DIR__ . '/../../lib/Settings/register.d/*.json');
		self::assertNotEmpty($fragments, 'No register.d fragments found — the merge would be a no-op.');
		sort($fragments);

		foreach ($fragments as $fragment) {
			$raw = file_get_contents($fragment);
			if ($raw === false) {
				self::fail('Could not read fragment: ' . $fragment);
			}

			$data = json_decode($raw, true);
			if (is_array($data) === false) {
				self::fail('Fragment is not valid JSON: ' . $fragment);
			}

			$merged = $this->deepMerge(base: $merged, overlay: $data);
		}

		return $merged;
	}//end effectiveRegister()

	/**
	 * Deep-merge one config over another, exactly as OpenRegister does.
	 *
	 * @param array<mixed> $base The config merged onto.
	 * @param array<mixed> $overlay The config merged in.
	 *
	 * @return array<mixed> The merged config.
	 */
	private function deepMerge(array $base, array $overlay): array {
		if (array_is_list($base) === true && array_is_list($overlay) === true) {
			return array_merge($base, $overlay);
		}

		foreach ($overlay as $key => $value) {
			if (isset($base[$key]) === true && is_array($base[$key]) === true && is_array($value) === true) {
				$base[$key] = $this->deepMerge(base: $base[$key], overlay: $value);
				continue;
			}

			$base[$key] = $value;
		}

		return $base;
	}//end deepMerge()

	/**
	 * All seeded `objects[]` entries for a given schema slug.
	 *
	 * @param string $schema Schema slug (e.g. "GLTransaction").
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function seededObjects(string $schema): array {
		$objects = ($this->register()['objects'] ?? []);
		$matches = [];
		foreach ($objects as $object) {
			if (($object['@self']['schema'] ?? null) === $schema) {
				$matches[] = $object;
			}
		}

		return $matches;
	}//end seededObjects()

	/**
	 * The single seeded object for a schema+slug pair.
	 *
	 * @param string $schema Schema slug.
	 * @param string $slug Object slug (`@self.slug`).
	 *
	 * @return array<string,mixed>
	 */
	private function seededObject(string $schema, string $slug): array {
		foreach ($this->seededObjects($schema) as $object) {
			if (($object['@self']['slug'] ?? null) === $slug) {
				return $object;
			}
		}

		self::fail("No seeded {$schema} object with slug {$slug}.");

	}//end seededObject()

	// -----------------------------------------------------------------
	// Cluster A — GL posting graph (Tasks 1-4)
	// -----------------------------------------------------------------

	/**
	 * Task 1 — GLLine.transactionId declares the $ref/inversedBy idiom to
	 * GLTransaction, per the pinned design.md idiom.
	 *
	 * @return void
	 */
	public function testGlLineTransactionIdDeclaresGlTransactionReference(): void {
		$schemas = $this->register()['components']['schemas'];
		self::assertArrayHasKey('GLLine', $schemas);

		$transactionId = $schemas['GLLine']['properties']['transactionId'];
		self::assertSame('GLTransaction', $transactionId['$ref']);
		self::assertSame('uuid', $transactionId['format']);
		self::assertSame('lines', $transactionId['inversedBy']);
		self::assertSame(self::NIL_UUID, $transactionId['example'], 'Example must be the nil-UUID placeholder, never a realistic-looking UUID.');

	}//end testGlLineTransactionIdDeclaresGlTransactionReference()

	/**
	 * Task 2 — GLTransaction declares the inverse `lines` array populated by
	 * OpenRegister from GLLine.transactionId's `inversedBy`.
	 *
	 * @return void
	 */
	public function testGlTransactionDeclaresInverseLinesArray(): void {
		$schemas = $this->register()['components']['schemas'];
		$lines = $schemas['GLTransaction']['properties']['lines'];

		self::assertSame('array', $lines['type']);
		self::assertSame('GLLine', $lines['items']['$ref']);
		self::assertSame('uuid', $lines['items']['format']);

	}//end testGlTransactionDeclaresInverseLinesArray()

	/**
	 * Task 3 — GLLine declares an Account reference (accountRef) alongside
	 * the retained accountNumber RGS-code string.
	 *
	 * @return void
	 */
	public function testGlLineDeclaresAccountRefAlongsideAccountNumber(): void {
		$properties = $this->register()['components']['schemas']['GLLine']['properties'];

		self::assertArrayHasKey('accountRef', $properties);
		self::assertSame('Account', $properties['accountRef']['$ref']);
		self::assertSame('uuid', $properties['accountRef']['format']);
		self::assertSame(self::NIL_UUID, $properties['accountRef']['example']);

		// accountNumber (the human-facing RGS code) MUST be retained per design.md.
		self::assertArrayHasKey('accountNumber', $properties);
		self::assertSame('string', $properties['accountNumber']['type']);
		self::assertArrayNotHasKey('$ref', $properties['accountNumber'], 'accountNumber stays a plain scalar; accountRef is the new resolving reference.');

	}//end testGlLineDeclaresAccountRefAlongsideAccountNumber()

	/**
	 * Task 4 — the Account objects seeded for Cluster A (none existed before
	 * the pilot) carry distinct, non-nil UUIDs.
	 *
	 * @return void
	 */
	public function testClusterASeedsThreeDistinctNonNilAccountUuids(): void {
		$accounts = $this->seededObjects('Account');
		self::assertGreaterThanOrEqual(3, count($accounts), 'design.md seeds Account objects for the AR, revenue and VAT-payable RGS codes used by the revenue GLTransaction.');

		$ids = [];
		foreach ($accounts as $account) {
			$id = ($account['@self']['id'] ?? null);
			self::assertNotNull($id, 'Every seeded Account MUST carry a stable @self.id so GLLine.accountRef can resolve it.');
			self::assertNotSame(self::NIL_UUID, $id, 'Seed object identity MUST be a real UUID, not the nil placeholder.');
			$ids[] = $id;
		}

		self::assertSame($ids, array_unique($ids), 'Seeded Account UUIDs must be distinct.');

	}//end testClusterASeedsThreeDistinctNonNilAccountUuids()

	/**
	 * Task 4 / Task 8 — the seeded GLTransaction (gl-txn-2026-q1-revenue) and
	 * its GLLines carry real, matching UUIDs: every line's transactionId
	 * equals the transaction's own @self.id, and each line's accountRef
	 * equals the matching seeded Account's @self.id. This is the exact value
	 * shape `ObjectEntity::getRelations()` extracts and `RelationHandler::
	 * getUses()/getUsedBy()` matches on — proving `/uses`/`/used` resolve
	 * non-empty post-import.
	 *
	 * @return void
	 */
	public function testSeededGlTransactionAndLinesCrossReferenceByUuid(): void {
		$transaction = $this->seededObject('GLTransaction', 'gl-txn-2026-q1-revenue');
		$transactionId = ($transaction['@self']['id'] ?? null);
		self::assertNotNull($transactionId, 'Seeded GLTransaction MUST carry a stable @self.id.');
		self::assertNotSame(self::NIL_UUID, $transactionId);

		$accountsByNumber = [];
		foreach ($this->seededObjects('Account') as $account) {
			$accountsByNumber[$account['accountNumber']] = ($account['@self']['id'] ?? null);
		}

		$lineSlugs = [
			'gl-line-revenue-1-debit',
			'gl-line-revenue-2-credit-revenue',
			'gl-line-revenue-3-credit-vat',
		];

		foreach ($lineSlugs as $slug) {
			$line = $this->seededObject('GLLine', $slug);

			// /uses on the GLLine MUST resolve its GLTransaction.
			self::assertSame(
				$transactionId,
				$line['transactionId'],
				"GLLine {$slug}.transactionId must hold the seeded GLTransaction's UUID (was a slug pre-pilot)."
			);

			// /uses on the GLLine MUST resolve its Account.
			self::assertArrayHasKey('accountRef', $line, "GLLine {$slug} must declare accountRef.");
			$expectedAccountId = ($accountsByNumber[$line['accountNumber']] ?? null);
			self::assertNotNull($expectedAccountId, "No seeded Account matches GLLine {$slug}'s accountNumber {$line['accountNumber']}.");
			self::assertSame(
				$expectedAccountId,
				$line['accountRef'],
				"GLLine {$slug}.accountRef must hold the matching seeded Account's UUID."
			);
		}

		// /used on the GLTransaction MUST resolve all three GLLines (the
		// inverse of transactionId's inversedBy: lines).
		$referencingLines = array_filter(
			$this->seededObjects('GLLine'),
			static fn (array $l): bool => $l['transactionId'] === $transactionId
		);
		self::assertCount(3, $referencingLines, '/used on the seeded GLTransaction must resolve exactly its three revenue lines.');

	}//end testSeededGlTransactionAndLinesCrossReferenceByUuid()

	// -----------------------------------------------------------------
	// Cluster B — ARInvoice <-> CustomerMaster (Tasks 5-7)
	// -----------------------------------------------------------------

	/**
	 * Task 5 — ARInvoice.customerId + glTransactionId declare the $ref idiom
	 * in the EFFECTIVE config, and the seeded values actually resolve.
	 *
	 * Asserted against `effectiveRegister()`, not the monolith. The
	 * declaration this test used to read sat at `components.ARInvoice` — one
	 * level above `components.schemas`, the only branch OpenRegister's
	 * ImportHandler iterates — so it never reached the engine, and this test
	 * passed by reading it back from the same dead path. The declaration is
	 * now on the live schema, and the shape assertion is paired with a
	 * resolution assertion on the seeded data so the two cannot drift apart
	 * again: a declaration nothing loads and a value nothing resolves look
	 * identical from a shape check alone.
	 *
	 * @return void
	 */
	public function testArInvoiceDeclaresCustomerAndGlTransactionReferences(): void {
		$effective = $this->effectiveRegister();
		$arInvoice = $effective['components']['schemas']['ARInvoice'];
		$properties = $arInvoice['properties'];

		$customerId = $properties['customerId'];
		self::assertSame('CustomerMaster', $customerId['$ref']);
		self::assertSame('uuid', $customerId['format']);
		self::assertSame('invoices', $customerId['inversedBy']);
		self::assertSame(self::NIL_UUID, $customerId['example']);

		$glTransactionId = $properties['glTransactionId'];
		self::assertSame('GLTransaction', $glTransactionId['$ref']);
		self::assertSame('uuid', $glTransactionId['format']);
		self::assertSame(self::NIL_UUID, $glTransactionId['example']);

		// The declaration is only worth anything if the shipped data obeys
		// it. Every seeded ARInvoice must point at a CustomerMaster object
		// that exists, by UUID — never at the per-administration customer
		// CODE (CustomerMaster.customerId, e.g. "DEB-0001"), which is a
		// different identifier space and is not globally unique.
		$customerUuids = [];
		foreach ($this->seededObjects('CustomerMaster') as $customer) {
			$customerUuids[] = ($customer['@self']['id'] ?? null);
		}

		$invoices = $this->seededObjects('ARInvoice');
		self::assertNotEmpty($invoices, 'No seeded ARInvoice — this test would assert nothing.');

		foreach ($invoices as $invoice) {
			self::assertContains(
				($invoice['customerId'] ?? null),
				$customerUuids,
				'Seeded ARInvoice.customerId must be the object UUID of a seeded CustomerMaster.'
			);
		}

	}//end testArInvoiceDeclaresCustomerAndGlTransactionReferences()

	/**
	 * Task 6 — CustomerMaster declares the inverse `invoices` array in the
	 * EFFECTIVE config, and the inverse resolves back.
	 *
	 * @return void
	 */
	public function testCustomerMasterDeclaresInverseInvoicesArray(): void {
		$invoices = $this->effectiveRegister()['components']['schemas']['CustomerMaster']['properties']['invoices'];

		self::assertSame('array', $invoices['type']);
		self::assertSame('ARInvoice', $invoices['items']['$ref']);
		self::assertSame('uuid', $invoices['items']['format']);

		// The inverse must resolve back: at least one seeded CustomerMaster
		// is reachable from a seeded ARInvoice via customerId.
		$reachable = [];
		foreach ($this->seededObjects('ARInvoice') as $invoice) {
			$reachable[] = ($invoice['customerId'] ?? null);
		}

		$resolved = 0;
		foreach ($this->seededObjects('CustomerMaster') as $customer) {
			if (in_array(($customer['@self']['id'] ?? null), $reachable, true) === true) {
				$resolved++;
			}
		}

		self::assertGreaterThan(
			0,
			$resolved,
			'No seeded CustomerMaster is reachable through ARInvoice.customerId — the inverse resolves to nothing.'
		);

	}//end testCustomerMasterDeclaresInverseInvoicesArray()

	/**
	 * Task 7 / Task 8 — the seeded CustomerMaster and ARInvoice (neither
	 * existed before the pilot) cross-reference by real UUID, and the
	 * ARInvoice bridges into Cluster A via glTransactionId. This is the
	 * exact value shape `/uses`/`/used` resolve.
	 *
	 * @return void
	 */
	public function testSeededArInvoiceAndCustomerMasterCrossReferenceByUuid(): void {
		$customers = $this->seededObjects('CustomerMaster');
		self::assertCount(1, $customers, 'design.md seeds exactly one demo CustomerMaster.');
		$customer = $customers[0];
		$customerId = ($customer['@self']['id'] ?? null);
		self::assertNotNull($customerId, 'Seeded CustomerMaster MUST carry a stable @self.id.');
		self::assertNotSame(self::NIL_UUID, $customerId);

		$invoices = $this->seededObjects('ARInvoice');
		self::assertCount(1, $invoices, 'design.md seeds exactly one demo ARInvoice.');
		$invoice = $invoices[0];

		// /uses on the ARInvoice MUST resolve its CustomerMaster.
		self::assertSame(
			$customerId,
			$invoice['customerId'],
			"Seeded ARInvoice.customerId must hold the seeded CustomerMaster's UUID (was a business key like 'DEMO-C1' pre-pilot)."
		);

		// /uses on the ARInvoice MUST also resolve the bridged GLTransaction.
		$transaction = $this->seededObject('GLTransaction', 'gl-txn-2026-q1-revenue');
		$transactionId = ($transaction['@self']['id'] ?? null);
		self::assertSame(
			$transactionId,
			$invoice['glTransactionId'],
			'Seeded ARInvoice.glTransactionId must bridge Cluster B into the Cluster A GLTransaction UUID.'
		);

		// /used on the CustomerMaster MUST resolve the ARInvoice (the
		// inverse of customerId's inversedBy: invoices).
		$referencingInvoices = array_filter(
			$invoices,
			static fn (array $inv): bool => $inv['customerId'] === $customerId
		);
		self::assertCount(1, $referencingInvoices, '/used on the seeded CustomerMaster must resolve its one ARInvoice.');

	}//end testSeededArInvoiceAndCustomerMasterCrossReferenceByUuid()

	/**
	 * Task 8 — no seed object anywhere in the register uses the nil UUID as
	 * a genuine cross-reference value; the nil UUID is reserved for
	 * documentation/example placeholders only (spec: "Seed data uses the nil
	 * UUID for placeholders, never realistic UUIDs").
	 *
	 * @return void
	 */
	public function testNoSeededReferenceFieldUsesTheNilUuidAsAWorkingValue(): void {
		$referenceFieldsBySchema = [
			'GLLine' => ['transactionId', 'accountRef'],
			'ARInvoice' => ['customerId', 'glTransactionId'],
		];

		foreach ($referenceFieldsBySchema as $schema => $fields) {
			foreach ($this->seededObjects($schema) as $object) {
				foreach ($fields as $field) {
					if (array_key_exists($field, $object) === false || $object[$field] === null) {
						continue;
					}

					self::assertNotSame(
						self::NIL_UUID,
						$object[$field],
						"{$schema}.{$field} on " . ($object['@self']['slug'] ?? '?') . ' must be a real cross-referencing UUID, not the nil placeholder.'
					);
				}
			}
		}

	}//end testNoSeededReferenceFieldUsesTheNilUuidAsAWorkingValue()
}//end class
