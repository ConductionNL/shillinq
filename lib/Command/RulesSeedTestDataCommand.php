<?php

/**
 * Rules Seed Test-Data Command
 *
 * `occ shillinq:rules:seed-testdata` — idempotently backfills the local TEST data
 * so it satisfies the enforced rules (sourceReference + balanced lines on every GL
 * transaction). Run after a clean-env reset so a fresh environment audits at 100%.
 * Test/dev utility only.
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

use OCA\Shillinq\Service\RuleTestDataSeeder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * OCC command that seeds compliant local test data.
 */
class RulesSeedTestDataCommand extends Command {
	/**
	 * Construct the seed-testdata command.
	 *
	 * @param RuleTestDataSeeder $seeder The test-data seeder.
	 */
	public function __construct(
		private readonly RuleTestDataSeeder $seeder,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Configure the command name and description.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName('shillinq:rules:seed-testdata')
			->setDescription('Backfill local test data to satisfy the enforced rules (idempotent; test/dev only).');

	}//end configure()

	/**
	 * Execute the command — run the seeder and print the result summary.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $input is required by
	 *     Symfony Command::execute()'s signature; this command takes no
	 *     console input.
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$result = $this->seeder->seed();

		$output->writeln('<info>Shillinq rules test-data seeder</info>');
		$output->writeln(sprintf('  sourceReference backfilled : %d', $result['sourceReferencesAdded']));
		$output->writeln(sprintf('  GLLines added              : %d', $result['linesAdded']));
		$output->writeln(sprintf('  ARInvoice lines backfilled : %d', ($result['invoiceLinesAdded'] ?? 0)));
		$output->writeln(sprintf('  already compliant          : %d', $result['alreadyCompliant']));
		$output->writeln('Run <info>occ shillinq:rules:audit</info> to confirm 100% compliance.');

		return 0;
	}//end execute()
}//end class
