<?php

/**
 * Unit tests for the fallbacks of RealisedFxSettlementService: the rate taken
 * from the FxRate register when several rows qualify, a rate lookup that
 * fails, the administration's functional currency when it is lower case,
 * blank or unreadable, the invoice identity without an id, and saves that
 * fail without stopping the settlement.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Treasury
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-multi-currency/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Treasury;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\Treasury\RealisedFxSettlementRun;
use OCA\Shillinq\Service\Treasury\RealisedFxSettlementService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use OCP\IUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

// phpcs:disable CustomSniffs.Functions.NamedParameters

#[CoversClass(RealisedFxSettlementService::class)]
#[UsesClass(RealisedFxSettlementRun::class)]
#[UsesClass(ObjectIdentifier::class)]
final class RealisedFxSettlementServiceFallbacksTest extends TestCase {
	/**
	 * What the store saved: schema and object, in order.
	 *
	 * @var array<int,array{schema:string,object:array<string,mixed>}>
	 */
	private array $saved = [];

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->saved  = [];
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * The service over a store reached through the container.
	 *
	 * @param ObjectServiceInterface $store    The store.
	 * @param array<string,string>   $settings App config values by key; others answer their default.
	 *
	 * @return RealisedFxSettlementService
	 */
	private function service(ObjectServiceInterface $store, array $settings = []): RealisedFxSettlementService {
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($settings[$key] ?? $default)
		);

		return new RealisedFxSettlementService($container, $config, $this->logger);

	}//end service()

	/**
	 * A settled USD invoice of administration adm-1.
	 *
	 * @param array<string,mixed> $fields Fields to override or add.
	 *
	 * @return array<string,mixed>
	 */
	private static function invoice(array $fields = []): array {
		return array_merge(
			[
				'id'               => 'arinv-1',
				'invoiceNumber'    => 'AR-1',
				'administrationId' => 'adm-1',
				'currency'         => 'USD',
				'grossAmount'      => 1000.0,
				'invoiceDate'      => '2026-01-10',
				'fxRate'           => 0.90,
			],
			$fields
		);

	}//end invoice()

	/**
	 * The saved objects of a schema, in order.
	 *
	 * @param string $schema The schema.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function savedOf(string $schema): array {
		return array_values(
			array_map(
				static fn (array $entry): array => $entry['object'],
				array_filter($this->saved, static fn (array $entry): bool => $entry['schema'] === $schema)
			)
		);

	}//end savedOf()

	/**
	 * The settlement rate is the FxRate row of the settlement date or the
	 * latest one before it; later, rateless and non-positive rows are ignored
	 * and rendered entities are read like arrays.
	 *
	 * @return void
	 */
	public function testThePaymentRateIsTheLatestRateOnOrBeforeTheSettlement(): void {
		$rate  = static fn (string $date, mixed $value): array => [
			'transactionCurrency' => 'USD',
			'baseCurrency'        => 'EUR',
			'date'                => $date,
			'rate'                => $value,
		];
		$store = new InMemoryObjectServiceStub(
			[
				'Administration' => [['id' => 'adm-1', 'functionalCurrency' => 'EUR']],
				'FxRate'         => [
					$rate('2026-03-01', 0.92),
					$rate('2026-04-01', 0.95),
					$rate('2026-04-20', 0.99),
					$rate('2026-04-10', 0.0),
					$rate('2026-04-12', null),
					$rate('', 0.97),
					['transactionCurrency' => 'GBP', 'baseCurrency' => 'EUR', 'date' => '2026-04-14', 'rate' => 1.2],
				],
			],
			$this->saved,
			findAllRendersEntities: true
		);

		$report = $this->service($store)->postRealisedFxOnSettlement(invoice: self::invoice(), settlementDate: '2026-04-15');

		self::assertTrue($report['posted']);
		self::assertSame(0.95, $report['paymentRate']);
		self::assertSame(0.90, $report['invoiceRate']);
		self::assertSame('gain', $report['direction']);
		self::assertSame(5000, $report['realisedCents']);

	}//end testThePaymentRateIsTheLatestRateOnOrBeforeTheSettlement()

	/**
	 * A rate lookup that throws is logged and leaves the settlement without a
	 * rate, so nothing is posted.
	 *
	 * @return void
	 */
	public function testAFailingRateLookupPostsNothing(): void {
		$store = new class(['Administration' => [['id' => 'adm-1', 'functionalCurrency' => 'EUR']]], $this->saved) extends InMemoryObjectServiceStub {
			/**
			 * The rate list throws; other schemas answer.
			 *
			 * @param array $config        The query.
			 * @param bool  $_rbac         Unused.
			 * @param bool  $_multitenancy Unused.
			 *
			 * @return array
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				if (isset($config['filters']['transactionCurrency']) === true) {
					throw new RuntimeException('rates down');
				}

				return parent::findAll($config, $_rbac, $_multitenancy);

			}//end findAll()
		};
		$this->logger->expects(self::once())->method('warning')->with(
			'RealisedFxSettlementService: FxRate lookup failed',
			['foreignCurrency' => 'USD', 'exception' => 'rates down']
		);

		$report = $this->service($store)->postRealisedFxOnSettlement(invoice: self::invoice(), settlementDate: '2026-04-15');

		self::assertFalse($report['posted']);
		self::assertSame('no-rate', $report['reason']);
		self::assertNull($report['paymentRate']);
		self::assertSame([], $this->saved);

	}//end testAFailingRateLookupPostsNothing()

	/**
	 * Without an invoice date the booked-less invoice has no invoice rate.
	 *
	 * @return void
	 */
	public function testAnInvoiceWithoutDateOrBookedRateHasNoRate(): void {
		$store = new InMemoryObjectServiceStub(['Administration' => [['id' => 'adm-1', 'functionalCurrency' => 'EUR']]], $this->saved);

		$report = $this->service($store)->postRealisedFxOnSettlement(
			invoice: self::invoice(['fxRate' => null, 'invoiceDate' => '']),
			settlementRate: 0.95,
			settlementDate: '2026-04-15'
		);

		self::assertSame('no-rate', $report['reason']);
		self::assertNull($report['invoiceRate']);
		self::assertSame(0.95, $report['paymentRate']);

	}//end testAnInvoiceWithoutDateOrBookedRateHasNoRate()

	/**
	 * A lower-case functional currency is read in upper case, so a USD
	 * invoice of a USD administration has no exposure.
	 *
	 * @return void
	 */
	public function testALowerCaseFunctionalCurrencyIsReadInUpperCase(): void {
		$store = new InMemoryObjectServiceStub(['Administration' => [['id' => 'adm-1', 'functionalCurrency' => 'usd']]], $this->saved);

		$report = $this->service($store)->postRealisedFxOnSettlement(invoice: self::invoice(), settlementRate: 0.95, settlementDate: '2026-04-15');

		self::assertFalse($report['posted']);
		self::assertSame('same-currency', $report['reason']);

	}//end testALowerCaseFunctionalCurrencyIsReadInUpperCase()

	/**
	 * A blank, missing or unreadable administration revalues against EUR; an
	 * unreadable one is logged.
	 *
	 * @return void
	 */
	public function testAnUnknownAdministrationDefaultsToEur(): void {
		$blank = new InMemoryObjectServiceStub(['Administration' => [['id' => 'adm-1', 'functionalCurrency' => '']]], $this->saved);
		$eurInvoice = self::invoice(['currency' => 'EUR']);
		self::assertSame('same-currency', $this->service($blank)->postRealisedFxOnSettlement(invoice: $eurInvoice, settlementRate: 1.0)['reason']);
		$unknown = self::invoice(['currency' => 'EUR', 'administrationId' => 'adm-x']);
		self::assertSame('same-currency', $this->service($blank)->postRealisedFxOnSettlement(invoice: $unknown, settlementRate: 1.0)['reason']);

		$broken = new class([], $this->saved) extends InMemoryObjectServiceStub {
			/**
			 * Selecting the administration schema throws.
			 *
			 * @param string|int $schema The schema.
			 *
			 * @return static
			 */
			public function setSchema(string|int $schema): static {
				if ($schema === 'Administration') {
					throw new RuntimeException('store down');
				}

				return parent::setSchema($schema);

			}//end setSchema()
		};
		$this->logger->expects(self::once())->method('warning')->with(
			'RealisedFxSettlementService: failed to resolve Administration.functionalCurrency — defaulting to EUR',
			['administrationId' => 'adm-1', 'exception' => 'store down']
		);

		self::assertSame('same-currency', $this->service($broken)->postRealisedFxOnSettlement(invoice: $eurInvoice, settlementRate: 1.0)['reason']);

	}//end testAnUnknownAdministrationDefaultsToEur()

	/**
	 * The posting names an invoice without an id by its `@self` id, else its
	 * slug; blank account and register settings keep the documented defaults.
	 *
	 * @return void
	 */
	public function testThePostingIdentifiesTheInvoiceWithoutAnId(): void {
		$store    = new InMemoryObjectServiceStub(['Administration' => [['id' => 'adm-1', 'functionalCurrency' => 'EUR']]], $this->saved);
		$settings = ['fx_realised_gain_account' => '', 'fx_ar_control_account' => '', 'register' => ''];

		$this->service($store, $settings)->postRealisedFxOnSettlement(
			invoice: self::invoice(['id' => null, '@self' => ['id' => 'self-1']]),
			settlementRate: 0.95,
			settlementDate: '2026-04-15'
		);
		$this->service($store, $settings)->postRealisedFxOnSettlement(
			invoice: self::invoice(['id' => null, 'slug' => 'ar-slug']),
			settlementRate: 0.95,
			settlementDate: '2026-04-15'
		);

		$postings = $this->savedOf('RealisedFxPosting');
		self::assertSame(['self-1', 'ar-slug'], array_column($postings, 'invoiceId'));
		self::assertSame(['8022', '8022'], array_column($postings, 'gainLossGLAccount'));
		self::assertSame(['1130', '1130'], array_column($postings, 'arControlGLAccount'));
		self::assertCount(2, $this->savedOf('GLTransaction'));

	}//end testThePostingIdentifiesTheInvoiceWithoutAnId()

	/**
	 * Saves that throw are logged per schema and the settlement still reports
	 * as posted.
	 *
	 * @return void
	 */
	public function testFailingSavesAreLoggedAndTheSettlementContinues(): void {
		$store = new class(['Administration' => [['id' => 'adm-1', 'functionalCurrency' => 'EUR']]], $this->saved) extends InMemoryObjectServiceStub {
			/**
			 * Every save throws.
			 *
			 * @param array           $object        The object.
			 * @param array|null      $extend        Unused.
			 * @param string|int|null $register      Unused.
			 * @param string|int|null $schema        Unused.
			 * @param string|null     $uuid          Unused.
			 * @param bool            $_rbac         Unused.
			 * @param bool            $_multitenancy Unused.
			 * @param bool            $silent        Unused.
			 * @param bool            $_validation   Unused.
			 * @param array|null      $uploadedFiles Unused.
			 * @param IUser|null      $currentUser   Unused.
			 * @param bool            $failIfExists  Unused.
			 *
			 * @return ObjectEntityInterface
			 */
			public function saveObject(
				array $object,
				ObjectSaveOptions $options = new ObjectSaveOptions(),
				bool $_rbac = true,
				bool $_multitenancy = true
			): ObjectEntityInterface {
				$extend = $options->extend ?? [];
				$register = $options->target->register;
				$schema = $options->target->schema;
				$uuid = $options->uuid;
				$silent = $options->policy->silent ?? false;
				$_validation = $options->policy->validation ?? true;
				$uploadedFiles = $options->uploadedFiles;
				$currentUser = $options->actor->currentUser;
				$failIfExists = $options->policy->failIfExists ?? false;
				throw new RuntimeException('disk full');

			}//end saveObject()
		};
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message, array $context) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);

		$report = $this->service($store)->postRealisedFxOnSettlement(invoice: self::invoice(), settlementRate: 0.85, settlementDate: '2026-04-15');

		self::assertTrue($report['posted']);
		self::assertSame('loss', $report['direction']);
		self::assertSame(-5000, $report['realisedCents']);
		self::assertSame(
			[
				['RealisedFxSettlementService: realised-FX GLTransaction save failed (continuing)', ['exception' => 'disk full']],
				['RealisedFxSettlementService: RealisedFxPosting save failed (continuing)', ['exception' => 'disk full']],
			],
			$warnings
		);

	}//end testFailingSavesAreLoggedAndTheSettlementContinues()
}//end class
