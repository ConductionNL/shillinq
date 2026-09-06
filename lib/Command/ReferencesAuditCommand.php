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

		$owners = [];
		try {
			foreach ($this->readAllRows($objectService, $ownerRegister, self::OWNER_SCHEMA) as $row) {
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
			$owners = [];
		}//end try

		$ownerIds = [];
		foreach ($owners as $ids) {
			foreach ($ids as $id) {
				$ownerIds[$id] = true;
			}
		}

		$counts = ['set' => 0, 'dangling' => 0, 'backfillable' => 0, 'ambiguous' => 0, 'unmatched' => 0, 'written' => 0];

		foreach ($satellites as $raw) {
			$row = $this->rowPayload($raw);
			if ($row === []) {
				continue;
			}

			$id = (string)($row['id'] ?? '');
			$reference = trim((string)($row[self::REFERENCE_PROPERTY] ?? ''));
			$identity = trim((string)($row[self::IDENTITY_KEY] ?? ''));

			if ($reference !== '') {
				if ($ownerIds === [] || isset($ownerIds[$reference]) === true) {
					$counts['set']++;
					continue;
				}

				$counts['dangling']++;
				$output->writeln('  <error>dangling</error>  ' . $id . ' -> ' . $reference);
				continue;
			}

			$candidates = ($owners[$identity] ?? []);
			if ($identity === '' || $candidates === []) {
				$counts['unmatched']++;
				continue;
			}

			if (count($candidates) > 1) {
				$counts['ambiguous']++;
				$output->writeln(
					'  <comment>ambiguous</comment> ' . $id . ' ' . self::IDENTITY_KEY . '=' . $identity
					. ' matches ' . count($candidates) . ' owners'
				);
				continue;
			}

			$counts['backfillable']++;
			if ($write === false) {
				continue;
			}

			try {
				// Patch, NOT saveObject/updateObject. Those two are
				// PUT-semantic: a property absent from the payload is written
				// as null, so a one-field update through them quietly clears
				// every field the read did not return. Patch merges.
				$objectService->patchObject(
					objectId: $id,
					data: [self::REFERENCE_PROPERTY => $candidates[0]],
					register: 'shillinq',
					schema: self::SATELLITE_SCHEMA,
					_rbac: false,
					_multitenancy: false
				);
				$counts['written']++;
			} catch (Throwable $e) {
				$output->writeln('  <error>write failed</error> ' . $id . ': ' . $e->getMessage());
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
}//end class
