<?php

/**
 * Golden test for the OpenBalanceGuard balance, blocked and credit-limit checks.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md#req-ar-006
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\OpenBalanceGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Pins result, every log record and every store call per scenario, including paging.
 *
 * Regenerate with OPEN_BALANCE_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class OpenBalanceGuardGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/fixtures/open-balance-guard.golden.json';

	public function testChecksMatchGolden(): void {
		$page = [];
		for ($i = 0; $i < 500; $i++) {
			$page[] = ['lifecycleState' => 'open', 'grossAmount' => '0.01'];
		}

		$manyAp = array_merge($page, [['lifecycleState' => 'paid', 'grossAmount' => 5], ['grossAmount' => 0.1]]);
		$vendor = ['vendorId' => 'v1', 'administrationId' => 'adm-1'];
		$customer = ['customerId' => 'c1', 'administrationId' => 'adm-1'];
		$invoice = ['customerId' => 'c1', 'administrationId' => 'adm-1', 'grossAmount' => '100.005'];
		$scenarios = [
			'vendorZero' => [
				'vendorBalanceZero',
				$vendor,
				['APInvoice' => [['lifecycleState' => 'paid', 'grossAmount' => 10], ['lifecycleState' => 'voided', 'grossAmount' => 3]]],
			],
			'vendorOpen' => ['vendorBalanceZero', $vendor, ['APInvoice' => [['lifecycleState' => 'issued', 'grossAmount' => 0.004]]]],
			'vendorOpenRounded' => ['vendorBalanceZero', $vendor, ['APInvoice' => [['lifecycleState' => 'issued', 'grossAmount' => 0.005]]]],
			'vendorPaged' => ['vendorBalanceZero', $vendor, ['APInvoice' => $manyAp]],
			'vendorStoreDown' => ['vendorBalanceZero', $vendor, ['*throw*' => true]],
			'vendorNoContainer' => ['vendorBalanceZero', $vendor, ['*nocontainer*' => true]],
			'customerZero' => ['customerBalanceZero', [], ['ARInvoice' => [['lifecycleState' => 'written-off', 'grossAmount' => 9]]]],
			'customerOpen' => ['customerBalanceZero', $customer, ['ARInvoice' => [['lifecycleState' => 'voided', 'grossAmount' => 9]]]],
			'vendorNotBlocked' => ['vendorNotBlocked', ['vendorId' => 'v1'], ['VendorMaster' => [['lifecycleState' => 'active']]]],
			'vendorBlocked' => ['vendorNotBlocked', ['vendorId' => 'v1'], ['VendorMaster' => [['lifecycleState' => 'blocked']]]],
			'vendorUnknown' => ['vendorNotBlocked', ['vendorId' => 'v1'], []],
			'customerBlocked' => ['customerNotBlocked', $customer, ['CustomerMaster' => [['lifecycleState' => 'blocked']]]],
			'customerLookupDown' => ['customerNotBlocked', $customer, ['*throw*' => true]],
			'creditNoCustomer' => ['withinCreditLimit', $invoice, []],
			'creditNoLimit' => ['withinCreditLimit', $invoice, ['CustomerMaster' => [['creditLimit' => 0]]]],
			'creditWithin' => [
				'withinCreditLimit',
				$invoice,
				['CustomerMaster' => [['creditLimit' => '150']], 'ARInvoice' => [['lifecycleState' => 'issued', 'grossAmount' => 49.99]]],
			],
			'creditExact' => [
				'withinCreditLimit',
				$invoice,
				['CustomerMaster' => [['creditLimit' => 150.01]], 'ARInvoice' => [['lifecycleState' => 'issued', 'grossAmount' => 50]]],
			],
			'creditOver' => [
				'withinCreditLimit',
				$invoice,
				['CustomerMaster' => [['creditLimit' => 150]], 'ARInvoice' => [['lifecycleState' => 'issued', 'grossAmount' => 50]]],
			],
		];

		$actual = [];
		foreach ($scenarios as $name => [$method, $input, $records]) {
			$actual[$name] = $this->scenario($method, $input, $records);
		}

		if (getenv('OPEN_BALANCE_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($golden, $actual);
	}

	/**
	 * Run one check and capture result, logs and store calls.
	 *
	 * @param string $method The check to call.
	 * @param array<string,mixed> $input The record passed in.
	 * @param array<string,mixed> $records Records per schema.
	 *
	 * @return array<string,mixed>
	 */
	private function scenario(string $method, array $input, array $records): array {
		$logger = new class extends AbstractLogger {

			public array $records = [];

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->records[] = [(string) $level, (string) $message, $context];
			}
		};
		$store = new class($records) {

			public array $calls = [];

			private string $schema = '';

			public function __construct(private array $records) {
			}

			public function setRegister(string $register): self {
				$this->calls[] = 'register:'.$register;
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->schema = $schema;
				$this->calls[] = 'schema:'.$schema;
				return $this;
			}

			public function findAll(array $args = []): array {
				$this->calls[] = ['findAll' => $args];
				if (isset($this->records['*throw*']) === true) {
					throw new \RuntimeException('store down');
				}

				$rows = ($this->records[$this->schema] ?? []);
				return array_slice($rows, (int) ($args['offset'] ?? 0), (int) ($args['limit'] ?? count($rows)));
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		if (isset($records['*nocontainer*']) === true) {
			$container->method('get')->willThrowException(new \RuntimeException('no object service'));
		} else {
			$container->method('get')->willReturn($store);
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$guard = new OpenBalanceGuard($container, $appConfig, $logger);

		return [
			'result' => $guard->$method($input),
			'logs' => $logger->records,
			'calls' => $store->calls,
		];
	}
}
