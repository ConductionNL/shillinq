<?php

/**
 * Golden test for the PaymentRunDuplicateGuard export decision.
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
 * @spec openspec/specs/payment-control-guards/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\PaymentRunDuplicateGuard;
use OCA\Shillinq\PaymentRun\PaymentBlockChecker;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Pins decision, message, every log record and every store call per scenario.
 *
 * Regenerate with PAYMENT_DUPLICATE_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class PaymentRunDuplicateGuardGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/fixtures/payment-run-duplicate-guard.golden.json';

	public function testDecisionsMatchGolden(): void {
		$batch = [
			'id' => 'pr-1',
			'administrationId' => ' adm-1 ',
			'paymentLines' => [['apTransactionRef' => ' ap-1 '], ['apTransactionRef' => 'ap-2']],
		];
		$invoices = [['id' => 'ap-1', 'state' => 'issued'], ['id' => 'ap-2', 'state' => 'issued']];
		$scenarios = [
			'noId' => [['paymentLines' => []], []],
			'selfId' => [['@self' => ['id' => 'pr-s'], 'paymentLines' => []], []],
			'nonStringId' => [['id' => 7], []],
			'emptyLines' => [['id' => 'pr-1'], []],
			'nonArrayLines' => [['id' => 'pr-1', 'paymentLines' => 'x'], []],
			'nonArrayLine' => [['id' => 'pr-1', 'paymentLines' => ['x']], []],
			'lineWithoutRef' => [['id' => 'pr-1', 'paymentLines' => [['amount' => 1]]], []],
			'clean' => [$batch, ['APTransaction' => $invoices]],
			'unknownInvoice' => [$batch, ['APTransaction' => [['id' => 'ap-1', 'state' => 'issued']]]],
			'paidByState' => [$batch, ['APTransaction' => [['id' => 'ap-2', 'state' => 'paid']]]],
			'paidByStatusSlug' => [$batch, ['APTransaction' => ['junk', ['slug' => 'ap-1', 'status' => 'paid']]]],
			'paidOtherInvoice' => [$batch, ['APTransaction' => [['id' => 'ap-9', 'state' => 'paid']]]],
			'batchedDraft' => [$batch, ['PaymentRun' => [['id' => 'pr-2', 'lifecycleState' => 'draft', 'paymentLines' => [['apTransactionRef' => 'ap-2']]]]]],
			'batchedExportedByStatus' => [
				$batch,
				[
					'PaymentRun' => [['uuid' => 'pr-3', 'status' => 'exported', 'paymentLines' => ['junk', ['apTransactionRef' => ' ap-1 ']]]],
				],
			],
			'ownBatch' => [
				$batch,
				[
					'APTransaction' => $invoices,
					'PaymentRun' => [['id' => 'pr-1', 'lifecycleState' => 'approved', 'paymentLines' => [['apTransactionRef' => 'ap-1']]]],
				],
			],
			'reconciledOther' => [
				$batch,
				[
					'APTransaction' => $invoices,
					'PaymentRun' => [['id' => 'pr-4', 'lifecycleState' => 'reconciled', 'paymentLines' => [['apTransactionRef' => 'ap-1']]]],
				],
			],
			'otherNonArrayLines' => [
				$batch,
				[
					'APTransaction' => $invoices,
					'PaymentRun' => ['junk', ['id' => 'pr-5', 'lifecycleState' => 'approved', 'paymentLines' => 'x']],
				],
			],
			'otherBatchOtherInvoice' => [
				$batch,
				[
					'APTransaction' => $invoices,
					'PaymentRun' => [['id' => 'pr-6', 'lifecycleState' => 'approved', 'paymentLines' => [['apTransactionRef' => 'ap-7'], ['amount' => 1]]]],
				],
			],
			'blocked' => [
				$batch,
				[
					'APTransaction' => [
						['id' => 'ap-1', 'invoiceNumber' => '2026-0001', 'state' => 'issued', 'paymentBlocked' => true, 'paymentBlockReason' => 'Hold'],
						['id' => 'ap-2', 'invoiceNumber' => '2026-0002', 'state' => 'issued', 'paymentBlocked' => true, 'paymentBlockReason' => 'Hold'],
					],
				],
			],
			'noAdministration' => [['id' => 'pr-1', 'paymentLines' => [['apTransactionRef' => 'ap-1']]], ['APTransaction' => $invoices]],
			'storeDown' => [$batch, ['*throw*' => true]],
		];

		$actual = [];
		foreach ($scenarios as $name => [$object, $records]) {
			$actual[$name] = $this->scenario($object, $records);
		}

		if (getenv('PAYMENT_DUPLICATE_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($golden, $actual);
	}

	/**
	 * Run one scenario and capture result, logs and store calls.
	 *
	 * @param array<string,mixed> $object The PaymentRun payload.
	 * @param array<string,mixed> $records Records per schema.
	 *
	 * @return array<string,mixed>
	 */
	private function scenario(array $object, array $records): array {
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

				return ($this->records[$this->schema] ?? []);
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$settings = $this->createMock(RegisterLocation::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$blockRecords = $records;
		unset($blockRecords['*throw*']);
		$guard = new PaymentRunDuplicateGuard(
			$container,
			$appConfig,
			$logger,
			new PaymentBlockChecker(new InMemoryObjectServiceStub($blockRecords), $settings)
		);

		$result = $guard->check($object, 'export', 'alice');

		return [
			'allowed' => $result->isAllowed(),
			'message' => $result->getMessage(),
			'logs' => $logger->records,
			'calls' => $store->calls,
		];
	}
}
