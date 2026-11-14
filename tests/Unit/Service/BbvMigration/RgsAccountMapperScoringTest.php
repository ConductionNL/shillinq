<?php

/**
 * Characterisation tests for RgsAccountMapper scoring, threshold and read fallbacks.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\BbvMigration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-bbv-compliance/specs/bookkeeping-bbv-compliance/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\BbvMigration;

use OCA\Shillinq\Service\BbvMigration\RgsAccountMapper;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * An object service whose reads answer rows or throw, per schema.
 */
final class RgsFixtureObjectService {

	/**
	 * The schemas read, in order.
	 *
	 * @var list<string>
	 */
	public array $reads = [];

	/**
	 * The schema of the next read.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * @param array<string, mixed> $rows schema => rows, or schema => 'throw:<message>'.
	 */
	public function __construct(
		private readonly array $rows,
	) {
	}

	public function setRegister(string $register): self {
		$this->reads[] = 'register ' . $register;
		return $this;
	}

	public function setSchema(string $schema): self {
		$this->schema = $schema;
		return $this;
	}

	/**
	 * @param array<string, mixed> $params The query.
	 *
	 * @return array<int, mixed>
	 */
	public function findAll(array $params = []): array {
		$this->reads[] = $this->schema . ' ' . json_encode($params);
		$rows = $this->rows[$this->schema] ?? [];
		if (is_string($rows) === true) {
			throw new RuntimeException(substr($rows, 6));
		}

		return $rows;
	}
}

