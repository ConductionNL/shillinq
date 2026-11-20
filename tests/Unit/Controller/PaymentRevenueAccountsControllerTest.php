<?php

/**
 * Unit tests for PaymentRevenueAccountsController.
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
 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-002)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\PaymentRevenueAccountsController;
use OCA\Shillinq\Service\PaymentRevenueAccountResolver;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The admin screen's read and write of the revenue-account mapping.
 */
#[CoversClass(PaymentRevenueAccountsController::class)]
#[UsesClass(PaymentRevenueAccountResolver::class)]
final class PaymentRevenueAccountsControllerTest extends TestCase {
	/**
	 * The app-config value as stored.
	 *
	 * @var string
	 */
	private string $stored = '';

	/**
	 * Build the controller over a request with the given params.
	 *
	 * @param array<string, mixed> $params The request params.
	 *
	 * @return PaymentRevenueAccountsController The controller.
	 */
	private function controller(array $params = []): PaymentRevenueAccountsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key) => ($params[$key] ?? null)
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (): string => $this->stored);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored = $value;
				return true;
			}
		);

		return new PaymentRevenueAccountsController(
			request: $request,
			accounts: new PaymentRevenueAccountResolver(appConfig: $appConfig),
		);
	}//end controller()

	/**
	 * Index answers one key per type, empty where unmapped.
	 *
	 * @return void
	 */
	public function testIndexAnswersEveryType(): void {
		$this->stored = '{"dwangsom":"8400"}';

		$data = $this->controller()->index()->getData();

		self::assertSame(PaymentRevenueAccountResolver::TYPES, array_keys($data));
		self::assertSame('8400', $data['dwangsom']);
		self::assertSame('', $data['leges']);
	}//end testIndexAnswersEveryType()

	/**
	 * Update stores the submitted types and answers the stored form.
	 *
	 * @return void
	 */
	public function testUpdateStoresTheSubmittedTypes(): void {
		$this->stored = '{"leges":"8300"}';

		$response = $this->controller(['dwangsom' => '8400', 'leges' => '', 'register' => 'x'])->update();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('{"dwangsom":"8400"}', $this->stored);
		self::assertTrue($response->getData()['success']);
		self::assertSame('8400', $response->getData()['dwangsom']);
		self::assertSame('', $response->getData()['leges']);
		self::assertArrayNotHasKey('register', $response->getData());
	}//end testUpdateStoresTheSubmittedTypes()

	/**
	 * A refused value answers 400 with the reason, and nothing changes.
	 *
	 * @return void
	 */
	public function testUpdateRefusesANonAccount(): void {
		$this->stored = '{"leges":"8300"}';

		$response = $this->controller(['leges' => '8400', 'deposit' => 'a b'])->update();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertFalse($response->getData()['success']);
		self::assertStringContainsString('"deposit"', $response->getData()['error']);
		self::assertSame('{"leges":"8300"}', $this->stored);
	}//end testUpdateRefusesANonAccount()

	/**
	 * Both endpoints are admin-only.
	 *
	 * @return void
	 */
	public function testBothEndpointsAreAdminOnly(): void {
		foreach (['index', 'update'] as $method) {
			$attributes = (new ReflectionMethod(PaymentRevenueAccountsController::class, $method))->getAttributes(AuthorizedAdminSetting::class);
			self::assertCount(1, $attributes, $method.' must carry AuthorizedAdminSetting');
		}
	}//end testBothEndpointsAreAdminOnly()
}//end class
