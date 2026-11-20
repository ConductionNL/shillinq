<?php

/**
 * Unit tests for OpenItemClearingRefusedException.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Ledger
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ledger-open-item-clearing/specs/bookkeeping-general-ledger/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Ledger;

use DomainException;
use OCA\Shillinq\Service\Ledger\OpenItemClearingRefusedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The refusal keeps its template for translation and renders it as its message.
 */
#[CoversClass(OpenItemClearingRefusedException::class)]
final class OpenItemClearingRefusedExceptionTest extends TestCase {

	/**
	 * The template and its parameters are kept apart, and the message is the filled template.
	 *
	 * @return void
	 */
	public function testKeepsTheTemplateAndRendersTheMessage(): void {
		$e = new OpenItemClearingRefusedException('Account %1$s is not a balance account kept by open item.', ['1950']);

		$this->assertInstanceOf(DomainException::class, $e);
		$this->assertSame('Account %1$s is not a balance account kept by open item.', $e->getTemplate());
		$this->assertSame(['1950'], $e->getParameters());
		$this->assertSame('Account 1950 is not a balance account kept by open item.', $e->getMessage());

	}//end testKeepsTheTemplateAndRendersTheMessage()

	/**
	 * A refusal without placeholders has no parameters.
	 *
	 * @return void
	 */
	public function testAPlainRefusalHasNoParameters(): void {
		$e = new OpenItemClearingRefusedException('Select at least two lines to clear.');

		$this->assertSame([], $e->getParameters());
		$this->assertSame('Select at least two lines to clear.', $e->getMessage());

	}//end testAPlainRefusalHasNoParameters()
}//end class