/**
 * Pins every scoring branch, the threshold parsing and the read fallbacks.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class RgsAccountMapperScoringTest extends TestCase {

	/**
	 * Build the mapper over the given rows and threshold config.
	 *
	 * @param RgsFixtureObjectService|null $objectService The store (null: container fails).
	 * @param string                       $threshold     The configured threshold.
	 *
	 * @return RgsAccountMapper The mapper.
	 */
	private function mapper(?RgsFixtureObjectService $objectService, string $threshold = '70'): RgsAccountMapper {
		$container = $this->createMock(ContainerInterface::class);
		if ($objectService === null) {
			$container->method('get')->willThrowException(new RuntimeException('no openregister'));
		} else {
			$container->method('get')->willReturn($objectService);
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => $key === 'register' ? 'books' : $threshold
		);

		return new RgsAccountMapper(container: $container, appConfig: $appConfig);

	}//end mapper()

	/**
	 * The RGS candidates.
	 *
	 * @return list<mixed>
	 */
	private static function rgs(): array {
		return [
			['id' => 'r1', 'referentienummer' => 'REF-1', 'rgsDecentraalCode' => 'D1', 'rgsCode' => 'C1', 'omschrijvingKort' => 'Kas'],
			['id' => 'r2', 'referentienummer' => 'REF-1', 'omschrijvingKort' => 'Kas tweede'],
			['id' => 'r3', 'rgsDecentraalCode' => 'A100', 'name' => 'Debiteuren'],
			['id' => 'r4', 'rgsCode' => 'A200', 'omschrijvingKort' => 'Crediteuren'],
			['id' => 'r5', 'omschrijvingKort' => 'Bank lopende rekening'],
			['id' => 'r6', 'name' => 'Bank lopende rekening courant'],
			['id' => 'r7', 'omschrijvingKort' => ''],
			new class {
				/**
				 * @return array<string, mixed>
				 */
				public function jsonSerialize(): array {
					return ['id' => 'r8', 'rgsCode' => 'A300', 'omschrijvingKort' => 'Voorraad'];
				}
			},
		];

	}//end rgs()

	/**
	 * Accounts and the suggestion each yields (null: none).
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string, 2: array<string, mixed>|null}> Cases.
	 */
	public static function accounts(): array {
		return [
			'reference wins, first exact'   => [['id' => 'a1', 'referentienummer' => 'REF-1', 'code' => 'A100', 'name' => 'x'], '70', ['r1', 100, 'exact-referentienummer']],
			'decentraal code'               => [['uuid' => 'a2', 'accountNumber' => 'A100', 'name' => 'Kas'], '70', ['r3', 95, 'exact-rgsDecentraalCode']],
			'rgs code beats fuzzy'          => [['id' => 'a3', 'code' => 'A200', 'name' => 'Kas'], '70', ['r4', 80, 'exact-rgsCode']],
			'entity candidate'              => [['id' => 'a4', 'code' => 'A300'], '70', ['r8', 80, 'exact-rgsCode']],
			'fuzzy best name, lowered'      => [['id' => 'a5', 'name' => 'BANK LOPENDE REKENING'], '50', ['r5', 70, 'fuzzy-name-match-100%']],
			'fuzzy under threshold'         => [['id' => 'a6', 'name' => 'Bank lopende rekening'], '71', null],
			'fuzzy under half'              => [['id' => 'a7', 'name' => 'zzzzzzzz'], '0', null],
			'no identifiers'                => [['id' => 'a8'], '0', null],
			'threshold out of range'        => [['id' => 'a9', 'name' => 'Bank lopende rekening'], '150', ['r5', 70, 'fuzzy-name-match-100%']],
			'threshold negative'            => [['id' => 'b1', 'name' => 'Bank lopende rekeni'], '-1', null],
			'threshold not a number'        => [['id' => 'b2', 'name' => 'Kas'], 'abc', ['r1', 70, 'fuzzy-name-match-100%']],
		];

	}//end accounts()

	/**
	 * Each account yields its pinned suggestion.
	 *
	 * @param array<string, mixed>   $account   The account.
	 * @param string                 $threshold The configured threshold.
	 * @param array<int, mixed>|null $expected  [candidate id, confidence, reason] or null.
	 *
	 * @return void
	 */
	#[DataProvider('accounts')]
	public function testSuggestion(array $account, string $threshold, ?array $expected): void {
		$result = $this->mapper(new RgsFixtureObjectService(['Account' => [$account], 'RgsDecentraalRekening' => self::rgs()]), $threshold)
			->suggestMappings('adm-1');

		self::assertTrue($result['success']);
		if ($expected === null) {
			self::assertSame([], $result['suggestions']);
			return;
		}

		self::assertCount(1, $result['suggestions']);
		$suggestion = $result['suggestions'][0];
		self::assertSame($expected, [$suggestion['candidate']['id'], $suggestion['confidence'], $suggestion['scoringReason']]);
		self::assertSame((string)($account['id'] ?? $account['uuid']), $suggestion['accountId']);
		self::assertSame((string)($account['code'] ?? $account['accountNumber'] ?? ''), $suggestion['accountNumber']);
		self::assertSame((string)($account['name'] ?? ''), $suggestion['accountName']);

	}//end testSuggestion()

	/**
	 * Mapped accounts are skipped (by empty() semantics); objects are read
	 * as arrays; non-arrays become empty rows.
	 *
	 * @return void
	 */
	public function testSkippedAndRowShapes(): void {
		$entity = new class {
			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'e1', 'code' => 'A100'];
			}
		};
		$plain = new class {
			/**
			 * @var string
			 */
			public string $id = 'p1';

			/**
			 * @var string
			 */
			public string $code = 'A200';
		};
		$accounts = [
			['id' => 'm1', 'rgsDecentraalCode' => 'X'],
			['id' => 'm2', 'rgsDecentraalCode' => '0', 'code' => 'A100'],
			$entity,
			$plain,
			'junk',
		];
		$store = new RgsFixtureObjectService(['Account' => $accounts, 'RgsDecentraalRekening' => self::rgs()]);
		$result = $this->mapper($store)->suggestMappings('adm-1');

		self::assertSame(1, $result['skipped']);
		self::assertSame(['m2', 'e1', 'p1'], array_column($result['suggestions'], 'accountId'));
		self::assertSame(
			[
				'register books',
				'Account {"filters":{"administrationId":"adm-1"},"limit":5000}',
				'register books',
				'RgsDecentraalRekening {"limit":5000}',
			],
			$store->reads
		);

	}//end testSkippedAndRowShapes()

	/**
	 * Read failures and the mapping-register fallback.
	 *
	 * @return void
	 */
	public function testReadFailuresAndFallback(): void {
		self::assertSame(
			['success' => false, 'suggestions' => [], 'skipped' => 0, 'message' => 'no openregister'],
			$this->mapper(null)->suggestMappings('adm-1')
		);
		self::assertSame(
			['success' => false, 'suggestions' => [], 'skipped' => 0, 'message' => 'accounts down'],
			$this->mapper(new RgsFixtureObjectService(['Account' => 'throw:accounts down']))->suggestMappings('adm-1')
		);
		self::assertSame(
			['success' => false, 'suggestions' => [], 'skipped' => 0, 'message' => 'mapping down'],
			$this->mapper(new RgsFixtureObjectService(['RgsDecentraalRekening' => 'throw:rgs down', 'BbvAccountMapping' => 'throw:mapping down']))->suggestMappings('adm-1')
		);

		$store = new RgsFixtureObjectService(['Account' => [['id' => 'a', 'code' => 'A100']], 'RgsDecentraalRekening' => 'throw:rgs down', 'BbvAccountMapping' => self::rgs()]);
		$result = $this->mapper($store)->suggestMappings('adm-1');
		self::assertSame(['r3'], array_map(static fn (array $s): string => $s['candidate']['id'], $result['suggestions']));
		self::assertSame('BbvAccountMapping {"limit":5000}', $store->reads[5]);

	}//end testReadFailuresAndFallback()
}//end class
