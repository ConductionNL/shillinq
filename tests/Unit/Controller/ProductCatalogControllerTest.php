<?php

/**
 * Unit tests for ProductCatalogController — the route half of the inventory
 * product catalog (#860).
 *
 * The double for the catalog service is HAND-WRITTEN (see
 * {@see RecordingProductCatalogService} at the bottom of this file) rather
 * than a `createMock()`. A PHPUnit mock cannot observe a named argument — it
 * resolves a call against its own signature and then invokes the return
 * callback positionally — so an argument expectation over this app's
 * named-argument call style measures the double, not the controller. The fake
 * counts what it was actually asked for and the tests assert on the body that
 * came back.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
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

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\ProductCatalogController;
use OCA\Shillinq\Service\ProductCatalogService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Covers the two read-only product catalog endpoints.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ProductCatalogControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Hand-written catalog-service fake.
	 *
	 * @var RecordingProductCatalogService
	 */
	private RecordingProductCatalogService $catalogService;

	/**
	 * Mock IUserSession.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Set up shared fixtures — authenticated by default.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->catalogService = new RecordingProductCatalogService();
		$this->userSession = $this->createMock(IUserSession::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

	}//end setUp()

	/**
	 * Build the controller over the current doubles.
	 *
	 * @return ProductCatalogController The subject.
	 */
	private function controller(): ProductCatalogController {
		return new ProductCatalogController(
			$this->request,
			$this->catalogService,
			$this->userSession,
			new NullLogger(),
		);

	}//end controller()

	/**
	 * The catalog envelope reaches the caller intact, provenance included.
	 *
	 * The `source` / `authoritative` pair is asserted explicitly: a controller
	 * that returned only `products` would render an identical table for a stale
	 * local cache and for the real master.
	 *
	 * @return void
	 */
	public function testProductsReturnsTheCatalogEnvelope(): void {
		$this->catalogService->products = [
			'source' => 'local-cache',
			'authoritative' => false,
			'masterAvailable' => false,
			'masterApp' => 'pipelinq',
			'total' => 1,
			'products' => [['productId' => 'prod-abc', 'name' => null]],
		];

		$response = $this->controller()->products();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('local-cache', $response->getData()['source']);
		self::assertFalse($response->getData()['authoritative']);
		self::assertSame(1, $this->catalogService->productCalls);

	}//end testProductsReturnsTheCatalogEnvelope()

	/**
	 * The attribute envelope reaches the caller intact.
	 *
	 * @return void
	 */
	public function testProductAttributesReturnsTheAttributeEnvelope(): void {
		$this->catalogService->attributes = [
			'source' => 'contract',
			'authoritative' => false,
			'masterAvailable' => false,
			'masterApp' => 'pipelinq',
			'total' => 1,
			'attributes' => [['name' => 'sku', 'ownedBy' => 'pipelinq']],
		];

		$response = $this->controller()->productAttributes();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('sku', $response->getData()['attributes'][0]['name']);
		self::assertSame(1, $this->catalogService->attributeCalls);

	}//end testProductAttributesReturnsTheAttributeEnvelope()

	/**
	 * An anonymous caller is refused before the service is consulted, on both
	 * endpoints.
	 *
	 * @return void
	 */
	public function testBothEndpointsRequireAuthentication(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$this->userSession = $session;

		$products = $this->controller()->products();
		$attributes = $this->controller()->productAttributes();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $products->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $attributes->getStatus());
		self::assertSame(0, $this->catalogService->productCalls, 'the catalog ran for an anonymous caller');
		self::assertSame(0, $this->catalogService->attributeCalls, 'the attribute list ran for an anonymous caller');

	}//end testBothEndpointsRequireAuthentication()

	/**
	 * A caller with no administration membership is refused with 403 on both
	 * endpoints — never with an empty 200, which would read to the operator as
	 * "this administration has no products" rather than "you have none".
	 *
	 * @return void
	 */
	public function testBothEndpointsAnswer403WhenTheCallerHoldsNoAdministration(): void {
		$this->catalogService->products = null;
		$this->catalogService->attributes = null;

		$products = $this->controller()->products();
		$attributes = $this->controller()->productAttributes();

		self::assertSame(Http::STATUS_FORBIDDEN, $products->getStatus());
		self::assertSame(['error' => 'no_accessible_administration'], $products->getData());
		self::assertSame(Http::STATUS_FORBIDDEN, $attributes->getStatus());
		self::assertSame(['error' => 'no_accessible_administration'], $attributes->getData());

	}//end testBothEndpointsAnswer403WhenTheCallerHoldsNoAdministration()

	/**
	 * A failure inside the service answers 500 with a stable code and leaks no
	 * exception text to the caller.
	 *
	 * @return void
	 */
	public function testProductsAnswers500WithoutLeakingTheException(): void {
		$this->catalogService->throw = new RuntimeException('SQLSTATE[42P01] relation "oc_openregister_objects" does not exist');

		$response = $this->controller()->products();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'catalog_unavailable'], $response->getData());
		self::assertStringNotContainsString('SQLSTATE', (string)json_encode($response->getData()));

	}//end testProductsAnswers500WithoutLeakingTheException()

	/**
	 * The same for the attribute endpoint — asserted separately because a
	 * try/catch is easy to add to one arm and forget on the other.
	 *
	 * @return void
	 */
	public function testProductAttributesAnswers500WithoutLeakingTheException(): void {
		$this->catalogService->throw = new RuntimeException('SQLSTATE[42P01] relation "oc_openregister_objects" does not exist');

		$response = $this->controller()->productAttributes();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'catalog_unavailable'], $response->getData());

	}//end testProductAttributesAnswers500WithoutLeakingTheException()
}//end class

/**
 * Hand-written ProductCatalogService double.
 *
 * Deliberately overrides the constructor without calling the parent's: the
 * parent's promoted dependencies are never touched because every method that
 * would read them is overridden here.
 *
 * phpcs:disable
 */
final class RecordingProductCatalogService extends ProductCatalogService {

	/**
	 * What listProducts() answers. Null models the membership refusal.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $products = [];

	/**
	 * What listAttributes() answers. Null models the membership refusal.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $attributes = [];

	/**
	 * When set, both methods throw it.
	 *
	 * @var \Throwable|null
	 */
	public ?\Throwable $throw = null;

	/**
	 * How many times listProducts() was called.
	 *
	 * @var integer
	 */
	public int $productCalls = 0;

	/**
	 * How many times listAttributes() was called.
	 *
	 * @var integer
	 */
	public int $attributeCalls = 0;

	/**
	 * No-op constructor — the parent's dependencies are never reached.
	 */
	public function __construct() {
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function listProducts(): ?array {
		$this->productCalls++;
		if ($this->throw !== null) {
			throw $this->throw;
		}

		return $this->products;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function listAttributes(): ?array {
		$this->attributeCalls++;
		if ($this->throw !== null) {
			throw $this->throw;
		}

		return $this->attributes;
	}
}
