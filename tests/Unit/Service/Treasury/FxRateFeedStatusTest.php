<?php

/**
 * Unit tests for FxRateFeedStatus: the FX rates page's feed line and the
 * newest rate date per currency pair.
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
 * @spec openspec/changes/banking-fx-at-booking/specs/bookkeeping-multi-currency/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service\Treasury;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\External\TreasuryRate\TreasuryRateAdapterInterface;
use OCA\Shillinq\Service\Treasury\FxRateFeedStatus;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The feed status over an in-memory register.
 */
#[CoversClass(FxRateFeedStatus::class)]
#[UsesClass(ObjectIdentifier::class)]
final class FxRateFeedStatusTest extends TestCase {
	/**
	 * The design's seed rates plus an older USD rate and an unusable row.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private const SEED_RATES = [
		['id' => 'fx-usd-old', 'transactionCurrency' => 'USD', 'baseCurrency' => 'EUR', 'date' => '2026-09-21', 'source' => 'ecb', 'rate' => 0.9100],
		[
			'id' => 'fx-usd', 'transactionCurrency' => 'USD', 'baseCurrency' => 'EUR',
			'date' => '2026-09-25T00:00:00+00:00', 'source' => 'manual', 'rate' => 0.9150, 'manualOverrideReason' => 'ECB-koers overgenomen',
		],
		['id' => 'fx-gbp', 'transactionCurrency' => 'gbp', 'baseCurrency' => 'EUR', 'date' => '2026-09-18', 'source' => 'ecb', 'rate' => 1.1830],
		['id' => 'fx-bad', 'transactionCurrency' => 'CHF', 'baseCurrency' => 'EUR', 'date' => 'soon', 'source' => 'manual', 'rate' => 0.95],
	];

	/**
	 * The logger the last built service writes to.
	 *
	 * @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * A feed status over the given objects and adapter answer.
	 *
	 * @param ObjectServiceInterface $objects The object surface.
	 * @param bool|null              $dormant The adapter's answer; null makes it throw.
	 *
	 * @return FxRateFeedStatus
	 */
	private function feedStatus(ObjectServiceInterface $objects, bool|null $dormant): FxRateFeedStatus {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);

		$adapter = $this->createMock(TreasuryRateAdapterInterface::class);
		if ($dormant === null) {
			$adapter->method('isDormant')->willThrowException(new RuntimeException('adapter down'));
		} else {
			$adapter->method('isDormant')->willReturn($dormant);
		}

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => '[' . vsprintf($text, $parameters) . ']'
		);

		$this->logger = $this->createMock(LoggerInterface::class);

		return new FxRateFeedStatus($objects, $appConfig, $adapter, $l10n, $this->logger);

	}//end feedStatus()

	/**
	 * With the feed dormant the page says rates are typed, and lists the
	 * newest date per pair, newest first (REQ-BFX-004 scenario).
	 *
	 * @return void
	 */
	public function testADormantFeedSaysRatesAreTypedAndListsTheNewestPerPair(): void {
		$status = $this->feedStatus(new InMemoryObjectServiceStub(['FxRate' => self::SEED_RATES]), true)->status();

		$this->assertSame(
			[
				'fromFeed' => false,
				'statusLine' => '[Rates are entered by hand]',
				'newest' => [
					['transactionCurrency' => 'USD', 'baseCurrency' => 'EUR', 'newestDate' => '2026-09-25'],
					['transactionCurrency' => 'GBP', 'baseCurrency' => 'EUR', 'newestDate' => '2026-09-18'],
				],
				'newestLine' => '[Newest rate: [USD to EUR on 2026-09-25] · [GBP to EUR on 2026-09-18]]',
			],
			$status
		);

	}//end testADormantFeedSaysRatesAreTypedAndListsTheNewestPerPair()

	/**
	 * A live adapter says rates arrive from the feed.
	 *
	 * @return void
	 */
	public function testALiveFeedSaysRatesArriveFromTheFeed(): void {
		$status = $this->feedStatus(new InMemoryObjectServiceStub(['FxRate' => self::SEED_RATES]), false)->status();

		$this->assertTrue($status['fromFeed']);
		$this->assertSame('[Rates arrive from the feed]', $status['statusLine']);

	}//end testALiveFeedSaysRatesArriveFromTheFeed()

	/**
	 * An adapter that throws is read as not delivering, and the failure is logged.
	 *
	 * @return void
	 */
	public function testAnAdapterThatThrowsIsReadAsTyped(): void {
		$service = $this->feedStatus(new InMemoryObjectServiceStub(['FxRate' => []]), null);
		$this->logger->expects($this->once())->method('warning')->with(
			'Shillinq FxRateFeedStatus: isDormant() threw',
			['exception' => 'adapter down']
		);

		$status = $service->status();

		$this->assertFalse($status['fromFeed']);
		$this->assertSame('[Rates are entered by hand]', $status['statusLine']);

	}//end testAnAdapterThatThrowsIsReadAsTyped()

	/**
	 * No rates: no pairs and an empty newest line, so the footer stays blank.
	 *
	 * @return void
	 */
	public function testNoRatesGiveAnEmptyNewestLine(): void {
		$status = $this->feedStatus(new InMemoryObjectServiceStub(['FxRate' => []]), true)->status();

		$this->assertSame([], $status['newest']);
		$this->assertSame('', $status['newestLine']);

	}//end testNoRatesGiveAnEmptyNewestLine()

	/**
	 * Pairs with the same newest date sort by pair, and the read asks the
	 * configured register's FxRate schema with the caller's access.
	 *
	 * @return void
	 */
	public function testSameDatePairsSortByPairAndTheReadUsesTheFxRateSchema(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->once())->method('setRegister')->with('shillinq')->willReturnSelf();
		$objects->expects($this->once())->method('setSchema')->with('FxRate')->willReturnSelf();
		$objects->expects($this->once())->method('findAll')->with(['limit' => 10000])->willReturn(
			[
				['transactionCurrency' => 'USD', 'baseCurrency' => 'EUR', 'date' => '2026-09-25'],
				['transactionCurrency' => 'CHF', 'baseCurrency' => 'EUR', 'date' => '2026-09-25'],
				['transactionCurrency' => '', 'baseCurrency' => 'EUR', 'date' => '2026-09-26'],
				'not a record',
			]
		);

		$this->assertSame(
			[
				['transactionCurrency' => 'CHF', 'baseCurrency' => 'EUR', 'newestDate' => '2026-09-25'],
				['transactionCurrency' => 'USD', 'baseCurrency' => 'EUR', 'newestDate' => '2026-09-25'],
			],
			$this->feedStatus($objects, true)->newestPerPair()
		);

	}//end testSameDatePairsSortByPairAndTheReadUsesTheFxRateSchema()

	/**
	 * A failing read lists no pairs and logs the failure instead of breaking the page.
	 *
	 * @return void
	 */
	public function testAFailingReadListsNoPairs(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('findAll')->willThrowException(new RuntimeException('register down'));

		$service = $this->feedStatus($objects, true);
		$this->logger->expects($this->once())->method('warning')->with(
			'Shillinq FxRateFeedStatus: FxRate lookup failed',
			['exception' => 'register down']
		);

		$this->assertSame([], $service->newestPerPair());

	}//end testAFailingReadListsNoPairs()
}//end class
