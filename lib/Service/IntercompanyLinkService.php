<?php

/**
 * Intercompany Link Service
 *
 * Change revive-gl-tax-capabilities (shillinq#418 / #446): the missing
 * intercompany mirroring + reconciliation trigger.
 * {@see \OCA\Shillinq\Service\IntercompanyJournalService} implements the
 * whole REQ-MA-004 kernel — `buildMirror()`, `reconcileVariance()`,
 * `isBalanced()`, `isTransitionAllowed()`, `statusAfterEdit()` — and passes
 * its unit tests, but **not one of those methods had a caller**: its own
 * docblock says "the controller layer persists the records", and no such
 * controller was ever written. An intercompany pair could therefore reach
 * `gekoppeld` / `bevestigd_beide` / `eliminatie_geboekt` without the mirror
 * ever being created and without the afwijking (variance) between the two
 * sides ever being computed.
 *
 * This service is the storage-aware half the kernel expected:
 *
 *   - On `link`, it resolves the counter-side `IntercompanyJournalEntry`
 *     (same `intercompanyNumber`, source/destination administrations
 *     swapped). When each administration booked its own side independently
 *     the counter-side already exists; when only one side was drafted, the
 *     mirror is created from `buildMirror()`.
 *   - It then computes `reconcileVariance()` between the two booked amounts
 *     and persists `varianceAmount` on BOTH sides, so a non-zero afwijking
 *     is visible to the operator and blocks the elimination
 *     ({@see \OCA\Shillinq\Guard\IntercompanyEliminationGuard}).
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
 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Creates the mirrored intercompany side and reconciles the pair (REQ-GLTAX-002).
 *
 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
 */
class IntercompanyLinkService {

	/**
	 * IntercompanyJournalEntry schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_ENTRY = 'IntercompanyJournalEntry';

	/**
	 * The lifecycle state a linked pair sits in.
	 *
	 * @var string
	 */
	private const STATE_LINKED = 'gekoppeld';

	/**
	 * Construct the service.
	 *
	 * @param IAppConfig $appConfig App config (register slug).
	 * @param IntercompanyJournalService $journalService The pure-logic REQ-MA-004 kernel.
	 * @param LoggerInterface $logger Logger (no sensitive payloads).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IntercompanyJournalService $journalService,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {

	}//end __construct()

	/**
	 * Mirror + reconcile an intercompany pair on `link` (REQ-GLTAX-002).
	 *
	 * Wired by {@see \OCA\Shillinq\Listener\IntercompanyLinkListener} on the
	 * `IntercompanyJournalEntry` `concept -> gekoppeld` transition.
	 *
	 * @param array<string,mixed> $entry The source-side IntercompanyJournalEntry that was just linked.
	 *
	 * @return array{linked:bool, mirrorCreated:bool, varianceAmount:float, balanced:bool, message:string}
	 *
	 * @throws \RuntimeException When the entry carries no intercompanyNumber.
	 *
	 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
	 */
	public function linkAndReconcile(array $entry): array {
		$intercompanyNumber = trim((string)($entry['intercompanyNumber'] ?? ''));
		if ($intercompanyNumber === '') {
			throw new RuntimeException('IntercompanyJournalEntry requires an intercompanyNumber to be linked');
		}

		$counter = $this->findCounterSide(entry: $entry);
		$mirrorCreated = false;

		if ($counter === null) {
			$mirror = $this->journalService->buildMirror(source: $entry);
			$mirror['intercompanyNumber'] = $intercompanyNumber;
			$mirror['description'] = (string)($entry['description'] ?? '');
			$counter = $this->saveEntry(data: $mirror);
			$mirrorCreated = true;
		}

		$sourceAmount = ($entry['amount'] ?? 0);
		$destinationAmount = ($counter['amount'] ?? 0);
		$variance = $this->journalService->reconcileVariance(
			sourceAmount: $sourceAmount,
			destinationAmount: $destinationAmount
		);
		$balanced = $this->journalService->isBalanced(
			sourceAmount: $sourceAmount,
			destinationAmount: $destinationAmount
		);

		$this->persistVariance(entry: $entry, variance: $variance);
		$this->persistVariance(entry: $counter, variance: $variance);

		if ($balanced === false) {
			$this->logger->warning(
				'IntercompanyLinkService: intercompany pair does not reconcile',
				[
					'intercompanyNumber' => $intercompanyNumber,
					'varianceAmount' => $variance,
				]
			);
		}

		return [
			'linked' => true,
			'mirrorCreated' => $mirrorCreated,
			'varianceAmount' => $variance,
			'balanced' => $balanced,
			'message' => 'Intercompany pair linked and reconciled',
		];

	}//end linkAndReconcile()

