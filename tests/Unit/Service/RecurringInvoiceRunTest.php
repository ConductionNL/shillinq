<?php

/**
 * Characterisation tests for RecurringInvoiceGenerator::generateForProfile().
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
 * @spec openspec/specs/recurring-invoicing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\RecurringInvoiceGenerator;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/InMemoryObjectService.php';

/**
 * Pins the full generated invoice and advanced profile, the occurrence-count
 * branches and the failure path of generateForProfile().
 */
// phpcs:disable CustomSniffs.Functions.NamedParameters
final class RecurringInvoiceRunTest extends TestCase {

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the generator on an object service double.
	 *
	 * @param object $objectService The object service double.
	 *
	 * @return RecurringInvoiceGenerator
	 */
	private function generator(object $objectService): RecurringInvoiceGenerator {
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);
		$this->logger = $this->createMock(LoggerInterface::class);
		return new RecurringInvoiceGenerator(container: $container, appConfig: $appConfig, logger: $this->logger);

	}//end generator()

	/**
	 * A two-line auto-issue profile.
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 *
	 * @return array<string,mixed>
	 */
	private function profile(array $overrides): array {
		return array_merge(
			[
				'id' => 'prof-abcdef-99',
				'customerReference' => 'contact:acme',
				'administrationId' => 'adm-1',
				'frequency' => 'quarterly',
				'interval' => 1,
				'invoiceDay' => 31,
				'startDate' => '2027-01-01',
				'issueMode' => 'auto-issue',
				'paymentTermsDays' => 14,
				'status' => 'active',
				'currency' => 'EUR',
				'documentLanguage' => 'nl',
				'remainingOccurrences' => 2,
				'lines' => [
					['description' => 'Hosting {month} {year}', 'quantity' => 3, 'unitPrice' => 33.33, 'vatCode' => 21, 'revenueAccount' => '8000'],
					['description' => 'Export', 'unitPrice' => 10, 'vatCode' => 0],
				],
			],
			$overrides
		);

	}//end profile()

	/**
	 * The generated invoice and the advanced profile, in full.
	 *
	 * @return void
	 */
	public function testGeneratedInvoiceAndProfile(): void {
		$result = $this->generator(new InMemoryObjectService())->generateForProfile($this->profile([]));

		self::assertSame(
			[
				'invoice' => [
					'invoiceNumber' => 'REC-202701-PROFABCD-01',
					'periodId' => '2027-01',
					'customerId' => 'contact:acme',
					'administrationId' => 'adm-1',
					'invoiceDate' => '2027-01-31',
					'dueDate' => '2027-02-14',
					'currency' => 'EUR',
					'netAmount' => 109.99,
					'vatAmount' => 21.0,
					'grossAmount' => 130.99,
					'lifecycleState' => 'issued',
					'recurringProfileId' => 'prof-abcdef-99',
					'billingPeriod' => '2027-01',
					'invoiceLines' => [
						[
							'lineId' => '1',
							'itemName' => 'Hosting januari 2027',
							'quantity' => 3.0,
							'unitCode' => 'C62',
							'netPrice' => 33.33,
							'netAmount' => 99.99,
							'vatRate' => 21,
							'vatCategory' => 'S',
							'glAccount' => '8000',
						],
						[
							'lineId' => '2',
							'itemName' => 'Export',
							'quantity' => 1.0,
							'unitCode' => 'C62',
							'netPrice' => 10.0,
							'netAmount' => 10.0,
							'vatRate' => 0,
							'vatCategory' => 'Z',
							'glAccount' => null,
						],
					],
					'id' => 'auto-1',
				],
				'profile' => [
					'id' => 'prof-abcdef-99',
					'customerReference' => 'contact:acme',
					'administrationId' => 'adm-1',
					'frequency' => 'quarterly',
					'interval' => 1,
					'invoiceDay' => 31,
					'startDate' => '2027-01-01',
					'issueMode' => 'auto-issue',
					'paymentTermsDays' => 14,
					'status' => 'active',
					'currency' => 'EUR',
					'documentLanguage' => 'nl',
					'remainingOccurrences' => 1,
					'lines' => [
						[
							'description' => 'Hosting {month} {year}',
							'quantity' => 3,
							'unitPrice' => 33.33,
							'vatCode' => 21,
							'revenueAccount' => '8000',
						],
						[
							'description' => 'Export',
							'unitPrice' => 10,
							'vatCode' => 0,
						],
					],
					'lastBillingPeriod' => '2027-01',
					'lastRunStatus' => 'ok',
					'lastRunError' => null,
					'nextRunDate' => '2027-04-30',
				],
				'created' => true,
				'billingPeriod' => '2027-01',
			],
			$result
		);

	}//end testGeneratedInvoiceAndProfile()

	/**
	 * The last occurrence ends the profile; an unlimited profile keeps its
	 * null count.
	 *
	 * @return void
	 */
	public function testOccurrenceCounting(): void {
		$last = $this->generator(new InMemoryObjectService())->generateForProfile($this->profile(['remainingOccurrences' => 1]));
		self::assertSame([0, 'ended'], [$last['profile']['remainingOccurrences'], $last['profile']['status']]);

		$open = $this->generator(new InMemoryObjectService())->generateForProfile($this->profile(['remainingOccurrences' => null]));
		self::assertSame([null, 'active'], [$open['profile']['remainingOccurrences'], $open['profile']['status']]);

	}//end testOccurrenceCounting()

	/**
	 * A failing invoice save marks the profile failed and refuses.
	 *
	 * @return void
	 */
	public function testFailedGeneration(): void {
		// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
		$store = new class {
			public string $schema = '';

			/** @var array<int,array<string,mixed>> */
			public array $profiles = [];

			public function setRegister(string $register): static {
				return $this;
			}

			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}

			public function findAll(array $args = []): array {
				return [];
			}

			public function saveObject(array $object): array {
				if ($this->schema === 'ARInvoice') {
					throw new RuntimeException('store down');
				}

				$this->profiles[] = $object;
				return $object;
			}
		};
		// phpcs:enable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
		$generator = $this->generator($store);
		$this->logger->expects(self::once())->method('error')->with(
			'RecurringInvoiceGenerator: failed to generate invoice',
			['profileId' => 'prof-abcdef-99', 'exception' => 'store down']
		);

		try {
			$generator->generateForProfile($this->profile([]));
			self::fail('expected refusal');
		} catch (RuntimeException $e) {
			self::assertSame('Recurring invoice generation failed', $e->getMessage());
		}

		$saved = $store->profiles[0];
		self::assertSame(['failed', 'Invoice generation failed.', 2], [$saved['lastRunStatus'], $saved['lastRunError'], $saved['remainingOccurrences']]);

	}//end testFailedGeneration()
}//end class
