<?php

/**
 * Audit sample drawer
 *
 * Draws a reproducible random sample of posted ledger lines for an audit
 * protocol and stores it as an AuditSample: the population, the method, the
 * size, the seed and the selected line ids. The population is put in a
 * canonical order (sorted ids) before the seeded draw, so the same seed over
 * the same population returns the same lines whatever order they arrive in.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\PublicSector
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-bado-controleprotocol/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\PublicSector;

use DateTimeInterface;
use DomainException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserSession;

/**
 * Draws and stores a seeded audit sample (REQ-011).
 *
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-bado-controleprotocol/spec.md
 */
class AuditSampleDrawer {

	/**
	 * Constructor.
	 *
	 * @param PublicSectorRecords $records     Reads the protocol, stores the sample.
	 * @param ITimeFactory        $time        The clock for extractedAt.
	 * @param IUserSession        $userSession The auditor drawing the sample.
	 */
	public function __construct(
		private readonly PublicSectorRecords $records,
		private readonly ITimeFactory $time,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Draw a sample of the given size from the population and store it.
	 *
	 * @param string             $protocolId  The Controleprotocol the sample belongs to.
	 * @param array<int,mixed>   $population  Posted line ids, or line records with an `id`.
	 * @param int                $size        How many lines to select.
	 * @param int|null           $seed        The seed; a new one is chosen and stored when null.
	 * @param string             $description What the population is, for the auditor's file.
	 *
	 * @return array{id:string,sampledLineIds:array<int,string>,reproducibleSeed:string}
	 *
	 * @throws DomainException When the protocol does not exist, or the size is not between 1 and the population size.
	 *
	 * @spec openspec/changes/public-sector-council-and-auditor-statements/tasks.md#task-2.1
	 */
	public function draw(string $protocolId, array $population, int $size, ?int $seed = null, string $description = ''): array {
		if ($this->records->find(schema: 'Controleprotocol', id: $protocolId) === null) {
			throw new DomainException(sprintf('Audit protocol %s does not exist.', $protocolId));
		}

		$ids = self::lineIds(population: $population);
		if ($size < 1 || $size > count($ids)) {
			throw new DomainException(sprintf('A sample must hold between 1 and %d lines; %d were asked.', count($ids), $size));
		}

		if ($seed === null) {
			$seed = random_int(1, 2147483647);
		}

		$selected = self::select(ids: $ids, size: $size, seed: $seed);
		if ($description === '') {
			$description = sprintf('%d posted lines', count($ids));
		}

		$sampleId = $this->records->create(
			schema: 'AuditSample',
			object: [
				'protocol' => $protocolId,
				'population' => $description,
				'selectionMethod' => 'random',
				'sampleSize' => $size,
				'reproducibleSeed' => (string)$seed,
				'sampledLineIds' => $selected,
				'extractedAt' => $this->time->getDateTime()->format(DateTimeInterface::ATOM),
				'extractedBy' => ($this->userSession->getUser()?->getUID() ?? 'system'),
			]
		);

		return ['id' => $sampleId, 'sampledLineIds' => $selected, 'reproducibleSeed' => (string)$seed];

	}//end draw()

	/**
	 * Select `size` ids by a seeded partial Fisher-Yates shuffle over the sorted ids.
	 *
	 * The generator is reseeded randomly afterwards so the fixed seed does not
	 * leak into later random numbers of the same request.
	 *
	 * @param array<int,string> $ids  The distinct line ids, sorted.
	 * @param int               $size How many to select.
	 * @param int               $seed The seed.
	 *
	 * @return array<int,string> The selected ids, in draw order.
	 */
	private static function select(array $ids, int $size, int $seed): array {
		mt_srand($seed, MT_RAND_MT19937);
		$last = (count($ids) - 1);
		for ($i = 0; $i < $size; $i++) {
			$j = mt_rand($i, $last);
			[$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
		}

		mt_srand();

		return array_slice($ids, 0, $size);

	}//end select()

	/**
	 * The distinct, non-empty line ids of the population, sorted.
	 *
	 * @param array<int,mixed> $population Line ids or line records.
	 *
	 * @return array<int,string>
	 */
	private static function lineIds(array $population): array {
		$ids = [];
		foreach ($population as $line) {
			$id = $line;
			if (is_array($line) === true) {
				$id = ($line['id'] ?? '');
			}

			if (is_scalar($id) === true && (string)$id !== '') {
				$ids[(string)$id] = true;
			}
		}

		$ids = array_map('strval', array_keys($ids));
		sort($ids, SORT_STRING);
		return $ids;

	}//end lineIds()
}//end class
