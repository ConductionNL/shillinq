<?php

/**
 * Golden characterisation of the SEPA mandate audit dossier.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\SepaAuditService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ZipArchive;

/**
 * Pins the ZIP entries, the queries and the tenant refusals.
 */
final class SepaAuditDossierGoldenTest extends TestCase {

	/**
	 * Recorded queries and log lines.
	 *
	 * @var array<int,mixed>
	 */
	private array $events = [];

	/**
	 * Build the subject over rows per schema; the caller may read `adm-1`.
	 *
	 * @param array<string,array<int,mixed>> $rows Schema => rows.
	 *
	 * @return SepaAuditService
	 */
	private function subject(array $rows): SepaAuditService {
		$events = &$this->events;
		$state = ['schema' => ''];
		$store = $this->createMock(ObjectServiceInterface::class);
		$store->method('setRegister')->willReturnCallback(
			function (string $register) use (&$events, $store): ObjectServiceInterface {
				$events[] = ['register', $register];
				return $store;
			}
		);
		$store->method('setSchema')->willReturnCallback(
			function (string $schema) use (&$state, $store): ObjectServiceInterface {
				$state['schema'] = $schema;
				return $store;
			}
		);
		$store->method('findAll')->willReturnCallback(
			function (array $config) use (&$state, &$events, $rows): array {
				$events[] = ['find', $state['schema'], $config];
				$filters = ($config['filters'] ?? []);
				return array_filter(
					($rows[$state['schema']] ?? []),
					static fn ($row): bool => is_array($row) === false || array_intersect_assoc($filters, $row) === $filters
				);
			}
		);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(
			function (string $administrationId) use (&$events): bool {
				$events[] = ['canAccess', $administrationId];
				return $administrationId === 'adm-1';
			}
		);
		$logger = $this->createMock(LoggerInterface::class);

		return new SepaAuditService($config, $context, $logger, $store);

	}//end subject()

	/**
	 * The ZIP's entries, in order, by name and content.
	 *
	 * @param string $bytes Raw ZIP bytes.
	 *
	 * @return array<string,string>
	 */
	private static function entries(string $bytes): array {
		$path = (string)tempnam(sys_get_temp_dir(), 'sepa-golden-');
		file_put_contents($path, $bytes);
		$zip = new ZipArchive();
		$zip->open($path);
		$entries = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = (string)$zip->getNameIndex($i);
			$entries[$name] = (string)$zip->getFromIndex($i);
		}
		$zip->close();
		unlink($path);

