<?php

/**
 * Shillinq StampJournalTypeOnGlTransactions Repair Step
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Idempotent repair step that stamps the new `journalType` discriminator
 * (and the folded closing/intercompany field groups) onto existing
 * `GLTransaction` records, completing the GLTransaction-family
 * abstraction merge.
 *
 * The former dedicated `ClosingEntry` and `IntercompanyTransaction`
 * schemas are folded into `GLTransaction` via the
 * `abstract-gltransaction-types` register fragment. This repair step
 * reconciles the legacy data so each GLTransaction carries the correct
 * `journalType`:
 *
 *   1. Every `ClosingEntry` carrying a `glTransactionId` resolves the
 *      target GLTransaction and stamps `journalType='closing'` plus the
 *      folded `closingEntryType` (from `entryType`), `fiscalYearId`, and
 *      `approvalStatus`.
 *   2. Every `IntercompanyTransaction` carrying a `sourceJournalEntryId`
 *      resolves the target GLTransaction and stamps
 *      `journalType='intercompany'` plus the folded
 *      `sourceAdministrationId`, `counterpartyEntityId`,
 *      `detectionMethod`, `detectionConfidence`, `isMatched`, and
 *      `matchId`.
 *   3. Any GLTransaction not linked from either source is left
 *      untouched (it keeps the schema default `journalType='manual'`).
 *
 * The source `ClosingEntry` / `IntercompanyTransaction` records are
 * NEVER deleted — this step only enriches the GLTransaction side.
 *
 * Idempotent — a GLTransaction already carrying the target
 * `journalType` is skipped, so re-runs of `occ maintenance:repair` (or a
 * fresh `occ app:enable shillinq`) never re-stamp or duplicate state.
 *
 * @category Repair
 * @package  OCA\Shillinq\Repair
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\Shillinq\Kernel\Contracts\RegisterLocationInterface;
use OCA\Shillinq\Kernel\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Repair step that stamps `journalType` (and the folded closing /
 * intercompany field groups) onto existing GLTransaction records from
 * their legacy ClosingEntry / IntercompanyTransaction sources.
 */
class StampJournalTypeOnGlTransactions implements IRepairStep {
	use ReadsSourceRowsInBatches;
	use RunsUnderSystemIdentity;

	/**
	 * ClosingEntry fields folded onto the GLTransaction: source name to GLTransaction name.
	 *
	 * @var array<string,string>
	 */
	private const CLOSING_FIELDS = ['entryType' => 'closingEntryType', 'fiscalYearId' => 'fiscalYearId', 'approvalStatus' => 'approvalStatus'];

	/**
	 * IntercompanyTransaction detection fields folded onto the GLTransaction under the same name.
	 *
	 * @var array<string,string>
	 */
	private const INTERCOMPANY_FIELDS = [
		'sourceAdministrationId' => 'sourceAdministrationId',
		'counterpartyEntityId' => 'counterpartyEntityId',
		'detectionMethod' => 'detectionMethod',
		'detectionConfidence' => 'detectionConfidence',
	];

	/**
	 * Constructor.
	 *
	 * @param RegisterLocationInterface $registerLocation Where the register lives (slug + OpenRegister availability).
	 * @param LoggerInterface $logger The logger interface.
	 * @param ContainerInterface $container The DI container (lazy OR ObjectService resolution).
	 */
	public function __construct(
		private RegisterLocationInterface $registerLocation,
		private LoggerInterface $logger,
		private ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The repair-step display name.
	 *
	 * @return string The display name.
	 */
	public function getName(): string {
		return 'Shillinq: stamp journalType (closing/intercompany) onto GLTransaction records';
	}//end getName()

	/**
	 * Run the stamp. Idempotent — a GLTransaction already carrying the
	 * target journalType is skipped.
	 *
	 * @param IOutput $output The repair-step output (progress + warnings).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-administration/spec.md
	 */
	public function run(IOutput $output): void {
		// Under a system identity: an upgrade has no session, and OpenRegister
		// refuses every write for 'Anonymous'. Without it this stamp updates
		// nothing and says so only in a warning, which does not fail an upgrade.
		$this->withSystemIdentity(
			objectService: $this->resolveObjectServiceForIdentity(),
			work: function () use ($output): void {
				$this->runInner(output: $output);
			}
		);
	}//end run()

	/**
	 * OpenRegister's ObjectService, or null when it cannot be resolved.
	 *
	 * Null is not fatal: {@see withSystemIdentity()} then runs the work anyway,
	 * exactly as it ran before this wrapper existed.
	 *
	 * @return object|null The service.
	 *
	 * @spec openspec/specs/app-administration/spec.md
	 */
	private function resolveObjectServiceForIdentity(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			return null;
		}
	}//end resolveObjectServiceForIdentity()

