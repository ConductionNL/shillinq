<?php

/**
 * Unit tests for SepaAuditService.
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
 * @spec openspec/changes/bookkeeping-sepa-direct-debit/specs/bookkeeping-sepa-direct-debit/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\SepaAuditService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ZipArchive;

/**
 * Fluent fake serving per-schema rows keyed by simple filter equality.
 */
final class FakeSepaObjectService {

	/**
	 * @var array<string, array<int, array<string,mixed>>>
	 */
	private array $rows;

	private string $schema = '';

	/**
	 * @param array<string, array<int, array<string,mixed>>> $rows Seed rows.
	 */
	public function __construct(array $rows) {
		$this->rows = $rows;

	}//end __construct()

	public function setRegister(string $r): self {
		return $this;
	}//end setRegister()

	public function setSchema(string $s): self {
		$this->schema = $s;
		return $this;
	}//end setSchema()

	/**
	 * @param array<string,mixed> $opts Find options — uses 'filters' nested map.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public function findAll(array $opts = []): array {
		$rows = ($this->rows[$this->schema] ?? []);
		$filters = ($opts['filters'] ?? []);

		return array_values(array_filter(
			$rows,
			static function (array $row) use ($filters): bool {
				foreach ($filters as $k => $v) {
					if (($row[$k] ?? null) !== $v) {
						return false;
					}
				}

				return true;
			}
		));

	}//end findAll()
}//end class

/**
 * Verifies REQ-SDD-010 audit dossier assembly:
 * - empty mandate id → null
 * - missing mandate → null
 * - tenant scoping (mandate outside administration → null, IDOR safe)
 * - happy path returns ZIP with mandate.json + collections.csv +
 *   collections.json + r-transactions.json + pre-notifications.json
 * - archived pain.008 fragments added per collection that has one
 * - safe filename slugging
 * - CSV escapes commas / quotes
 *
 * @spec openspec/changes/bookkeeping-sepa-direct-debit/specs/bookkeeping-sepa-direct-debit/spec.md
 */
final class SepaAuditServiceTest extends TestCase {

	/**
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * Build the subject with the CALLER's administration memberships + fake OR rows.
	 *
	 * ⚠️ `$admin` used to be `appConfig('administration_id')` — an instance-wide
	 * constant with no relation to the calling user. It is now the
	 * administration the caller is actually a member of, which is what
	 * AdministrationContextService::canAccess() answers on. An empty string
	 * means "member of nothing" and, unlike before, refuses everything: the old
	 * empty value SKIPPED the comparison entirely.
	 *
	 * @param string $admin The administration the caller is a member of ('' = none).
	 * @param array<string, array<int, array<string,mixed>>> $rows Seed rows per schema.
	 *
	 * @return SepaAuditService
	 */
	private function svc(string $admin, array $rows): SepaAuditService {
		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(
			static fn (string $administrationId): bool => $administrationId !== '' && $administrationId === $admin
		);

		$fake = new FakeSepaObjectService($rows);
		$this->container->method('get')->willReturn($fake);

		return new SepaAuditService( $this->appConfig, $context, $this->logger,
			objectService: new DuckObjectServiceAdapter($fake),
		);
	}//end svc()

	/**
	 * Empty mandate id → null (short-circuit, no OR query).
	 *
	 * @return void
	 */
	public function testEmptyMandateIdReturnsNull(): void {
		$this->container->expects(self::never())->method('get');
		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$svc = new SepaAuditService(
			$this->appConfig,
			$this->createMock(AdministrationContextService::class),
			$this->logger,
			objectService: new DuckObjectServiceAdapter(new FakeSepaObjectService([])),
		);

		self::assertNull($svc->buildMandateDossier(''));

	}//end testEmptyMandateIdReturnsNull()

	/**
	 * Missing mandate → null (not-found).
	 *
	 * @return void
	 */
	public function testMissingMandateReturnsNull(): void {
		$svc = $this->svc('', ['SepaMandate' => []]);

		self::assertNull($svc->buildMandateDossier('mandate-1'));

	}//end testMissingMandateReturnsNull()

	/**
	 * Tenant scoping: mandate's administrationId differs from configured admin
	 * → returns null (IDOR-safe, no leak).
	 *
	 * @return void
	 */
	public function testCrossTenantMandateReturnsNull(): void {
		$svc = $this->svc(
			'admin-A',
			[
				'SepaMandate' => [
					['id' => 'mandate-1', 'administrationId' => 'admin-B', 'mandateReference' => 'M1'],
				],
			]
		);

		self::assertNull($svc->buildMandateDossier('mandate-1'));

	}//end testCrossTenantMandateReturnsNull()

