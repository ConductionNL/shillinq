<?php

/**
 * Golden record of RuleTestDataSeeder::seed().
 *
 * Each scenario seeds a recording object-service double, runs seed(), and
 * compares the returned counts, every findAll/saveObject call (arguments
 * recorded by parameter name) and every logged warning with
 * tests/Unit/Service/fixtures/rule-test-data-seeder-golden.json.
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
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
 * @spec openspec/changes/glline-administration-scope/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\RuleTestDataSeeder;
use OCA\Shillinq\Standards\RuleEngine;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * RuleTestDataSeeder golden tests.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class RuleTestDataSeederGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/fixtures/rule-test-data-seeder-golden.json';

	/**
	 * The scenarios, by name.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function scenarios(): array {
		return [
			'empty register, admin from the admin group' => ['empty'],
			'mixed rows, admin by uid, empty register config' => ['mixed'],
			'every save and probe fails, no admin at all' => ['failing'],
			'review cases: blank seller VAT id, lines already present, group admin before uid admin' => ['reviewed'],
		];

	}//end scenarios()

	/**
	 * seed() matches the golden record for one scenario.
	 *
	 * @param string $scenario The scenario key.
	 *
	 * @return void
	 */
	#[DataProvider('scenarios')]
	public function testSeedMatchesGolden(string $scenario): void {
		$recorded = $this->runScenario($scenario);
		$golden   = [];
		if (is_file(self::GOLDEN) === true) {
			$golden = json_decode((string) file_get_contents(self::GOLDEN), true);
		}

		if (getenv('SHILLINQ_GOLDEN_RECORD') === '1') {
			$golden[$scenario] = $recorded;
			ksort($golden);
			file_put_contents(self::GOLDEN, json_encode($golden, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)) . "\n");
		}

		self::assertSame(
			json_encode($golden[$scenario] ?? null, JSON_PRESERVE_ZERO_FRACTION),
			json_encode($recorded, JSON_PRESERVE_ZERO_FRACTION)
		);

	}//end testSeedMatchesGolden()

	/**
	 * Run seed() for a scenario and return what it did.
	 *
	 * @param string $scenario The scenario key.
	 *
	 * @return array<string, mixed>
	 */
	private function runScenario(string $scenario): array {
		$recorder = new RecordingSeederObjectService(rows: $this->rows($scenario), failing: $scenario === 'failing');
		$warnings = [];
		$logger   = $this->createStub(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);

		$seeder = new RuleTestDataSeeder(
			appConfig: $this->appConfig(value: $scenario === 'mixed' ? '' : 'books'),
			userManager: $this->userManager(scenario: $scenario),
			groupManager: $this->groupManager(scenario: $scenario),
			logger: $logger,
			objectService: new DuckObjectServiceAdapter($recorder),
		);

		$counts = $seeder->seed();
		return ['counts' => $counts, 'calls' => $recorder->calls, 'warnings' => $warnings];

	}//end runScenario()

	/**
	 * The stored rows per schema for a scenario.
	 *
	 * @param string $scenario The scenario key.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	private function rows(string $scenario): array {
		if ($scenario === 'empty') {
			return [];
		}

		if ($scenario === 'reviewed') {
			return [
				'GLTransaction' => [['id' => 'tx-eeeeeeee-5', 'sourceReference' => '']],
				'GLLine' => [['transactionId' => 'tx-eeeeeeee-5'], ['transactionId' => 'tx-eeeeeeee-5']],
				'ARInvoice' => [
					[
						'id' => 'ar-blank-vat-id',
						'invoiceLines' => [['vatRate' => 21]],
						'allowancesTotal' => 0,
						'invoiceTypeCode' => '380',
						'sellerVatId' => ' ',
					],
				],
			];
		}

		$specTypes   = array_keys(array_filter(RuleEngine::providerSeedSpecs()));
		$sampleTypes = array_keys(array_filter(RuleEngine::providerSeedObjects()));
		$rows        = [
			'GLTransaction' => [
				['id' => 'tx-aaaaaaaa-1', 'transactionNumber' => 'MEM-1', 'sourceReference' => '', 'administrationId' => 'adm-1'],
				['id' => 'tx-bbbbbbbb-2', 'sourceReference' => 'DOC-2', 'currency' => 'USD', '@self' => ['id' => 'ignored']],
				new JsonRow(['@self' => ['id' => 'tx-cccccccc-3'], 'sourceReference' => '  ', 'transactionNumber' => '']),
				['id' => 'tx-dddddddd-4', 'transactionNumber' => 'MEM-4', 'sourceReference' => 'DOC-4'],
			],
			'GLLine' => [
				['transactionId' => 'tx-bbbbbbbb-2'],
				['transactionId' => 'tx-bbbbbbbb-2'],
				['transactionId' => 'MEM-4'],
				['transactionId' => 'MEM-4'],
				['transactionId' => 'MEM-4'],
				['transactionId' => 'tx-cccccccc-3'],
			],
			'APTransaction' => [
				['id' => 'ap-1', 'sourceDocumentUri' => 'doc://x'],
				['id' => 'ap-22222222-2', 'invoiceNumber' => 'INV-2', 'sourceDocumentUri' => ' '],
				new JsonRow(['@self' => ['id' => 'ap-33333333-3'], 'invoiceNumber' => '']),
			],
			'ARInvoice' => [
				[
					'id' => 'ar-full',
					'invoiceLines' => [['vatRate' => 21]],
					'allowancesTotal' => 0,
					'sellerVatId' => 'NL1',
					'invoiceTypeCode' => '380',
				],
				['id' => 'ar-zero', 'netAmount' => 100, 'vatAmount' => 0.004, 'invoiceDate' => '2026-01-02'],
				['id' => 'ar-cents', 'netAmount' => 1, 'vatAmount' => 0.04],
				['id' => 'ar-std', 'netAmount' => '200', 'vatAmount' => '42', 'currency' => 'USD', 'sellerName' => 'Own B.V.', 'invoiceTypeCode' => '381'],
				['id' => 'ar-nonet', 'netAmount' => 0, 'vatAmount' => 5, 'invoiceLines' => [['vatRate' => 21]], 'allowancesTotal' => 0, 'sellerVatId' => ' '],
				new JsonRow(['@self' => ['id' => 'ar-json'], 'invoiceLines' => ['not-an-array'], 'supplyDate' => '2026-02-03']),
			],
		];
		if (isset($sampleTypes[0]) === true) {
			$rows[$sampleTypes[0]] = [['id' => 'existing-sample']];
		}

		if (isset($specTypes[0]) === true) {
			$fields = array_keys(RuleEngine::providerSeedSpecs()[$specTypes[0]]);
			$first  = $fields[0];
			$rows[$specTypes[0]] = array_merge(
				($rows[$specTypes[0]] ?? []),
				[
					array_merge(['id' => 'spec-full'], array_fill_keys($fields, 'set')),
					['id' => 'spec-missing'],
					['id' => 'spec-blank', $first => '  '],
					new JsonRow(['@self' => ['id' => 'spec-json'], $first => null]),
				]
			);
		}

		return $rows;

	}//end rows()

	/**
	 * App config answering the register key.
	 *
	 * @param string $value The configured register.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(string $value): IAppConfig {
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => ($app === 'shillinq' && $key === 'register') ? $value : $default
		);
		return $appConfig;

	}//end appConfig()

	/**
	 * Group manager for a scenario: an admin group with two users, or none.
	 *
	 * @param string $scenario The scenario key.
	 *
	 * @return IGroupManager
	 */
	private function groupManager(string $scenario): IGroupManager {
		$groupManager = $this->createStub(IGroupManager::class);
		$group        = null;
		if ($scenario === 'empty') {
			$group = $this->createStub(IGroup::class);
			$group->method('getUsers')->willReturn([$this->user(uid: 'group-admin'), $this->user(uid: 'second-admin')]);
		}

		if ($scenario === 'reviewed') {
			$group = $this->createStub(IGroup::class);
			$group->method('getUsers')->willReturn([$this->user(uid: 'carol')]);
		}

		if ($scenario === 'failing') {
			$group = $this->createStub(IGroup::class);
			$group->method('getUsers')->willReturn([]);
		}

		$groupManager->method('get')->willReturnCallback(static fn (string $gid): ?IGroup => $gid === 'admin' ? $group : null);
		return $groupManager;

	}//end groupManager()

	/**
	 * User manager for a scenario: `admin` resolves only in the mixed one.
	 *
	 * @param string $scenario The scenario key.
	 *
	 * @return IUserManager
	 */
	private function userManager(string $scenario): IUserManager {
		$userManager = $this->createStub(IUserManager::class);
		$admin       = in_array($scenario, ['mixed', 'reviewed'], true) ? $this->user(uid: 'uid-admin') : null;
		$userManager->method('get')->willReturnCallback(static fn (string $uid): ?IUser => $uid === 'admin' ? $admin : null);
		return $userManager;

	}//end userManager()

	/**
	 * A user stub with a uid.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;

	}//end user()
}//end class

/**
 * A stored row that reaches the seeder as an entity with jsonSerialize().
 */