	/**
	 * The stamp itself.
	 *
	 * @param IOutput $output The repair-step output (progress + warnings).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-administration/spec.md
	 */
	private function runInner(IOutput $output): void {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$registerSlug = $this->registerLocation->getRegisterSlug();

			$closingStamped = $this->stampClosing(
				objectService: $objectService,
				registerSlug: $registerSlug,
				output: $output
			);
			$intercompanyStamped = $this->stampIntercompany(
				objectService: $objectService,
				registerSlug: $registerSlug,
				output: $output
			);

			$output->info(
				'Shillinq: journalType stamp complete — '
				. $closingStamped . ' closing, '
				. $intercompanyStamped . ' intercompany GLTransaction(s) stamped.'
			);
		} catch (\Throwable $e) {
			// Stamp is best-effort: failing it must NOT block the app
			// upgrade. Log + warn so an operator can re-run.
			$output->warning('Shillinq: journalType stamp failed: ' . $e->getMessage());
			$this->logger->warning(
				'Shillinq: journalType stamp failed',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end runInner()

	/**
	 * Stamp journalType='closing' (+ folded closing fields) onto every
	 * GLTransaction linked from a ClosingEntry via `glTransactionId`.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug The register slug.
	 * @param IOutput $output The repair-step output.
	 *
	 * @return int The number of GLTransaction records stamped.
	 */
	private function stampClosing(
		object $objectService,
		string $registerSlug,
		IOutput $output,
	): int {
		$closingEntries = $this->readAllRows(objectService: $objectService, registerSlug: $registerSlug, schema: 'ClosingEntry');

		if ($closingEntries === []) {
			return 0;
		}

		$stamped = 0;
		foreach ($closingEntries as $entry) {
			try {
				if ($this->stampClosingRow(objectService: $objectService, registerSlug: $registerSlug, entry: $entry) === true) {
					$stamped++;
				}
			} catch (\Throwable $e) {
				$output->warning('Shillinq: failed to stamp closing GLTransaction: ' . $e->getMessage());
			}
		}

		return $stamped;
	}//end stampClosing()

	/**
	 * Stamp journalType='closing' (+ folded closing fields) onto the GLTransaction one ClosingEntry links.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug  The register slug.
	 * @param mixed  $entry         One ClosingEntry row.
	 *
	 * @return bool Whether the GLTransaction was stamped.
	 */
	private function stampClosingRow(object $objectService, string $registerSlug, mixed $entry): bool {
		$arr         = $this->rowPayload(row: $entry);
		$id          = (string)($arr['glTransactionId'] ?? '');
		$transaction = $this->unstampedTransaction(objectService: $objectService, registerSlug: $registerSlug, id: $id, journalType: 'closing');
		if ($transaction === null) {
			return false;
		}

		$transaction['journalType'] = 'closing';
		$transaction = self::copyNonEmpty(transaction: $transaction, row: $arr, fields: self::CLOSING_FIELDS);

		$this->saveTransaction(objectService: $objectService, registerSlug: $registerSlug, transaction: $transaction);
		return true;
	}//end stampClosingRow()

	/**
	 * Stamp journalType='intercompany' (+ folded intercompany fields)
	 * onto every GLTransaction linked from an IntercompanyTransaction via
	 * `sourceJournalEntryId`.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug The register slug.
	 * @param IOutput $output The repair-step output.
	 *
	 * @return int The number of GLTransaction records stamped.
	 */
	private function stampIntercompany(
		object $objectService,
		string $registerSlug,
		IOutput $output,
	): int {
		$intercompany = $this->readAllRows(objectService: $objectService, registerSlug: $registerSlug, schema: 'IntercompanyTransaction');

		if ($intercompany === []) {
			return 0;
		}

		$stamped = 0;
		foreach ($intercompany as $ic) {
			try {
				if ($this->stampIntercompanyRow(objectService: $objectService, registerSlug: $registerSlug, row: $ic) === true) {
					$stamped++;
				}
			} catch (\Throwable $e) {
				$output->warning('Shillinq: failed to stamp intercompany GLTransaction: ' . $e->getMessage());
			}
		}

		return $stamped;
	}//end stampIntercompany()

	/**
	 * Stamp journalType='intercompany' (+ folded intercompany fields) onto the GLTransaction one
	 * IntercompanyTransaction links via `sourceJournalEntryId`.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug  The register slug.
	 * @param mixed  $row           One IntercompanyTransaction row.
	 *
	 * @return bool Whether the GLTransaction was stamped.
	 */
	private function stampIntercompanyRow(object $objectService, string $registerSlug, mixed $row): bool {
		$arr         = $this->rowPayload(row: $row);
		$id          = (string)($arr['sourceJournalEntryId'] ?? '');
		$transaction = $this->unstampedTransaction(objectService: $objectService, registerSlug: $registerSlug, id: $id, journalType: 'intercompany');
		if ($transaction === null) {
			return false;
		}

		$transaction['journalType'] = 'intercompany';
		$transaction = self::copyIntercompanyFields(transaction: $transaction, row: $arr);

		$this->saveTransaction(objectService: $objectService, registerSlug: $registerSlug, transaction: $transaction);
		return true;
	}//end stampIntercompanyRow()

	/**
	 * Fold the intercompany detection and match fields onto the GLTransaction.
	 *
	 * @param array<string,mixed> $transaction The GLTransaction object data.
	 * @param array<string,mixed> $row         The IntercompanyTransaction payload.
	 *
	 * @return array<string,mixed>
	 */
	private static function copyIntercompanyFields(array $transaction, array $row): array {
		$transaction = self::copyNonEmpty(transaction: $transaction, row: $row, fields: self::INTERCOMPANY_FIELDS);

		if (array_key_exists('isMatched', $row) === true) {
			$transaction['isMatched'] = (bool)$row['isMatched'];
		}

		return self::copyNonEmpty(transaction: $transaction, row: $row, fields: ['matchId' => 'matchId']);
	}//end copyIntercompanyFields()

	/**
	 * The GLTransaction a source row links, or null when there is no link, it is not found,
	 * or it already carries the journal type (idempotent: already stamped, skip).
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug  The register slug.
	 * @param string $id            The linked GLTransaction id; empty when none.
	 * @param string $journalType   The journal type being stamped.
	 *
	 * @return array<string,mixed>|null
	 */
	private function unstampedTransaction(object $objectService, string $registerSlug, string $id, string $journalType): array|null {
		if ($id === '') {
			return null;
		}

		$transaction = $this->findGlTransaction(objectService: $objectService, registerSlug: $registerSlug, id: $id);
		if ($transaction === null) {
			return null;
		}

		if (((string)($transaction['journalType'] ?? '')) === $journalType) {
			return null;
		}

		return $transaction;
	}//end unstampedTransaction()

	/**
	 * Copy each non-empty source field onto the GLTransaction under its target name, as a string.
	 *
	 * @param array<string,mixed>  $transaction The GLTransaction object data.
	 * @param array<string,mixed>  $row         The source payload.
	 * @param array<string,string> $fields      Source field name to GLTransaction field name.
	 *
	 * @return array<string,mixed>
	 */
	private static function copyNonEmpty(array $transaction, array $row, array $fields): array {
		foreach ($fields as $source => $target) {
			$value = (string)($row[$source] ?? '');
			if ($value !== '') {
				$transaction[$target] = $value;
			}
		}

		return $transaction;
	}//end copyNonEmpty()

	/**
	 * Save the stamped GLTransaction without RBAC or multi-tenancy (installer context).
	 *
	 * @param object              $objectService The OR ObjectService.
	 * @param string              $registerSlug  The register slug.
	 * @param array<string,mixed> $transaction   The GLTransaction object data.
	 *
	 * @return void
	 */
	private function saveTransaction(object $objectService, string $registerSlug, array $transaction): void {
		$objectService->saveObject(
			object: $transaction,
			register: $registerSlug,
			schema: 'GLTransaction',
			_rbac: false,
			_multitenancy: false,
		);
	}//end saveTransaction()

	/**
	 * Resolve a single GLTransaction record by its OpenRegister id/uuid,
	 * returned as the plain object-data array (carrying its `id`, so a
	 * subsequent saveObject is an UPDATE not a CREATE). Null when not
	 * found.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug The register slug.
	 * @param string $id The GLTransaction id/uuid.
	 *
	 * @return array<string,mixed>|null The GLTransaction object data, or null.
	 */
	private function findGlTransaction(
		object $objectService,
		string $registerSlug,
		string $id,
	): ?array {
		try {
			$found = ObjectIdentifier::findScoped(
				scoped: $objectService->setRegister($registerSlug)->setSchema('GLTransaction'),
				id: $id,
				named: ['_rbac' => false, '_multitenancy' => false]
			);
		} catch (\Throwable) {
			return null;
		}

		if ($found === null) {
			return null;
		}

		// GetObject() returns the clean object data with `id` prepended,
		// which makes the later saveObject resolve as an UPDATE.
		$data = $found->getObject();
		if (is_array($data) === false || $data === []) {
			return null;
		}

		return $data;
	}//end findGlTransaction()
}//end class
