<?php

/**
 * Shillinq FX Rate Feed Status
 *
 * Says where the rates on the FX rates page come from (the rates feed, or
 * typed by hand) and the date of the newest rate per currency pair, so the
 * index page can state both in its count line and footer.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Treasury
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/banking-fx-at-booking/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Treasury;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Service\External\TreasuryRate\TreasuryRateAdapterInterface;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The feed state and the newest rate date per currency pair.
 *
 * @spec openspec/changes/banking-fx-at-booking/tasks.md#task-3.1
 */
class FxRateFeedStatus {
	/**
	 * How many FxRate rows one status read looks at.
	 *
	 * @var int
	 */
	private const ROW_LIMIT = 10000;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface       $objectService OpenRegister's object surface.
	 * @param IAppConfig                   $appConfig     App config (register slug).
	 * @param TreasuryRateAdapterInterface $adapter       The bound rates feed adapter.
	 * @param IL10N                        $l10n          Translated status lines.
	 * @param LoggerInterface              $logger        Structured logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly TreasuryRateAdapterInterface $adapter,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The status envelope the FX rates page reads.
	 *
	 * Shape:
	 *  - fromFeed: true when the bound adapter delivers rates, false when they are typed.
	 *  - statusLine: the translated sentence saying which.
	 *  - newest: one entry per currency pair, newest date first, then by pair.
	 *  - newestLine: the translated "newest rate" line, empty when there are no rates.
	 *
	 * @return array{fromFeed:bool,statusLine:string,newest:array<int,array{transactionCurrency:string,baseCurrency:string,newestDate:string}>,newestLine:string}
	 *
	 * @spec openspec/changes/banking-fx-at-booking/tasks.md#task-3.1
	 */
	public function status(): array {
		$fromFeed = $this->isFeedLive();
		$newest   = $this->newestPerPair();

		$statusLine = $this->l10n->t('Rates are entered by hand');
		if ($fromFeed === true) {
			$statusLine = $this->l10n->t('Rates arrive from the feed');
		}

		return [
			'fromFeed' => $fromFeed,
			'statusLine' => $statusLine,
			'newest' => $newest,
			'newestLine' => $this->newestLine(newest: $newest),
		];

	}//end status()

	/**
	 * The newest rate date per currency pair, over the rates the user may read.
	 *
	 * @return array<int,array{transactionCurrency:string,baseCurrency:string,newestDate:string}>
	 *
	 * @spec openspec/changes/banking-fx-at-booking/tasks.md#task-3.1
	 */
	public function newestPerPair(): array {
		try {
			$rows = $this->objectService->setRegister($this->register())->setSchema('FxRate')->findAll(['limit' => self::ROW_LIMIT]);
		} catch (Throwable $e) {
			$this->logger->warning('Shillinq FxRateFeedStatus: FxRate lookup failed', ['exception' => $e->getMessage()]);
			return [];
		}

		$byPair = [];
		foreach ($rows as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			if ($record === null) {
				continue;
			}

			$from = strtoupper(trim((string)($record['transactionCurrency'] ?? '')));
			$to   = strtoupper(trim((string)($record['baseCurrency'] ?? '')));
			$date = substr((string)($record['date'] ?? ''), 0, 10);
			if ($from === '' || $to === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
				continue;
			}

			$key = $from . '/' . $to;
			if (isset($byPair[$key]) === false || $date > $byPair[$key]['newestDate']) {
				$byPair[$key] = ['transactionCurrency' => $from, 'baseCurrency' => $to, 'newestDate' => $date];
			}
		}

		$pairs = array_values($byPair);
		usort(
			$pairs,
			static function (array $left, array $right): int {
				if ($left['newestDate'] !== $right['newestDate']) {
					return strcmp($right['newestDate'], $left['newestDate']);
				}

				return strcmp(
					$left['transactionCurrency'] . $left['baseCurrency'],
					$right['transactionCurrency'] . $right['baseCurrency']
				);
			}
		);

		return $pairs;

	}//end newestPerPair()

	/**
	 * The "newest rate" line: every pair with its newest date.
	 *
	 * @param array<int,array{transactionCurrency:string,baseCurrency:string,newestDate:string}> $newest The pairs.
	 *
	 * @return string Empty when there are no rates.
	 */
	private function newestLine(array $newest): string {
		if ($newest === []) {
			return '';
		}

		$parts = [];
		foreach ($newest as $pair) {
			$parts[] = $this->l10n->t(
				'%1$s to %2$s on %3$s',
				[$pair['transactionCurrency'], $pair['baseCurrency'], $pair['newestDate']]
			);
		}

		return $this->l10n->t('Newest rate: %s', [implode(' · ', $parts)]);

	}//end newestLine()

	/**
	 * Whether the bound adapter delivers rates. An adapter that cannot say is
	 * read as not delivering, so the page never claims a feed it cannot show.
	 *
	 * @return bool
	 */
	private function isFeedLive(): bool {
		try {
			return $this->adapter->isDormant() === false;
		} catch (Throwable $e) {
			$this->logger->warning('Shillinq FxRateFeedStatus: isDormant() threw', ['exception' => $e->getMessage()]);
			return false;
		}

	}//end isFeedLive()

	/**
	 * The register slug.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;

	}//end register()
}//end class