final class JsonRow implements \JsonSerializable {
	/**
	 * Wrap a row.
	 *
	 * @param array<string, mixed> $row The row.
	 */
	public function __construct(private readonly array $row) {
	}//end __construct()

	/**
	 * The row.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return $this->row;
	}//end jsonSerialize()
}//end class

/**
 * An object-service double that serves fixed rows and records every
 * findAll/saveObject call with its arguments by parameter name.
 */
final class RecordingSeederObjectService {

	/**
	 * Recorded calls.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $calls = [];

	/**
	 * Serve rows; when failing, every save and every non-core probe throws.
	 *
	 * @param array<string, array<int, mixed>> $rows    Rows per schema.
	 * @param bool                             $failing Whether calls fail.
	 */
	public function __construct(private readonly array $rows, private readonly bool $failing) {
	}//end __construct()

	/**
	 * Select the register (state lives on the adapter).
	 *
	 * @param string $register The register.
	 *
	 * @return self
	 */
	public function setRegister(string $register): self {
		return $this;
	}//end setRegister()

	/**
	 * Select the schema (state lives on the adapter).
	 *
	 * @param string $schema The schema.
	 *
	 * @return self
	 */
	public function setSchema(string $schema): self {
		return $this;
	}//end setSchema()

	/**
	 * Return the schema's rows, filtered on `transactionId` when asked.
	 *
	 * @param array  $config   The query.
	 * @param string $register The register.
	 * @param string $schema   The schema.
	 *
	 * @return array
	 */
	public function findAll(array $config = [], string $register = '', string $schema = ''): array {
		$this->calls[] = ['call' => 'findAll', 'register' => $register, 'schema' => $schema, 'config' => $config];
		$core          = ['GLTransaction', 'GLLine', 'APTransaction', 'ARInvoice'];
		if ($this->failing === true && in_array($schema, $core, true) === false) {
			throw new \RuntimeException('probe failed for ' . $schema);
		}

		$rows = ($this->rows[$schema] ?? []);
		if (isset($config['filters']['transactionId']) === true) {
			$key  = $config['filters']['transactionId'];
			$rows = array_values(array_filter($rows, static fn ($row): bool => (($row['transactionId'] ?? null) === $key)));
		}

		if (isset($config['limit']) === true) {
			$rows = array_slice($rows, 0, $config['limit']);
		}

		return $rows;

	}//end findAll()

	/**
	 * Record a save; throw when failing.
	 *
	 * @param array      $object        The payload.
	 * @param string     $register      The register.
	 * @param string     $schema        The schema.
	 * @param ?string    $uuid          The uuid.
	 * @param bool       $_rbac         RBAC flag.
	 * @param bool       $_multitenancy Multitenancy flag.
	 * @param ?IUser     $currentUser   Acting user.
	 *
	 * @return array
	 */
	public function saveObject(
		array $object,
		string $register = '',
		string $schema = '',
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		?IUser $currentUser = null
	): array {
		$this->calls[] = [
			'call' => 'saveObject',
			'register' => $register,
			'schema' => $schema,
			'uuid' => $uuid,
			'_rbac' => $_rbac,
			'_multitenancy' => $_multitenancy,
			'currentUser' => $currentUser?->getUID(),
			'object' => $object,
		];
		if ($this->failing === true) {
			throw new \RuntimeException('save failed for ' . $schema);
		}

		return $object;

	}//end saveObject()
}//end class