		return $entries;

	}//end entries()

	/**
	 * Rows for one accessible mandate, one foreign and one untagged.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	private static function rows(): array {
		return [
			'SepaMandate' => [
				['id' => 'm-1', 'administrationId' => 'adm-1', 'mandateReference' => 'MND/2026 #1', 'iban' => 'NL01'],
				['id' => 'm-2', 'administrationId' => 'adm-2', 'mandateReference' => 'FOREIGN'],
				['id' => 'm-3', 'mandateReference' => 'UNTAGGED'],
				['id' => 'm-4', 'administrationId' => 'adm-1'],
			],
			'DirectDebitCollection' => [
				['id' => 'c-1', 'mandateId' => 'm-1', 'endToEndId' => 'E2E/1', 'amount' => 12.5, 'currency' => 'EUR', 'status' => 'settled, late', '_pain008Xml' => '<xml>1</xml>'],
				['mandateId' => 'm-1', 'endToEndId' => 'no-id', 'pain002ReasonCode' => 'say "AM04"'],
				'scalar-row',
				['id' => 'c-3', 'mandateId' => 'm-1', '_pain008Xml' => '<xml>3</xml>'],
				['id' => 'c-9', 'mandateId' => 'm-2', 'endToEndId' => 'SECRET'],
			],
			'RTransaction' => [
				['id' => 'r-1', 'collectionId' => 'c-1', 'reasonCode' => 'MD06'],
				['id' => 'r-3', 'collectionId' => 'c-3'],
				['id' => 'r-9', 'collectionId' => 'c-9'],
			],
			'PreNotification' => [['id' => 'p-1', 'collectionId' => 'c-3', 'text' => "line1\nline2"]],
		];

	}//end rows()

	/**
	 * Pin the dossier of an accessible mandate: entries, filename and queries.
	 *
	 * @return void
	 */
	public function testAccessibleDossierIsExact(): void {
		$this->events = [];
		$result = $this->subject(self::rows())->buildMandateDossier('m-1');
		$entries = self::entries($result['data']);
		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$rows = self::rows();
		$collections = [$rows['DirectDebitCollection'][0], $rows['DirectDebitCollection'][1], 'scalar-row', $rows['DirectDebitCollection'][3]];

		self::assertSame('sepa-dossier-MND_2026__1.zip', $result['filename']);
		self::assertSame(
			[
				'mandate.json' => json_encode($rows['SepaMandate'][0], $flags),
				'collections.csv' => "endToEndId,amount,currency,sequenceType,requestedCollectionDate,status,pain002ReasonCode\n"
					. "E2E/1,12.5,EUR,,,\"settled, late\",\n"
					. "no-id,,,,,,\"say \"\"AM04\"\"\"\n"
					. ",,,,,,\n",
				'collections.json' => json_encode($collections, $flags),
				'r-transactions.json' => json_encode([$rows['RTransaction'][0], $rows['RTransaction'][1]], $flags),
				'pre-notifications.json' => json_encode([$rows['PreNotification'][0]], $flags),
				'pain/E2E_1.pain008.xml' => '<xml>1</xml>',
				'pain/collection.pain008.xml' => '<xml>3</xml>',
			],
			$entries
		);
		self::assertSame(
			[
				['register', 'shillinq'], ['find', 'SepaMandate', ['filters' => ['id' => 'm-1']]],
				['canAccess', 'adm-1'],
				['register', 'shillinq'], ['find', 'DirectDebitCollection', ['filters' => ['mandateId' => 'm-1']]],
				['register', 'shillinq'], ['find', 'RTransaction', ['filters' => ['collectionId' => 'c-1']]],
				['register', 'shillinq'], ['find', 'RTransaction', ['filters' => ['collectionId' => 'c-3']]],
				['register', 'shillinq'], ['find', 'PreNotification', ['filters' => ['collectionId' => 'c-1']]],
				['register', 'shillinq'], ['find', 'PreNotification', ['filters' => ['collectionId' => 'c-3']]],
			],
			$this->events
		);

		$bare = $this->subject(self::rows())->buildMandateDossier('m-4');
		self::assertSame('sepa-dossier-m-4.zip', $bare['filename']);
		self::assertSame(['mandate.json', 'collections.csv', 'collections.json', 'r-transactions.json', 'pre-notifications.json'], array_keys(self::entries($bare['data'])));

	}//end testAccessibleDossierIsExact()

	/**
	 * Allowed and refused: an empty id, an unknown, a foreign and an untagged
	 * mandate all answer null, and nothing beyond the mandate is read.
	 *
	 * @return void
	 */
	public function testRefusalsReadNothingBeyondTheMandate(): void {
		foreach (['' => [], 'm-x' => [], 'm-2' => ['adm-2'], 'm-3' => ['']] as $mandateId => $checked) {
			$this->events = [];
			self::assertNull($this->subject(self::rows())->buildMandateDossier((string)$mandateId));
			self::assertSame($checked, array_column(array_filter($this->events, static fn (array $e): bool => $e[0] === 'canAccess'), 1));
			self::assertSame([], array_filter($this->events, static fn (array $e): bool => $e[0] === 'find' && $e[1] !== 'SepaMandate'));
		}

	}//end testRefusalsReadNothingBeyondTheMandate()
}//end class
