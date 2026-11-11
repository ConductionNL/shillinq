<?php

/**
 * Disposal journal run
 *
 * The figures of one fixed-asset disposal journal: the asset and disposal, the
 * accounts posted to, the authoritative book value, and the integer-cent
 * amounts of the gross value, accumulated depreciation, proceeds and the gain
 * or loss they leave.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * The amounts and accounts of one fixed-asset disposal.
 *
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 */
final class DisposalJournalRun {
	/**
	 * The gain, loss and clearing accounts.
	 *
	 * @var array<string,mixed>
	 */
	public array $accounts = [];

	/**
	 * The disposal date (Y-m-d).
	 *
	 * @var string
	 */
	public string $disposalDate = '';

	/**
	 * The disposal accounting treatment.
	 *
	 * @var string
	 */
	public string $treatment = '';

	/**
	 * The net book value at the disposal date.
	 *
	 * @var float
	 */
	public float $bookValue = 0.0;

	/**
	 * The gross asset value in cents.
	 *
	 * @var int
	 */
	public int $costCents = 0;

	/**
	 * The disposal proceeds in cents.
	 *
	 * @var int
	 */
	public int $proceedsCents = 0;

	/**
	 * The accumulated depreciation in cents.
	 *
	 * @var int
	 */
	public int $accumDepCents = 0;

	/**
	 * The book gain in cents.
	 *
	 * @var int
	 */
	public int $gainCents = 0;

	/**
	 * The book loss in cents.
	 *
	 * @var int
	 */
	public int $lossCents = 0;

	/**
	 * Start a disposal journal.
	 *
	 * @param array<string,mixed> $asset    The FixedAsset.
	 * @param array<string,mixed> $disposal The disposal payload.
	 */
	public function __construct(
		public readonly array $asset,
		public readonly array $disposal,
	) {
	}//end __construct()
}//end class
