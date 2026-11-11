<?php

/**
 * GR/IR fan-out tally
 *
 * The running count of one receipt fan-out in GRIRClearingService: how many
 * lines were posted, how many were skipped, and the result of every line that
 * was attempted. Never leaves the service.
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
 * @spec openspec/specs/grir-accrual-wiring/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Counts the posted and skipped lines of a fan-out.
 *
 * @spec openspec/specs/grir-accrual-wiring/spec.md
 */
final class GRIRFanOutTally {
	/**
	 * Lines posted.
	 *
	 * @var int
	 */
	private int $posted = 0;

	/**
	 * Lines skipped: nothing accepted, or attempted without a posting.
	 *
	 * @var int
	 */
	private int $skipped = 0;

	/**
	 * The result of every attempted line.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $results = [];

	/**
	 * Count one line: null when nothing was accepted, else its posting result.
	 *
	 * @param array<string,mixed>|null $result The posting result, or null.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/grir-accrual-wiring/spec.md
	 */
	public function count(?array $result): void {
		if ($result === null) {
			$this->skipped++;
			return;
		}

		$this->results[] = $result;
		if (($result['posted'] ?? false) === true) {
			$this->posted++;
			return;
		}

		$this->skipped++;
	}//end count()

	/**
	 * The fan-out result envelope.
	 *
	 * @return array{posted:int,skipped:int,results:array<int,array<string,mixed>>}
	 *
	 * @spec openspec/specs/grir-accrual-wiring/spec.md
	 */
	public function envelope(): array {
		return [
			'posted' => $this->posted,
			'skipped' => $this->skipped,
			'results' => $this->results,
		];
	}//end envelope()
}//end class
