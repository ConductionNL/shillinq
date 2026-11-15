<?php

/**
 * The signed pay link of an invoice.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Payment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/receivables-payment-links/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Payment;

use OCA\Shillinq\Service\Payment\PayLinkService;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * Valid, tampered, foreign-administration and rotated tokens; the pay URL.
 *
 * @covers \OCA\Shillinq\Service\Payment\PayLinkService
 */
class PayLinkServiceTest extends TestCase {

	/**
	 * The app config values, shared by the services a test builds.
	 *
	 * @var array<string, mixed>
	 */
	private array $values = [];

	/**
	 * A service over an instance secret.
	 *
	 * @param string $secret The instance secret.
	 *
	 * @return PayLinkService
	 */
	private function service(string $secret = 'instance-secret-of-test'): PayLinkService {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(static fn (string $key, string $default = ''): string => $key === 'secret' ? $secret : $default);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(fn (string $app, string $key, int $default = 0): int => (int)($this->values[$key] ?? $default));
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => (string)($this->values[$key] ?? $default));
		$appConfig->method('setValueInt')->willReturnCallback(
			function (string $app, string $key, int $value): bool {
				$this->values[$key] = $value;
				return true;
			}
		);

		return new PayLinkService(config: $config, appConfig: $appConfig);
	}//end service()

	/**
	 * A token verifies to the invoice and administration it was signed for.
	 *
	 * @return void
	 */
	public function testAValidTokenVerifies(): void {
		$token = $this->service()->tokenFor(administrationId: 'adm-1', invoiceId: 'inv-2026-0412');

		$this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', (string)$token);
		$this->assertSame(['administrationId' => 'adm-1', 'invoiceId' => 'inv-2026-0412'], $this->service()->verify(token: (string)$token));
		$this->assertSame(['administrationId' => 'adm-1', 'invoiceId' => 'inv-2026-0412'], $this->service()->verify(token: (string)$token, expectedAdministrationId: 'adm-1'));
	}//end testAValidTokenVerifies()

	/**
	 * A changed payload, a changed signature or a token signed with another secret opens nothing.
	 *
	 * @return void
	 */
	public function testATamperedTokenIsRefused(): void {
		$token = (string)$this->service()->tokenFor(administrationId: 'adm-1', invoiceId: 'inv-1');
		[$payload, $signature] = explode('.', $token);
		$forged = rtrim(strtr(base64_encode((string)json_encode(['a' => 'adm-1', 'i' => 'inv-2', 'g' => 0])), '+/', '-_'), '=');

		$this->assertNull($this->service()->verify(token: $forged . '.' . $signature));
		$this->assertNull($this->service()->verify(token: $payload . '.' . strrev($signature)));
		$this->assertNull($this->service(secret: 'another-instance')->verify(token: $token));
		foreach (['', 'abc', $payload, $payload . '.', '.' . $signature, $token . '.x', '!!.##'] as $broken) {
			$this->assertNull($this->service()->verify(token: $broken), $broken);
		}
	}//end testATamperedTokenIsRefused()

	/**
	 * A token of one administration is refused where another is expected.
	 *
	 * @return void
	 */
	public function testAForeignAdministrationTokenIsRefused(): void {
		$token = (string)$this->service()->tokenFor(administrationId: 'adm-1', invoiceId: 'inv-1');

		$this->assertNull($this->service()->verify(token: $token, expectedAdministrationId: 'adm-2'));
	}//end testAForeignAdministrationTokenIsRefused()

	/**
	 * Rotating an administration's key invalidates its links and only its links.
	 *
	 * @return void
	 */
	public function testRotationInvalidatesTheAdministrationsLinks(): void {
		$service = $this->service();
		$mine    = (string)$service->tokenFor(administrationId: 'adm-1', invoiceId: 'inv-1');
		$theirs  = (string)$service->tokenFor(administrationId: 'adm-2', invoiceId: 'inv-9');

		$this->assertSame(1, $service->rotate(administrationId: 'adm-1'));

		$this->assertNull($service->verify(token: $mine));
		$this->assertNotNull($service->verify(token: $theirs));
		$fresh = (string)$service->tokenFor(administrationId: 'adm-1', invoiceId: 'inv-1');
		$this->assertNotSame($mine, $fresh);
		$this->assertSame('inv-1', $service->verify(token: $fresh)['invoiceId']);
	}//end testRotationInvalidatesTheAdministrationsLinks()

	/**
	 * Without an instance secret or with an id that is not one, nothing is signed.
	 *
	 * @return void
	 */
	public function testNothingIsSignedWithoutASecretOrAValidId(): void {
		$this->assertNull($this->service(secret: '')->tokenFor(administrationId: 'adm-1', invoiceId: 'inv-1'));
		$this->assertNull($this->service()->tokenFor(administrationId: 'adm 1', invoiceId: 'inv-1'));
		$this->assertNull($this->service()->tokenFor(administrationId: 'adm-1', invoiceId: ''));
	}//end testNothingIsSignedWithoutASecretOrAValidId()

	/**
	 * The pay URL is the portal's https pay page with the token; no page, no URL.
	 *
	 * @return void
	 */
	public function testThePayUrlNeedsAnHttpsPayPage(): void {
		$invoice = ['id' => 'inv-1', 'administrationId' => 'adm-1'];
		$this->assertNull($this->service()->forInvoice(invoice: $invoice));

		$this->values[PayLinkService::PAGE_KEY] = 'http://portal.example.nl/pay';
		$this->assertNull($this->service()->forInvoice(invoice: $invoice));

		$this->values[PayLinkService::PAGE_KEY] = 'https://portal.example.nl/pay?lang=nl';
		$url = (string)$this->service()->forInvoice(invoice: $invoice);
		$this->assertStringStartsWith('https://portal.example.nl/pay?lang=nl&pay=', $url);
		parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
		$this->assertSame(['administrationId' => 'adm-1', 'invoiceId' => 'inv-1'], $this->service()->verify(token: (string)$query['pay']));
	}//end testThePayUrlNeedsAnHttpsPayPage()
}//end class
