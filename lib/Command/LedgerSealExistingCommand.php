<?php

/**
 * Ledger Seal Existing Command
 *
 * `occ shillinq:ledger:seal-existing <administrationId>`: the one-off genesis pass that
 * seals the posted transactions of an administration that has no seals yet, in
 * posting order. It refuses an administration that already has seals: rewriting
 * seals has to be asked for by a person (ledger-sealed-entries design, Migration Plan).
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
 * @spec openspec/changes/ledger-sealed-entries/specs/bookkeeping-audit-trail/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Command;

use OCA\Shillinq\Service\Ledger\LedgerSealService;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Seal an administration's existing posted transactions once.
 *
 * @spec openspec/changes/ledger-sealed-entries/design.md
 */
class LedgerSealExistingCommand extends Command {
	/**
	 * Wire the seal chain.
	 *
	 * @param LedgerSealService $seals The seal chain.
	 */
	public function __construct(
		private readonly LedgerSealService $seals,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name, description and argument.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ledger-sealed-entries/design.md
	 */
	protected function configure(): void {
		$this->setName(name: 'shillinq:ledger:seal-existing')
			->setDescription(description: 'Seal the posted transactions of an administration that has no seals yet, in posting order.')
			->addArgument(name: 'administrationId', mode: InputArgument::REQUIRED, description: 'The administration to seal');
	}//end configure()

	/**
	 * Seal, or refuse an administration that already has seals.
	 *
	 * @param InputInterface  $input  The input.
	 * @param OutputInterface $output The output.
	 *
	 * @return integer 0 when sealed, 1 when refused.
	 *
	 * @spec openspec/changes/ledger-sealed-entries/design.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$count = $this->seals->sealExisting(administrationId: (string)$input->getArgument('administrationId'));
		} catch (RuntimeException $e) {
			$output->writeln('<error>'.$e->getMessage().'</error>');
			return self::FAILURE;
		}

		$output->writeln(sprintf('<info>Sealed %d posted transactions.</info>', $count));
		return self::SUCCESS;
	}//end execute()
}//end class
