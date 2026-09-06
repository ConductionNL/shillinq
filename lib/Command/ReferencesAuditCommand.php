<?php

/**
 * Shillinq References Audit Command
 *
 * Audits, and on request backfills, the cross-app uuid reference that links a
 * shillinq satellite record to the record another app owns.
 *
 * @category Command
 * @package  OCA\Shillinq\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://shillinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment
 */

declare(strict_types=1);

namespace OCA\Shillinq\Command;

use OCA\Shillinq\Repair\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Service\HrmqCostRateAdapter;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `occ shillinq:references:audit [--write]`.
 *
 * WHY READ-ONLY BY DEFAULT. The consolidation gave each satellite a plain uuid
 * pointing at the owner's record, and nothing populated it. Filling it in is a
 * cross-app write, and a WRONG cross-app link is worse than an empty one: an
 * empty reference is visibly absent, while a wrong one silently attributes one
 * person's payroll to another. So the default run reports and changes nothing,
 * `--write` fills in only an UNAMBIGUOUS single match on the shared identity
 * key, and anything else is named rather than guessed.
 *
 * The identity key is not a convenience. It is the same key that decided the
 * consolidation in the first place: two records carrying one `bsn` are one
 * person, which is precisely why the two schemas were merged onto an owner.
 *
 * @spec openspec/specs/subject-cost-aggregation/spec.md
 */
class ReferencesAuditCommand extends Command {
	use ReadsSourceRowsInBatches;

	/**
	 * The satellite schema this app owns.
	 *
	 * @var string
	 */
	private const SATELLITE_SCHEMA = 'payrollEmployee';

	/**
	 * The property holding the owner's uuid.
	 *
	 * @var string
	 */
	private const REFERENCE_PROPERTY = 'employee';

	/**
	 * The owner's schema, in humaniq's register.
	 *
	 * @var string
	 */
	private const OWNER_SCHEMA = 'Employee';

	/**
	 * The identity key both sides carry.
	 *
	 * @var string
	 */
	private const IDENTITY_KEY = 'bsn';

	/**
	 * Wire collaborators.
	 *
	 * @param ContainerInterface $container Container, for the lazy ObjectService resolve.
	 * @param HrmqCostRateAdapter $humaniq The one place that knows humaniq's register slug.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly HrmqCostRateAdapter $humaniq,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Declare the command.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName('shillinq:references:audit')
			->setDescription(
				'Audit the payrollEmployee -> humaniq Employee uuid reference. Read-only unless --write.'
			)
			->addOption(
				'write',
				null,
				InputOption::VALUE_NONE,
				'Fill in the reference where exactly one owner record shares the identity key.'
			);
	}//end configure()

	/**
	 * Run the audit.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 when no reference dangles, 1 when at least one does.
	 *
	 * @spec openspec/specs/subject-cost-aggregation/spec.md
	 *   The file, with no anchor, because none of its five requirements is
	 *   about this command: they are about pricing hours, and this is the link
	 *   that lets the hours be found at all. The spec's own opening makes the
	 *   case — "the store with the tidy-looking link had no writer; the store
	 *   with the writer is in the other app" — and this command is what makes
	 *   that cross-app reference real. Pointing at an anchor that does not
	 *   exist would satisfy gate-16 while covering nothing, which the gate
	 *   cannot tell apart.
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$write = (bool)$input->getOption('write');

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			$output->writeln('<error>OpenRegister is not available: ' . $e->getMessage() . '</error>');
			return 1;
		}

		$ownerRegister = $this->humaniq->registerSlug();

		try {
			$satellites = $this->readAllRows($objectService, 'shillinq', self::SATELLITE_SCHEMA);
		} catch (Throwable $e) {
			$output->writeln('<error>could not read ' . self::SATELLITE_SCHEMA . ': ' . $e->getMessage() . '</error>');
			return 1;
		}

		[$owners, $ownerIds] = $this->ownerIndex($objectService, $ownerRegister, $output);

		$counts = ['set' => 0, 'dangling' => 0, 'backfillable' => 0, 'ambiguous' => 0, 'unmatched' => 0, 'written' => 0];

		foreach ($satellites as $raw) {
			$row = $this->rowPayload($raw);
			if ($row === []) {
				continue;
			}

			$verdict = $this->classifyRow($row, $owners, $ownerIds);
			$counts[$verdict['outcome']]++;

			if ($verdict['message'] !== '') {
				$output->writeln($verdict['message']);
			}

			if ($verdict['outcome'] !== 'backfillable' || $write === false) {
				continue;
			}

			if ($this->backfill($objectService, $verdict['id'], $verdict['owner'], $output) === true) {
				$counts['written']++;
			}
		}//end foreach

		$output->writeln('');
		$output->writeln(
			sprintf(
				'%s.%s -> %s.%s via %s: %d set, %d dangling, %d backfillable, %d ambiguous, %d unmatched, %d written',
				self::SATELLITE_SCHEMA,
				self::REFERENCE_PROPERTY,
				$ownerRegister,
				self::OWNER_SCHEMA,
				self::IDENTITY_KEY,
				$counts['set'],
				$counts['dangling'],
				$counts['backfillable'],
				$counts['ambiguous'],
				$counts['unmatched'],
				$counts['written']
			)
		);

		if ($write === false && $counts['backfillable'] > 0) {
			$output->writeln('Re-run with --write to fill in the ' . $counts['backfillable'] . ' unambiguous match(es).');
		}

		if ($counts['dangling'] > 0) {
			return 1;
		}

		return 0;
	}//end execute()

	/**
	 * Decide what one satellite row is, against the index of owners.
	 *
	 * EXTRACTED FROM execute(), which phpmd measured at cyclomatic complexity
	 * 22 against a threshold of 15 and an NPath of 83,376 against 5,000. The
	 * branching was never incidental: this is a five-way classification, and
	 * naming it is what lets execute() read as "classify, report, maybe write".
	 * Behaviour is unchanged, including the order the cases are tested in,
	 * which matters — a row carrying a reference is never a backfill candidate
	 * regardless of what its identity key would have matched.
	 *
	 * @param array<string,mixed>        $row      One satellite row's payload.
	 * @param array<string,list<string>> $owners   Identity key to owner ids.
	 * @param array<string,bool>         $ownerIds Every known owner id.
	 *
	 * @return array{outcome: string, id: string, owner: string, message: string}
	 */
	private function classifyRow(array $row, array $owners, array $ownerIds): array {
		$id        = (string)($row['id'] ?? '');
		$reference = trim((string)($row[self::REFERENCE_PROPERTY] ?? ''));
		$identity  = trim((string)($row[self::IDENTITY_KEY] ?? ''));

		if ($reference !== '') {
			// An empty owner index means humaniq could not be read at all. That
			// is reported as presence, never as a dangling link, or an outage
			// would look like data loss.
			if ($ownerIds === [] || isset($ownerIds[$reference]) === true) {
				return ['outcome' => 'set', 'id' => $id, 'owner' => '', 'message' => ''];
			}

			return [
				'outcome' => 'dangling',
				'id'      => $id,
				'owner'   => '',
				'message' => '  <error>dangling</error>  ' . $id . ' -> ' . $reference,
			];
		}

		$candidates = ($owners[$identity] ?? []);
		if ($identity === '' || $candidates === []) {
			return ['outcome' => 'unmatched', 'id' => $id, 'owner' => '', 'message' => ''];
		}

		if (count($candidates) > 1) {
			return [
				'outcome' => 'ambiguous',
				'id'      => $id,
				'owner'   => '',
				'message' => '  <comment>ambiguous</comment> ' . $id . ' ' . self::IDENTITY_KEY . '=' . $identity
					. ' matches ' . count($candidates) . ' owners',
			];
		}

		return ['outcome' => 'backfillable', 'id' => $id, 'owner' => $candidates[0], 'message' => ''];
	}//end classifyRow()

