<?php

/**
 * Golden test for the ProductCatalogController listings.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\ProductCatalogController;
use OCA\Shillinq\Service\ProductCatalogService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins both listings, allowed and refused, with every call and log line.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class ProductCatalogControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Run one listing.
	 *
	 * @param string $action   The action.
	 * @param bool   $loggedIn Whether a user is logged in.
	 * @param mixed  $rows     What the service answers; 'throw' makes it throw.
	 *
	 * @return array{0:int,1:mixed}
	 */
	private function act(string $action, bool $loggedIn, mixed $rows=null): array {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(
			function () use ($loggedIn): ?IUser {
				$this->calls[] = ['getUser'];
				if ($loggedIn === true) {
					return $this->createMock(IUser::class);
				}

				return null;
			}
		);
		$service = $this->createMock(ProductCatalogService::class);
		foreach (['listProducts', 'listAttributes'] as $method) {
			$service->method($method)->willReturnCallback(
				function () use ($method, $rows): ?array {
					$this->calls[] = [$method];
					if ($rows === 'throw') {
						throw new RuntimeException('store down');
					}

					return $rows;
				}
			);
		}

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->calls[] = ['error', $message, $context];
			}
		);

		$response = (new ProductCatalogController($this->createMock(IRequest::class), $service, $session, $logger))->$action();
		return [$response->getStatus(), $response->getData()];
	}//end act()

	/**
	 * Each listing: 401 without a session, 403 without an administration, the rows, or a logged 500.
	 *
	 * @return void
	 */
	public function testListings(): void {
		$cases = [['products', 'listProducts', 'product catalog'], ['productAttributes', 'listAttributes', 'product attribute']];
		foreach ($cases as [$action, $method, $label]) {
			$this->calls = [];
			self::assertSame(expected: [401, ['error' => 'Authentication required']], actual: $this->act(action: $action, loggedIn: false));
			self::assertSame(expected: [403, ['error' => 'no_accessible_administration']], actual: $this->act(action: $action, loggedIn: true));
			self::assertSame(expected: [200, ['rows' => [1]]], actual: $this->act(action: $action, loggedIn: true, rows: ['rows' => [1]]));
			self::assertSame(expected: [200, []], actual: $this->act(action: $action, loggedIn: true, rows: []));
			self::assertSame(expected: [500, ['error' => 'catalog_unavailable']], actual: $this->act(action: $action, loggedIn: true, rows: 'throw'));
			$log      = ['error', 'ProductCatalogController: ' . $label . ' lookup failed', ['exception' => 'store down']];
			$expected = [['getUser'], ['getUser'], [$method], ['getUser'], [$method], ['getUser'], [$method], ['getUser'], [$method], $log];
			self::assertSame(expected: $expected, actual: $this->calls);
		}
	}//end testListings()
}//end class
