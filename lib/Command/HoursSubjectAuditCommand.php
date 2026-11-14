<?php

/**
 * Shillinq Hours Subject Audit Command
 *
 * Counts the hour entries that still name a domain object through the
 * `subjectApp` / `subjectId` overlay, before that overlay is retired.
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
 *
 * @spec openspec/changes/hours-to-humaniq/specs/hours-to-humaniq/spec.md#requirement-req-h2h-001-hours-on-a-domain-object-live-in-humaniq
 */

declare(strict_types=1);

namespace OCA\Shillinq\Command;

use OCA\Shillinq\Kernel\Support\ReadsSourceRowsInBatches;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `occ shillinq:hours:subject-audit`.
 *
 * Read-only. Before `UrenRegistratie.subjectApp` / `subjectId` are deleted,
 * every install must show that no hour entry carries them, or the hours they
 * attribute are lost. The run counts, per administration, the entries with a
 * non-null `subjectApp` (the count the change's tasks ask for) and, separately,
 * those with a non-null `subjectId`: an entry with only one of the two set is
 * still data the retirement would drop.
 *
 * Exit code 0 means nothing to migrate; 1 means at least one entry carries a
 * subject (or the register could not be read), which reopens the design.
 *
 * @spec openspec/changes/hours-to-humaniq/specs/hours-to-humaniq/spec.md#requirement-req-h2h-001-hours-on-a-domain-object-live-in-humaniq
 */
class HoursSubjectAuditCommand extends Command {
	use ReadsSourceRowsInBatches;

	/**
	 * The hour schema.
	 *
	 * @var string
	 */
	public const HOUR_SCHEMA = 'UrenRegistratie';

	/**
	 * The label for an entry without an administration.
	 *
	 * @var string
	 */
	public const NO_ADMINISTRATION = '(none)';

	/**
	 * Wire collaborators.
	 *
	 * @param ContainerInterface $container Container, for the lazy ObjectService resolve.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Declare the command.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hours-to-humaniq/specs/hours-to-humaniq/spec.md#requirement-req-h2h-001-hours-on-a-domain-object-live-in-humaniq
	 */
	protected function configure(): void {
		$this->setName('shillinq:hours:subject-audit')
			->setDescription(
				'Count UrenRegistratie entries that still carry subjectApp / subjectId, per administration. Read-only.'
			);
	}//end configure()

	/**
	 * Run the count.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 when no entry carries a subject, 1 otherwise.
	 *
	 * @spec openspec/changes/hours-to-humaniq/specs/hours-to-humaniq/spec.md#requirement-req-h2h-001-hours-on-a-domain-object-live-in-humaniq
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$rows = $this->readAllRows($objectService, 'shillinq', self::HOUR_SCHEMA);
		} catch (Throwable $e) {
			$output->writeln('<error>could not read ' . self::HOUR_SCHEMA . ': ' . $e->getMessage() . '</error>');
			return 1;
		}

		$counts = $this->count(rows: $rows);

		$total = ['entries' => 0, 'subjectApp' => 0, 'subjectId' => 0];
		foreach ($counts as $administration => $count) {
			$output->writeln(
				sprintf(
					'%s: %d entries, %d with subjectApp, %d with subjectId',
					$administration,
					$count['entries'],
					$count['subjectApp'],
					$count['subjectId']
				)
			);
			foreach ($total as $key => $value) {
				$total[$key] = ($value + $count[$key]);
			}
		}

		$output->writeln(
			sprintf(
				'Total: %d entries, %d with subjectApp, %d with subjectId',
				$total['entries'],
				$total['subjectApp'],
				$total['subjectId']
			)
		);

		if ($total['subjectApp'] > 0 || $total['subjectId'] > 0) {
			$output->writeln('<comment>Entries carry a domain subject: migrate them before the fields are retired.</comment>');
			return 1;
		}

		$output->writeln('Nothing to migrate.');
		return 0;
	}//end execute()

	/**
	 * Count the entries per administration, sorted by administration.
	 *
	 * @param array<int, mixed> $rows The hour entries as read.
	 *
	 * @return array<string, array{entries: int, subjectApp: int, subjectId: int}>
	 *
	 * @spec openspec/changes/hours-to-humaniq/specs/hours-to-humaniq/spec.md#requirement-req-h2h-001-hours-on-a-domain-object-live-in-humaniq
	 */
	private function count(array $rows): array {
		$counts = [];
		foreach ($rows as $raw) {
			$row = $this->rowPayload($raw);
			if ($row === []) {
				continue;
			}

			$administration = $row['administrationId'] ?? null;
			if (is_scalar($administration) === false || (string)$administration === '') {
				$administration = self::NO_ADMINISTRATION;
			}

			$administration = (string)$administration;
			$counts[$administration] ??= ['entries' => 0, 'subjectApp' => 0, 'subjectId' => 0];
			$counts[$administration]['entries']++;
			if (($row['subjectApp'] ?? null) !== null) {
				$counts[$administration]['subjectApp']++;
			}

			if (($row['subjectId'] ?? null) !== null) {
				$counts[$administration]['subjectId']++;
			}
		}//end foreach

		ksort($counts, SORT_STRING);

		return $counts;
	}//end count()
}//end class