	/**
	 * Resolve the counter-side entry of an intercompany pair.
	 *
	 * The counter-side carries the same `intercompanyNumber` with the source
	 * and destination administrations swapped. Also used by
	 * {@see \OCA\Shillinq\Guard\IntercompanyEliminationGuard}, which must
	 * fail closed when this returns null.
	 *
	 * @param array<string,mixed> $entry Either side of the pair.
	 *
	 * @return array<string,mixed>|null The counter-side entry, or null when absent.
	 *
	 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
	 */
	public function findCounterSide(array $entry): ?array {
		$intercompanyNumber = trim((string)($entry['intercompanyNumber'] ?? ''));
		if ($intercompanyNumber === '') {
			return null;
		}

		$sourceId = trim((string)($entry['sourceAdministrationId'] ?? ''));
		$destinationId = trim((string)($entry['destinationAdministrationId'] ?? ''));
		$selfId = $this->entryId(entry: $entry);

		$rows = $this->findAll(
			schema: self::SCHEMA_ENTRY,
			filters: ['intercompanyNumber' => $intercompanyNumber]
		);

		foreach ($rows as $row) {
			if ($this->entryId(entry: $row) === $selfId && $selfId !== '') {
				continue;
			}

			$rowSource = trim((string)($row['sourceAdministrationId'] ?? ''));
			$rowDestination = trim((string)($row['destinationAdministrationId'] ?? ''));
			if ($rowSource === $destinationId && $rowDestination === $sourceId) {
				return $row;
			}
		}

		return null;
	}//end findCounterSide()

	/**
	 * Persist the computed variance on one side of the pair.
	 *
	 * @param array<string,mixed> $entry The entry to update.
	 * @param float $variance The reconciliation variance (afwijking_bedrag).
	 *
	 * @return void
	 */
	private function persistVariance(array $entry, float $variance): void {
		$entryId = $this->entryId(entry: $entry);
		if ($entryId === '') {
			return;
		}

		$data = $entry;
		$data['id'] = $entryId;
		$data['varianceAmount'] = $variance;
		if (trim((string)($data['status'] ?? '')) === '') {
			$data['status'] = self::STATE_LINKED;
		}

		unset($data['@self']);

		$this->saveEntry(data: $data);

	}//end persistVariance()

	/**
	 * Resolve an entry's OpenRegister id.
	 *
	 * @param array<string,mixed> $entry The entry.
	 *
	 * @return string The id, '' when the record is not persisted.
	 */
	private function entryId(array $entry): string {
		$id = trim((string)($entry['id'] ?? ''));
		if ($id !== '') {
			return $id;
		}

		$self = ($entry['@self'] ?? []);
		if (is_array($self) === true) {
			return trim((string)($self['id'] ?? ''));
		}

		return '';
	}//end entryId()

	/**
	 * Persist an IntercompanyJournalEntry through the real ObjectService API.
	 *
	 * @param array<string,mixed> $data Record body.
	 *
	 * @return array<string,mixed> The saved record.
	 *
	 * @throws \RuntimeException When the row type is unsupported.
	 */
	private function saveEntry(array $data): array {
		$saved = $this->objectService
			->setRegister($this->register())
			->setSchema(self::SCHEMA_ENTRY)
			->saveObject($data);

		// ADR-084: saveObject() is declared `: ObjectEntityInterface`, which
		// extends JsonSerializable and declares `getObject(): array` — so the
		// is_object()/method_exists() guards that used to wrap these two calls
		// could never be false, and the trailing throw was unreachable.
		// jsonSerialize() still returns mixed, so that check stays.
		$out = $saved->jsonSerialize();
		if (is_array($out) === true) {
			return $out;
		}

		return $saved->getObject();
	}//end saveEntry()

	/**
	 * Find all records via the real ObjectService API (findAll).
	 *
	 * @param string $schema Schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findAll(string $schema, array $filters): array {
		try {
			$rows = $this->objectService
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['filters' => $filters]);
		} catch (\Throwable $e) {
			$this->logger->error(
				'IntercompanyLinkService: failed to query OpenRegister',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$result = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$result[] = $row;
			}
		}

		return $result;
	}//end findAll()

	/**
	 * Resolve the OR register slug from app config.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
