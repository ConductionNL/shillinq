<?php

/**
 * Rules Audit Command
 *
 * `occ shillinq:rules:audit` — runs RuleAuditService against the register's
 * bookkeeping objects and prints the compliance posture: rule-corpus coverage,
 * objects checked / compliant, violations by severity, and the most-violated
 * rules. Read-only; it reports whether shillinq complies, it does not change data.
 *
 * @category Command
 * @package  OCA\Shillinq\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment
 */

declare(strict_types=1);

namespace OCA\Shillinq\Command;

use OCA\Shillinq\Service\RuleAuditService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * OCC command that audits bookkeeping data against the rule corpus.
 */
class RulesAuditCommand extends Command {
	/**
	 * Construct the rule-audit command.
	 *
	 * @param RuleAuditService $auditService The compliance auditor.
	 */
	public function __construct(
		private readonly RuleAuditService $auditService,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Configure the command name, description and options.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName('shillinq:rules:audit')
			->setDescription('Audit bookkeeping data against the machine-checkable rule corpus.')
			->addOption('jurisdiction', null, InputOption::VALUE_REQUIRED, 'Jurisdiction context (ISO alpha-2)', 'NL');

	}//end configure()

	/**
	 * Execute the audit and print the compliance summary to the console.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$report = $this->auditService->audit(['jurisdiction' => (string)$input->getOption('jurisdiction')]);

		$output->writeln('<info>Shillinq rule-compliance audit</info>');
		$output->writeln(sprintf('  catalogue version : %s', $report['catalogueVersion']));
		$output->writeln(sprintf('  corpus rules      : %d (machine-checkable: %d)', $report['corpusTotal'], $report['machineCheckable']));
		$output->writeln(sprintf('  enforceable today : %d (%.1f%% of machine-checkable)', $report['enforceableRules'], $report['coveragePct']));
		$output->writeln('');
		$output->writeln(sprintf('  objects checked   : %d', $report['objectsChecked']));
		$output->writeln(sprintf('  compliant         : %d', $report['objectsCompliant']));
		$output->writeln(sprintf('  with violations   : %d', $report['objectsWithViolations']));
		$output->writeln(
			sprintf(
				'  violations        : %d mandatory / %d conditional / %d recommended',
				$report['violationsBySeverity']['mandatory'] ?? 0,
				$report['violationsBySeverity']['conditional'] ?? 0,
				$report['violationsBySeverity']['recommended'] ?? 0
			)
		);

		foreach ($report['types'] as $type => $stat) {
			$output->writeln(
				sprintf(
					'    %-16s checked=%d compliant=%d withViolations=%d',
					$type,
					$stat['checked'],
					$stat['compliant'],
					$stat['withViolations']
				)
			);
		}

		if (empty($report['topViolatedRules']) === false) {
			$output->writeln('');
			$output->writeln('  top violated rules:');
			foreach ($report['topViolatedRules'] as $row) {
				$output->writeln(sprintf('    %-34s %d', $row['ruleId'], $row['count']));
			}
		}

		return 0;
	}//end execute()
}//end class