	/**
	 * Write one unambiguous owner reference onto a satellite row.
	 *
	 * @param mixed           $objectService The OpenRegister object service.
	 * @param string          $id            The satellite row's id.
	 * @param string          $owner         The owner id to record.
	 * @param OutputInterface $output        Console output.
	 *
	 * @return bool True when the write landed.
	 */
	private function backfill(mixed $objectService, string $id, string $owner, OutputInterface $output): bool {
		try {
			// Patch, NOT saveObject/updateObject. Those two are PUT-semantic: a
			// property absent from the payload is written as null, so a
			// one-field update through them quietly clears every field the read
			// did not return. Patch merges.
			$objectService->patchObject(
				objectId: $id,
				data: [self::REFERENCE_PROPERTY => $owner],
				register: 'shillinq',
				schema: self::SATELLITE_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
			return true;
		} catch (Throwable $e) {
			$output->writeln('  <error>write failed</error> ' . $id . ': ' . $e->getMessage());
			return false;
		}//end try
	}//end backfill()

	/**
	 * Read humaniq's owner records into an index keyed by the identity key.
	 *
	 * EXTRACTED FROM execute() alongside classifyRow(), for the same phpmd
	 * finding. This half is a read that is allowed to fail: an absent humaniq
	 * is a normal state, not a fault, so it answers with an empty index and
	 * says so rather than letting the caller decide.
	 *
	 * @param mixed           $objectService The OpenRegister object service.
	 * @param string          $register      humaniq's register slug.
	 * @param OutputInterface $output        Console output.
	 *
	 * @return array{0: array<string,list<string>>, 1: array<string,bool>} Owners by identity key, and every owner id.
	 */
	private function ownerIndex(mixed $objectService, string $register, OutputInterface $output): array {
		$owners = [];

		try {
			foreach ($this->readAllRows($objectService, $register, self::OWNER_SCHEMA) as $row) {
				$owner = $this->rowPayload($row);
				if ($owner === []) {
					continue;
				}

				$key = trim((string)($owner[self::IDENTITY_KEY] ?? ''));
				if ($key === '') {
					continue;
				}

				$owners[$key][] = (string)($owner['id'] ?? '');
			}
		} catch (Throwable $e) {
			// An absent humaniq is a normal state, not a fault. Say so plainly:
			// with no owner register there is nothing to resolve against, and
			// reporting every reference as dangling would be a lie.
			$output->writeln(
				'<comment>humaniq is not readable (' . $e->getMessage() . '). '
				. 'Nothing can be resolved or backfilled; reporting presence only.</comment>'
			);
			return [[], []];
		}//end try

		$ownerIds = [];
		foreach ($owners as $ids) {
			foreach ($ids as $id) {
				$ownerIds[$id] = true;
			}
		}

		return [$owners, $ownerIds];
	}//end ownerIndex()

}//end class
