<?php

/**
 * Unit tests for IncassoDossierComposer.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Dunning
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

namespace OCA\Shillinq\Tests\Unit\Service\Dunning;

use OCA\Shillinq\Service\Dunning\IncassoDossierComposer;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Characterisation tests for the stage-5 dossier bundle.
 */
final class IncassoDossierComposerTest extends TestCase {

	/**
	 * Build a fluent ObjectService stand-in that answers per schema and records
	 * every query.
	 *
	 * @param array<string, mixed> $rowsBySchema Rows (or a Throwable) per schema.
	 * @param array<int, mixed>    $queries      Recorded queries (by reference).
	 *
	 * @return object
	 */
	private function objectService(array $rowsBySchema, array &$queries): object {
		return new class($rowsBySchema, $queries) {
			private string $register = '';
			private string $schema = '';

			public function __construct(private array $rows, private array &$queries) {
			}

			public function setRegister(string $register): self {
				$this->register = $register;
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}

			public function findAll(array $config): mixed {
				$this->queries[] = [$this->register, $this->schema, $config];
				$rows = ($this->rows[$this->schema] ?? null);
				if ($rows instanceof \Throwable) {
					throw $rows;
				}
				return $rows;
			}
		};

	}//end objectService()

	/**
	 * Build the composer with the given rows and register setting.
	 *
	 * @param array<string, mixed> $rowsBySchema Rows per schema.
	 * @param string               $register     Configured register value.
	 * @param array<int, mixed>    $queries      Recorded queries (by reference).
	 * @param array<int, string>   $warnings     Recorded warnings (by reference).
	 *
	 * @return IncassoDossierComposer
	 */
	private function composer(array $rowsBySchema, string $register, array &$queries, array &$warnings): IncassoDossierComposer {
		$service = $this->objectService($rowsBySchema, $queries);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($service);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->with('shillinq', 'register', 'shillinq')->willReturn($register);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);
		return new IncassoDossierComposer($container, $config, $logger);

	}//end composer()

	/**
	 * Pin the whole bundle: queries, latest calculation, evidence references
	 * and the customer and invoice headers.
	 *
	 * @return void
	 */
	public function testComposesTheFullBundle(): void {
		$queries = [];
		$warnings = [];
		$runs = [
			['id' => 'r1', 'renderedPdfHash' => 'abc', 'postageStatus' => ['barcode' => '3SABC']],
			['id' => 'r2', 'renderedPdfHash' => ''],
			['renderedPdfHash' => 'def', 'postageStatus' => ['barcode' => '']],
			['postageStatus' => ['barcode' => 'X9']],
		];
		$costs = [
			['n' => 1, 'statutoryRente' => ['calculatedOn' => '2026-01-02']],
			['n' => 2, 'statutoryRente' => ['calculatedOn' => '2026-03-01']],
			['n' => 3],
		];
		$pauses = [['p' => 1]];
		$composer = $this->composer(
			['DunningRun' => $runs, 'IncassoKostenBerekening' => $costs, 'DunningPauseDispute' => $pauses],
			'reg-x',
			$queries,
			$warnings
		);

		$out = $composer->compose('adm-1', 'inv-9', 'cus-4');

		$filters = ['filters' => ['administrationId' => 'adm-1', 'invoiceId' => 'inv-9']];
		self::assertSame(
			[
				['reg-x', 'DunningRun', $filters],
				['reg-x', 'IncassoKostenBerekening', $filters],
				['reg-x', 'DunningPauseDispute', $filters],
			],
			$queries
		);
		self::assertSame(
			[
				'invoiceId' => 'inv-9',
				'content' => [
					'invoice' => ['invoiceId' => 'inv-9', 'customerId' => 'cus-4', 'administrationId' => 'adm-1'],
					'dunningRuns' => $runs,
					'incassoKosten' => ['n' => 2, 'statutoryRente' => ['calculatedOn' => '2026-03-01']],
					'pauseEvents' => $pauses,
					'klantGegevens' => ['customerId' => 'cus-4'],
					'evidenceRefs' => ['dunning-run:r1:sha256=abc', 'postnl:3SABC', 'dunning-run::sha256=def', 'postnl:X9'],
				],
			],
			$out
		);
		self::assertSame([], $warnings);

	}//end testComposesTheFullBundle()

	/**
	 * An empty register setting falls back to `shillinq`; a failing or non-array
	 * lookup yields an empty list (logged on failure) and no calculation.
	 *
	 * @return void
	 */
	public function testFallsBackOnEmptyRegisterAndFailedLookups(): void {
		$queries = [];
		$warnings = [];
		$composer = $this->composer(
			['DunningRun' => 'not-a-list', 'IncassoKostenBerekening' => new \RuntimeException('down')],
			'',
			$queries,
			$warnings
		);

		$out = $composer->compose('a', 'i', 'c');

		self::assertSame(['shillinq', 'shillinq', 'shillinq'], array_column($queries, 0));
		self::assertSame([], $out['content']['dunningRuns']);
		self::assertNull($out['content']['incassoKosten']);
		self::assertSame([], $out['content']['pauseEvents']);
		self::assertSame([], $out['content']['evidenceRefs']);
		self::assertSame(['Shillinq: IncassoDossierComposer findAll(IncassoKostenBerekening) failed: down'], $warnings);

	}//end testFallsBackOnEmptyRegisterAndFailedLookups()
}//end class
