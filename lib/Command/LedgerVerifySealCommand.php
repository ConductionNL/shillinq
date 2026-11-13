<?php

/**
 * Ledger Verify Seal Command
 *
 * `occ shillinq:ledger:verify-seal <administrationId>`: checks the seal chain of the
 * posted transactions of one administration (ledger-sealed-entries REQ-LSE-002).
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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Check an administration's ledger seal chain.
 *
 * @spec openspec/changes/ledger-sealed-entries/specs/bookkeeping-audit-trail/spec.md#requirement-a-controller-checks-the-seal-chain-req-lse-002
 */
class LedgerVerifySealCommand extends Command {
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
	 * @spec openspec/changes/ledger-sealed-entries/specs/bookkeeping-audit-trail/spec.md#requirement-a-controller-checks-the-seal-chain-req-lse-002
	 */
	protected function configure(): void {
		$this->setName(name: 'shillinq:ledger:verify-seal')
			->setDescription(description: 'Check that no posted transaction of an administration changed since it was sealed.')
			->addArgument(name: 'administrationId', mode: InputArgument::REQUIRED, description: 'The administration to check');
	}//end configure()

	/**
	 * Report the chain intact with its length, or the first entry that breaks it.
	 *
	 * @param InputInterface  $input  The input.
	 * @param OutputInterface $output The output.
	 *
	 * @return integer 0 when intact, 1 when broken.
	 *
	 * @spec openspec/changes/ledger-sealed-entries/specs/bookkeeping-audit-trail/spec.md#requirement-a-controller-checks-the-seal-chain-req-lse-002
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$result = $this->seals->verify(administrationId: (string)$input->getArgument('administrationId'));
		if ($result['intact'] === true) {
			$output->writeln(sprintf('<info>The ledger seal chain is intact over %d entries.</info>', $result['entries']));
			return self::SUCCESS;
		}

		$name = (string)($result['transactionNumber'] ?? '');
		if ($name === '') {
			$name = (string)$result['transactionId'];
		}

		$output->writeln(
			sprintf('<error>Transaction %s is the first entry that no longer matches its seal (%s).</error>', $name, (string)$result['reason'])
		);
		return self::FAILURE;
	}//end execute()
}//end class
