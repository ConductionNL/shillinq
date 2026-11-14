<?php

/**
 * Unit tests for how ContractLeafProvider reads contract rows: get() returns
 * the one linked contract by id, and malformed rows or links are skipped.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Integration
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

namespace OCA\Shillinq\Tests\Unit\Integration;

use OCA\Shillinq\Integration\ContractLeafProvider;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ContractLeafProvider's row reading.
 */
#[CoversClass(ContractLeafProvider::class)]
final class ContractLeafProviderRowsTest extends TestCase {

	/**
	 * The scope the provider was read with, and the findAll params.
	 *
	 * @var array<string, mixed>
	 */
	private array $reads = [];

	/**
	 * A provider over the given rows.
	 *
	 * @param array<int, mixed> $rows What findAll() returns.
	 *
	 * @return ContractLeafProvider
	 */
	private function provider(array $rows): ContractLeafProvider {
		$reads = &$this->reads;
		$double = new class($rows, $reads) {
			/**
			 * @param array<int, mixed>    $rows  Rows.
			 * @param array<string, mixed> $reads Read log.
			 */
			public function __construct(private array $rows, private array &$reads) {
			}

			public function setRegister(string $register): static {
				$this->reads['register'] = $register;
				return $this;
			}

			public function setSchema(string $schema): static {
				$this->reads['schema'] = $schema;
				return $this;
			}

			public function findAll(array $params = []): array {
				$this->reads['params'] = $params;
				return $this->rows;
			}
		};

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->with('shillinq', 'register', 'shillinq')->willReturn('finance');

		return new ContractLeafProvider(objectService: new DuckObjectServiceAdapter(inner: $double), appConfig: $appConfig);
	}//end provider()

	/**
	 * A contract row linked to $caseId.
	 *
	 * @param string $id     The contract id.
	 * @param string $caseId The linked case.
	 *
	 * @return array<string, mixed>
	 */
	private function contract(string $id, string $caseId = 'zaak-7'): array {
		return [
			'id'            => $id,
			'title'         => 'Contract ' . $id,
			'status'        => 'active',
			'linkedObjects' => [['register' => 'zaken', 'schema' => 'zaak', 'id' => $caseId]],
		];
	}//end contract()

	/**
	 * get() returns the requested contract among several linked ones.
	 *
	 * @return void
	 */
	public function testGetReturnsTheRequestedLinkedContract(): void {
		$provider = $this->provider([$this->contract('c-1'), $this->contract('c-2'), $this->contract('c-3', 'zaak-8')]);

		$contract = $provider->get('zaken', 'zaak', 'zaak-7', 'c-2');

		$this->assertSame('c-2', $contract['id']);
		$this->assertSame('Contract c-2', $contract['title']);
		$this->assertSame(['register' => 'finance', 'schema' => 'Contract', 'params' => ['limit' => 500]], $this->reads);
	}//end testGetReturnsTheRequestedLinkedContract()

	/**
	 * Non-array rows, rows without a link list and non-array links are skipped;
	 * a contract counts once even when linked twice.
	 *
	 * @return void
	 */
	public function testMalformedRowsAndLinksAreSkipped(): void {
		$twice = $this->contract('c-9');
		$twice['linkedObjects'][] = ['register' => 'zaken', 'schema' => 'zaak', 'id' => 'zaak-7'];
		$withJunkLink = $this->contract('c-5');
		array_unshift($withJunkLink['linkedObjects'], 'zaak-7');

		$listed = $this->provider(
			[
				'not-a-row',
				['id' => 'c-0', 'linkedObjects' => 'zaak-7'],
				['id' => 'c-00'],
				$withJunkLink,
				$twice,
			]
		)->list('zaken', 'zaak', 'zaak-7');

		$this->assertSame(['c-5', 'c-9'], array_column($listed['items'], 'id'));
		$this->assertSame(2, $listed['total']);
		$this->assertNull($listed['nextCursor']);
	}//end testMalformedRowsAndLinksAreSkipped()
}//end class
