<?php

/**
 * E-invoice Requeue Command
 *
 * `occ shillinq:einvoice:requeue` lists the sales invoices the old send path
 * left at delivery status `queued` without handing them to integriq, and, once
 * the operator confirms, sends each again through integriq's event register
 * (sales-einvoice-exchange design, Migration Plan; REQ-SEIX-001). Without
 * confirmation it only lists.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-1.4
 */

declare(strict_types=1);

namespace OCA\Shillinq\Command;

use OCA\Shillinq\Service\EInvoice\EInvoiceService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Throwable;

/**
 * Lists, and on confirmation re-sends, e-invoices stuck at queued.
 *
 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-1.4
 */
class EInvoiceRequeueCommand extends Command {
	/**
	 * One listed invoice: administration, invoice number, old payload reference.
	 *
	 * @var string
	 */
	private const LINE_FORMAT = '  %-38s %-16s %s';

	/**
	 * Construct the command.
	 *
	 * @param EInvoiceService $eInvoiceService Lists and re-sends the stuck invoices.
	 */
	public function __construct(
		private readonly EInvoiceService $eInvoiceService,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Configure the command name, description and options.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-1.4
	 */
	protected function configure(): void {
		$this->setName(name: 'shillinq:einvoice:requeue')
			->setDescription(description: 'List e-invoices the old send path left at queued and, when confirmed, send them again through integriq.')
			->addOption(
				name: 'yes',
				shortcut: 'y',
				mode: InputOption::VALUE_NONE,
				description: 'Send the listed invoices again without asking.'
			);
	}//end configure()

	/**
	 * List the stuck invoices, ask, then send each again.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 when nothing is stuck, nothing was confirmed or every invoice
	 *             was queued again; 1 when one or more could not be sent.
	 *
	 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-1.4
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$stuck = $this->eInvoiceService->queuedByTheOldPath();
		if ($stuck === []) {
			$output->writeln('<info>No e-invoice is waiting at queued from the old send path.</info>');
			return 0;
		}

		$output->writeln(sprintf('<info>%d e-invoice(s) are at queued but were never handed to integriq:</info>', count($stuck)));
		$output->writeln(sprintf(self::LINE_FORMAT, 'Administration', 'Invoice', 'Old payload reference'));
		foreach ($stuck as $invoice) {
			$output->writeln(
				sprintf(
					self::LINE_FORMAT,
					(string)($invoice['administrationId'] ?? ''),
					(string)($invoice['invoiceNumber'] ?? ''),
					(string)($invoice['payloadFileUri'] ?? '')
				)
			);
		}

		if ($this->confirmed(input: $input, output: $output) === false) {
			$output->writeln('Nothing was sent. Run again with --yes, or answer yes, to send them.');
			return 0;
		}

		$failed = 0;
		foreach ($stuck as $invoice) {
			if ($this->requeueOne(invoice: $invoice, output: $output) === false) {
				$failed++;
			}
		}

		if ($failed > 0) {
			$output->writeln(
				sprintf(
					'<error>%d of %d e-invoice(s) were not sent; they stay at queued. Send those by email or fix and run again.</error>',
					$failed,
					count($stuck)
				)
			);
			return 1;
		}

		$output->writeln(sprintf('<info>%d e-invoice(s) handed to integriq.</info>', count($stuck)));
		return 0;
	}//end execute()

	/**
	 * Whether the operator confirmed: `--yes`, or a yes on an interactive prompt.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return bool True to send.
	 */
	private function confirmed(InputInterface $input, OutputInterface $output): bool {
		if ($input->getOption('yes') === true) {
			return true;
		}

		if ($input->isInteractive() === false) {
			return false;
		}

		$helper = new QuestionHelper();

		return (bool)$helper->ask($input, $output, new ConfirmationQuestion('Send these e-invoices again through integriq? [y/N] ', false));
	}//end confirmed()

	/**
	 * Send one stuck invoice again and print the outcome.
	 *
	 * @param array<string,mixed> $invoice The stuck invoice.
	 * @param OutputInterface     $output  Console output.
	 *
	 * @return bool True when it was queued through integriq.
	 */
	private function requeueOne(array $invoice, OutputInterface $output): bool {
		$number = (string)($invoice['invoiceNumber'] ?? '');
		try {
			$result = $this->eInvoiceService->requeue(invoice: $invoice);
		} catch (Throwable $e) {
			$output->writeln(sprintf('  <error>%s: %s</error>', $number, $e->getMessage()));
			return false;
		}

		if ($result['deliveryStatus'] === 'queued') {
			$output->writeln(sprintf('  %s: queued, payload %s', $number, (string)$result['payloadFileUri']));
			return true;
		}

		$reasons = array_map(static fn (array $warning): string => (string)($warning['message'] ?? ''), $result['warnings']);
		$output->writeln(sprintf('  <error>%s: not sent. %s</error>', $number, implode(' ', array_filter($reasons))));
		return false;
	}//end requeueOne()
}//end class
