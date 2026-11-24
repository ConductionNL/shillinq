<?php

/**
 * Book Captures Command
 *
 * `occ shillinq:payments:book-captures` lists the invoices paid through a
 * payment provider whose capture has no booking yet (paid before
 * receivables-provider-payouts booked captures), and with `--book` books each
 * one at its capture date: debit clearing, credit debtors (design Migration
 * Plan, REQ-RPPO-003). Read-only without `--book`.
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
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Command;

use OCA\Shillinq\Kernel\Contracts\RegisterLocationInterface;
use OCA\Shillinq\Service\Payout\CaptureBookingService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists, and on request books, the captures of provider-paid invoices without a booking.
 *
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.3
 */
class BookCapturesCommand extends Command {

	/**
	 * One listed capture: date, payment id, invoice, amount.
	 *
	 * @var string
	 */
	private const LINE_FORMAT = '  %-10s  %-24s  invoice %-24s  %12s';

	/**
	 * Constructor.
	 *
	 * @param RegisterLocationInterface $registerLocation Where the register lives.
	 * @param CaptureBookingService     $captureBooking   Lists and books the captures.
	 */
	public function __construct(
		private readonly RegisterLocationInterface $registerLocation,
		private readonly CaptureBookingService $captureBooking,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name, description and the `--book` option.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.3
	 */
	protected function configure(): void {
		$this->setName(name: 'shillinq:payments:book-captures')
			->setDescription(description: 'List the provider-paid invoices whose capture has no booking yet; book them with --book.')
			->addOption(
				name: 'book',
				shortcut: null,
				mode: InputOption::VALUE_NONE,
				description: 'Book each listed capture at its capture date (debit clearing, credit debtors).'
			);
	}//end configure()

	/**
	 * List the unbooked captures and, with `--book`, book them.
	 *
	 * @param InputInterface  $input  The input.
	 * @param OutputInterface $output The output.
	 *
	 * @return int 0 when everything listed is (now) booked or nothing was asked; 1 when a booking failed.
	 *
	 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.3
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$register = $this->registerLocation->getRegisterSlug();
		$unbooked = $this->captureBooking->unbooked(registerSlug: $register);
		$book = ($input->getOption('book') === true);

		$output->writeln(sprintf('<info>%d provider-paid invoice(s) without a capture booking</info>', count($unbooked)));
		$failed = 0;
		foreach ($unbooked as $capture) {
			$line = sprintf(
				self::LINE_FORMAT,
				$capture['capturedAt'],
				$capture['paymentId'],
				$capture['invoiceReference'],
				number_format($capture['amount'], 2, '.', '')
			);
			if ($book === false) {
				$output->writeln($line);
				continue;
			}

			$journalId = $this->captureBooking->bookRequest(registerSlug: $register, requestId: $capture['requestId']);
			if ($journalId === null) {
				$failed++;
				$output->writeln($line . '  <error>not booked</error>');
				continue;
			}

			$output->writeln($line . '  <info>booked ' . $journalId . '</info>');
		}//end foreach

		if ($book === false && count($unbooked) > 0) {
			$output->writeln('Run again with --book to book them.');
		}

		if ($failed > 0) {
			$output->writeln(sprintf('<error>%d capture(s) could not be booked; see the log.</error>', $failed));
			return 1;
		}

		return 0;
	}//end execute()
}//end class