	/**
	 * Happy path: ZIP contains the expected file set.
	 *
	 * @return void
	 */
	public function testHappyPathZipContainsCanonicalFileSet(): void {
		$rows = [
			'SepaMandate' => [
				[
					'id' => 'mandate-1',
					'administrationId' => 'admin-A',
					'mandateReference' => 'M-2026-001',
				],
			],
			'DirectDebitCollection' => [
				[
					'id' => 'col-1',
					'mandateId' => 'mandate-1',
					'endToEndId' => 'E2E-1',
					'amount' => '100.00',
					'currency' => 'EUR',
					'sequenceType' => 'RCUR',
					'requestedCollectionDate' => '2026-04-01',
					'status' => 'collected',
					'_pain008Xml' => '<Document/>',
				],
				[
					'id' => 'col-2',
					'mandateId' => 'mandate-1',
					'endToEndId' => 'E2E-2',
					'amount' => '50.00',
					'currency' => 'EUR',
					'sequenceType' => 'OOFF',
					'requestedCollectionDate' => '2026-04-02',
					'status' => 'rejected',
					'pain002ReasonCode' => 'AC04',
				],
			],
			'RTransaction' => [
				['id' => 'r-1', 'collectionId' => 'col-2', 'reasonCode' => 'AC04'],
			],
			'PreNotification' => [
				['id' => 'pn-1', 'collectionId' => 'col-1', 'sentAt' => '2026-03-20'],
			],
		];

		$svc = $this->svc('admin-A', $rows);
		$result = $svc->buildMandateDossier('mandate-1');

		self::assertIsArray($result);
		self::assertSame('sepa-dossier-M-2026-001.zip', $result['filename']);
		self::assertNotSame('', $result['data']);

		// Inspect the ZIP contents.
		$tmp = tempnam(sys_get_temp_dir(), 'sepa-test-');
		file_put_contents($tmp, $result['data']);

		$zip = new ZipArchive();
		self::assertTrue($zip->open($tmp) === true);

		$names = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$names[] = $zip->getNameIndex($i);
		}

		self::assertContains('mandate.json', $names);
		self::assertContains('collections.csv', $names);
		self::assertContains('collections.json', $names);
		self::assertContains('r-transactions.json', $names);
		self::assertContains('pre-notifications.json', $names);
		// Only col-1 has _pain008Xml.
		self::assertContains('pain/E2E-1.pain008.xml', $names);
		// col-2 has no _pain008Xml — no entry.
		self::assertNotContains('pain/E2E-2.pain008.xml', $names);

		$mandate = json_decode($zip->getFromName('mandate.json'), true);
		self::assertSame('M-2026-001', $mandate['mandateReference']);

		$csv = $zip->getFromName('collections.csv');
		self::assertStringContainsString('E2E-1', $csv);
		self::assertStringContainsString('AC04', $csv);
		self::assertStringStartsWith('endToEndId,amount,currency', $csv);

		$zip->close();
		unlink($tmp);

	}//end testHappyPathZipContainsCanonicalFileSet()

	/**
	 * Filename is safely slugged when the mandate reference has odd chars.
	 *
	 * @return void
	 */
	public function testFilenameSlugsUnsafeChars(): void {
		$svc = $this->svc(
			'admin-A',
			[
				'SepaMandate' => [
					['id' => 'm', 'administrationId' => 'admin-A', 'mandateReference' => 'M/2026 #01'],
				],
				'DirectDebitCollection' => [],
			]
		);

		$result = $svc->buildMandateDossier('m');

		self::assertIsArray($result);
		self::assertSame('sepa-dossier-M_2026__01.zip', $result['filename']);

	}//end testFilenameSlugsUnsafeChars()

	/**
	 * A caller with NO memberships gets nothing — the inverse of the old assertion (#518).
	 *
	 * ⚠️ This test replaces `testUnconfiguredAdminAcceptsAnyMandate`, which
	 * asserted that an unset `administration_id` "accepts mandates regardless of
	 * administrationId — single-tenant mode". That was the defect, written down
	 * as a requirement: `administration_id` is instance-wide config with no
	 * relation to the caller, its default is '', and at that value the guard was
	 * skipped entirely — so the audit dossier of ANY mandate on the instance was
	 * exportable by ANY authenticated user. The green test is exactly why nobody
	 * looked. Access is now decided by the caller's memberships.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
	 */
	public function testCallerWithNoMembershipsGetsNothing(): void {
		$svc = $this->svc(
			'',
			[
				'SepaMandate' => [
					['id' => 'mandate-1', 'administrationId' => 'admin-B', 'mandateReference' => 'M1'],
				],
				'DirectDebitCollection' => [],
			]
		);

		self::assertNull($svc->buildMandateDossier('mandate-1'));

	}//end testCallerWithNoMembershipsGetsNothing()

	/**
	 * A mandate carrying NO administrationId is refused, not exported (#518).
	 *
	 * canAccess('') fails closed. Under the old config-based guard an untagged
	 * mandate compared equal to nothing and fell straight through.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
	 */
	public function testUntaggedMandateIsRefused(): void {
		$svc = $this->svc(
			'admin-A',
			[
				'SepaMandate' => [
					['id' => 'mandate-1', 'mandateReference' => 'M1'],
				],
				'DirectDebitCollection' => [],
			]
		);

		self::assertNull($svc->buildMandateDossier('mandate-1'));

	}//end testUntaggedMandateIsRefused()

	/**
	 * CSV cells containing commas / quotes / newlines are quoted+escaped.
	 *
	 * @return void
	 */
	public function testCsvCellsAreEscaped(): void {
		$svc = $this->svc(
			'admin-A',
			[
				'SepaMandate' => [
					['id' => 'm', 'administrationId' => 'admin-A', 'mandateReference' => 'M'],
				],
				'DirectDebitCollection' => [
					[
						'id' => 'col',
						'mandateId' => 'm',
						'endToEndId' => 'E2E,with,comma',
						'status' => 'foo "bar"',
					],
				],
			]
		);

		$result = $svc->buildMandateDossier('m');

		$tmp = tempnam(sys_get_temp_dir(), 'sepa-test-');
		file_put_contents($tmp, $result['data']);
		$zip = new ZipArchive();
		$zip->open($tmp);
		$csv = $zip->getFromName('collections.csv');
		$zip->close();
		unlink($tmp);

		self::assertStringContainsString('"E2E,with,comma"', $csv);
		// Quote becomes double-quote inside a quoted cell.
		self::assertStringContainsString('"foo ""bar"""', $csv);

	}//end testCsvCellsAreEscaped()

}//end class
