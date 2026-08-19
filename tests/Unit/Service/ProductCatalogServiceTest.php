<?php

/**
 * Unit tests for ProductCatalogService — the read side of the inventory
 * product catalog (#860).
 *
 * ## What these tests are pointed at
 *
 * The failure mode this class exists to prevent is a catalog page that renders
 * and can never list a row. So the assertions are about WHICH SOURCE answered
 * and WHAT THE ROWS CONTAIN, not about a query having been issued:
 *
 *  - the master path is asserted present with master rows AND the fallback is
 *    asserted to take over without them, because the positive half alone would
 *    pass on a service that always read the master and always found nothing;
 *  - the fallback's unowned identity fields are asserted NULL, because a
 *    projection that invented a placeholder name would look identical to one
 *    that resolved a real one;
 *  - tenant scoping is proved by seeding a SECOND administration and asserting
 *    its product is absent from the output, not by asserting a query shape.
 *
 * There is no `expects($this->once())->method('findAll')` anywhere in this
 * file. A PHPUnit mock cannot observe a named argument — it resolves the call
 * against its OWN signature and invokes the return callback positionally — so
 * an argument expectation over this app's call style would pin the double's
 * defaults rather than the code. The store is the repo's hand-written
 * `InMemoryObjectServiceStub`, which really filters.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/inventory-product-catalog/spec.md#req-ipc-008
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\ProductCatalogService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Catalog resolution tests.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ProductCatalogServiceTest extends TestCase {

	/**
	 * Administration ids the faked membership guard answers with.
	 *
	 * @var array<int,string>
	 */
	private array $accessible = ['adm-001'];

	/**
	 * Build the subject over a seeded in-memory OpenRegister.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $rows Schema slug => rows.
	 *
	 * @return ProductCatalogService The subject.
	 */
	private function subject(array $rows): ProductCatalogService {
		return $this->subjectOver(objectService: new InMemoryObjectServiceStub($rows));

	}//end subject()

	/**
	 * Build the subject over an arbitrary object service.
	 *
	 * @param ObjectServiceInterface $objectService The store.
	 *
	 * @return ProductCatalogService The subject.
	 */
	private function subjectOver(ObjectServiceInterface $objectService): ProductCatalogService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$context = $this->createMock(AdministrationContextService::class);
		$context->method('accessibleAdministrationIds')
			->willReturnCallback(fn (): array => $this->accessible);

		return new ProductCatalogService(
			appConfig: $appConfig,
			logger: new NullLogger(),
			objectService: $objectService,
			administrationContext: $context,
		);

	}//end subjectOver()

	/**
	 * Two products in the pipelinq master, one category.
	 *
	 * @return array<string,array<int,array<string,mixed>>> The seed.
	 */
	private function masterSeed(): array {
		return [
			'product' => [
				[
					'id' => 'prod-abc',
					'sku' => 'DELL-XPS-13-2024',
					'name' => 'Dell XPS 13 Laptop',
					'category' => 'cat-it',
					'unitPrice' => 1899.0,
					'cost' => 1450.0,
					'taxRate' => 21,
					'unit' => 'ST',
					'barcode' => '0711719454837',
					'status' => 'active',
					'variants' => [['attributes' => ['RAM' => '16GB', 'Storage' => '512GB']]],
					'modifierGroups' => [['name' => 'Warranty']],
				],
				[
					'id' => 'prod-def',
					'sku' => 'HP-LJ-M404-TONER-BK',
					'name' => 'Toner Cartridge HP LaserJet Pro M404',
					'category' => 'cat-office',
					'unitPrice' => 78.5,
					'status' => 'active',
				],
			],
			'productCategory' => [
				['id' => 'cat-it', 'name' => 'IT hardware'],
				['id' => 'cat-office', 'name' => 'Office supplies'],
			],
		];

	}//end masterSeed()

	/**
	 * Local inventory referencing two products, one of them in another tenant.
	 *
	 * @return array<string,array<int,array<string,mixed>>> The seed.
	 */
	private function localSeed(): array {
		return [
			'InventoryStock' => [
				[
					'productId' => 'prod-abc',
					'productSku' => 'DELL-XPS-13-2024',
					'locationCode' => 'WH-AMS-001',
					'quantityOnHand' => 4.0,
					'unitCost' => 1450.0,
					'status' => 'active',
					'administrationId' => 'adm-001',
				],
				[
					'productId' => 'prod-abc',
					'productSku' => 'DELL-XPS-13-2024',
					'locationCode' => 'WH-RTM-001',
					'quantityOnHand' => 2.5,
					'unitCost' => 1450.0,
					'status' => 'active',
					'administrationId' => 'adm-001',
				],
				[
					'productId' => 'prod-other-tenant',
					'locationCode' => 'WH-UTR-001',
					'quantityOnHand' => 99.0,
					'status' => 'active',
					'administrationId' => 'adm-999',
				],
			],
			'Barcode' => [
				[
					'barcode' => '0711719454837',
					'barcodeType' => 'GTIN',
					'format' => 'GTIN-12',
					'productId' => 'prod-abc',
					'uomCode' => 'EA',
					'quantity' => 1,
					'isDefault' => true,
				],
				[
					'barcode' => '3663602910000',
					'barcodeType' => 'EAN',
					'format' => 'EAN-13',
					'productId' => 'prod-barcode-only',
					'uomCode' => 'EA',
					'quantity' => 1,
				],
			],
		];

	}//end localSeed()

	/**
	 * With a reachable master, its products are served AS AUTHORITATIVE.
	 *
	 * @return void
	 */
	public function testMasterProductsAreServedAsAuthoritative(): void {
		$result = $this->subject($this->masterSeed())->listProducts();

		$this->assertSame(ProductCatalogService::SOURCE_MASTER, $result['source']);
		$this->assertTrue($result['authoritative']);
		$this->assertTrue($result['masterAvailable']);
		$this->assertSame(2, $result['total']);

		$names = array_column($result['products'], 'name', 'productId');
		$this->assertSame('Dell XPS 13 Laptop', $names['prod-abc']);
		$this->assertSame('Toner Cartridge HP LaserJet Pro M404', $names['prod-def']);

	}//end testMasterProductsAreServedAsAuthoritative()

	/**
	 * The master's uuid `category` FK is resolved to the category NAME.
	 *
	 * Asserted with its negative half — an unresolvable uuid must survive as
	 * itself rather than becoming null — because a mapper that dropped every
	 * category would pass a "the known one resolved" assertion on its own.
	 *
	 * @return void
	 */
	public function testMasterCategoryUuidIsResolvedToItsName(): void {
		$seed = $this->masterSeed();
		$seed['product'][] = ['id' => 'prod-ghi', 'name' => 'Unfiled item', 'category' => 'cat-missing'];

		$result = $this->subject($seed)->listProducts();
		$categories = array_column($result['products'], 'category', 'productId');

		$this->assertSame('IT hardware', $categories['prod-abc']);
		$this->assertSame('Office supplies', $categories['prod-def']);
		$this->assertSame('cat-missing', $categories['prod-ghi']);

	}//end testMasterCategoryUuidIsResolvedToItsName()

	/**
	 * Without a master, the catalog is projected from local inventory rows.
	 *
	 * This is the half that stops the page being a permanently-empty table on
	 * every install that has no pipelinq.
	 *
	 * @return void
	 */
	public function testFallsBackToTheLocalCacheWhenTheMasterIsAbsent(): void {
		$result = $this->subject($this->localSeed())->listProducts();

		$this->assertSame(ProductCatalogService::SOURCE_LOCAL_CACHE, $result['source']);
		$this->assertFalse($result['authoritative']);
		$this->assertFalse($result['masterAvailable']);

		$ids = array_column($result['products'], 'productId');
		$this->assertContains('prod-abc', $ids);
		$this->assertContains('prod-barcode-only', $ids);

	}//end testFallsBackToTheLocalCacheWhenTheMasterIsAbsent()

	/**
	 * A product held at two locations is ONE row, with the quantities summed
	 * and its default barcode attached.
	 *
	 * @return void
	 */
	public function testLocalProjectionCollapsesLocationsAndAttachesTheBarcode(): void {
		$result = $this->subject($this->localSeed())->listProducts();

		$rows = array_column($result['products'], null, 'productId');
		$this->assertSame(1, count(array_filter($result['products'], static fn (array $r): bool => $r['productId'] === 'prod-abc')));
		$this->assertSame(2, $rows['prod-abc']['stockLocations']);
		$this->assertEqualsWithDelta(6.5, $rows['prod-abc']['quantityOnHand'], 0.001);
		$this->assertSame('0711719454837', $rows['prod-abc']['primaryBarcode']);
		$this->assertEqualsWithDelta(1450.0, $rows['prod-abc']['unitCost'], 0.001);

	}//end testLocalProjectionCollapsesLocationsAndAttachesTheBarcode()

	/**
	 * The fallback leaves fields shillinq does not own NULL.
	 *
	 * The negative control for the whole design: a projection that filled
	 * `name` with the sku, the id, or any placeholder would render a page that
	 * looks like it resolved the master when it did not.
	 *
	 * @return void
	 */
	public function testLocalProjectionLeavesUnownedIdentityFieldsNull(): void {
		$result = $this->subject($this->localSeed())->listProducts();
		$rows = array_column($result['products'], null, 'productId');

		$this->assertNull($rows['prod-abc']['name']);
		$this->assertNull($rows['prod-abc']['category']);
		$this->assertNull($rows['prod-abc']['unitPrice']);

		// The fields shillinq DOES own are populated on the same row, so the
		// nulls above are an ownership statement and not an empty projection.
		$this->assertSame('DELL-XPS-13-2024', $rows['prod-abc']['sku']);
		$this->assertSame(ProductCatalogService::SOURCE_LOCAL_CACHE, $rows['prod-abc']['source']);

	}//end testLocalProjectionLeavesUnownedIdentityFieldsNull()

	/**
	 * Another administration's stock never reaches the caller's catalog.
	 *
	 * @return void
	 */
	public function testLocalProjectionIsScopedToTheCallersAdministrations(): void {
		$result = $this->subject($this->localSeed())->listProducts();
		$ids = array_column($result['products'], 'productId');

		$this->assertNotContains('prod-other-tenant', $ids);

		// Positive control on the same store: granting the membership makes the
		// very same row appear, so its absence above is the guard and not an
		// empty seed.
		$this->accessible = ['adm-001', 'adm-999'];
		$widened = array_column($this->subject($this->localSeed())->listProducts()['products'], 'productId');
		$this->assertContains('prod-other-tenant', $widened);

	}//end testLocalProjectionIsScopedToTheCallersAdministrations()

	/**
	 * A caller with no AdministrationMembership is REFUSED — on both endpoints,
	 * and before the master is consulted.
	 *
	 * The master seed is deliberately present: without the refusal this call
	 * would answer two authoritative product definitions, which is the hole.
	 * The positive control is the widening at the end — the same store, the
	 * same seed, one membership granted, and the rows appear.
	 *
	 * @return void
	 */
	public function testACallerWithNoAdministrationIsRefusedOnBothEndpoints(): void {
		$this->accessible = [];
		$subject = $this->subject($this->masterSeed());

		$this->assertNull($subject->listProducts());
		$this->assertNull($subject->listAttributes());

		$this->accessible = ['adm-001'];
		$granted = $this->subject($this->masterSeed());
		$this->assertNotNull($granted->listProducts());
		$this->assertSame(2, $granted->listProducts()['total']);

	}//end testACallerWithNoAdministrationIsRefusedOnBothEndpoints()

	/**
	 * A master that throws (register absent, the normal case) falls through to
	 * the local cache instead of propagating.
	 *
	 * @return void
	 */
	public function testAMasterThatThrowsFallsThroughInsteadOfFailing(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willThrowException(new RuntimeException("Register not found: 'pipelinq'"));

		$result = $this->subjectOver(objectService: $objectService)->listProducts();

		$this->assertSame(ProductCatalogService::SOURCE_LOCAL_CACHE, $result['source']);
		$this->assertFalse($result['authoritative']);
		$this->assertSame([], $result['products']);

	}//end testAMasterThatThrowsFallsThroughInsteadOfFailing()

	/**
	 * With a reachable master, attribute definitions are its products' own
	 * attribute names.
	 *
	 * @return void
	 */
	public function testAttributesComeFromTheMasterWhenItDeclaresThem(): void {
		$result = $this->subject($this->masterSeed())->listAttributes();

		$this->assertSame(ProductCatalogService::SOURCE_MASTER, $result['source']);
		$this->assertTrue($result['authoritative']);

		$names = array_column($result['attributes'], 'name');
		$this->assertContains('RAM', $names);
		$this->assertContains('Storage', $names);
		$this->assertContains('Warranty', $names);

	}//end testAttributesComeFromTheMasterWhenItDeclaresThem()

	/**
	 * Without a master, the attribute page still lists the contract's surface —
	 * and every row names the application that owns its value.
	 *
	 * @return void
	 */
	public function testAttributesFallBackToTheContractSurfaceAndAreNeverEmpty(): void {
		$result = $this->subject([])->listAttributes();

		$this->assertSame(ProductCatalogService::SOURCE_CONTRACT, $result['source']);
		$this->assertFalse($result['authoritative']);
		$this->assertGreaterThan(0, $result['total']);

		$owners = array_unique(array_column($result['attributes'], 'ownedBy'));
		sort($owners);
		$this->assertSame(['pipelinq', 'shillinq'], $owners);

	}//end testAttributesFallBackToTheContractSurfaceAndAreNeverEmpty()

	/**
	 * A reachable master carrying no per-product attribute names still yields
	 * the contract surface rather than an empty table.
	 *
	 * @return void
	 */
	public function testAReachableMasterWithoutAttributeNamesStillYieldsRows(): void {
		$seed = ['product' => [['id' => 'prod-plain', 'name' => 'Plain item']]];

		$result = $this->subject($seed)->listAttributes();

		$this->assertSame(ProductCatalogService::SOURCE_CONTRACT, $result['source']);
		$this->assertTrue($result['masterAvailable']);
		$this->assertGreaterThan(0, $result['total']);

	}//end testAReachableMasterWithoutAttributeNamesStillYieldsRows()
}//end class
