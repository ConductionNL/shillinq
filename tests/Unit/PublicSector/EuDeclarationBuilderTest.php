<?php

/**
 * REQ-EUF-012 / REQ-EUF-013 / REQ-EUF-007: proposing an EU project's
 * declaration for a quarter, submitting it whole or not at all, and the open
 * irregularity that blocks a new one.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\PublicSector;

use DomainException;
use OCA\OpenRegister\Lifecycle\LifecycleActionContext;
use OCA\Shillinq\Kernel\Contracts\ObjectTransitionRunnerInterface;
use OCA\Shillinq\Lifecycle\Action\SubmitEuDeclarationAction;
use OCA\Shillinq\Lifecycle\EuExpenditureGuard;
use OCA\Shillinq\Service\PublicSector\EuDeclarationBuilder;
use OCA\Shillinq\Tests\Unit\Service\InMemoryObjectService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Service/InMemoryObjectService.php';

/**
 * Tests for EuDeclarationBuilder and the declaration's submit action.
 *
 * @covers \OCA\Shillinq\Service\PublicSector\EuDeclarationBuilder
 * @covers \OCA\Shillinq\Lifecycle\Action\SubmitEuDeclarationAction
 * @uses   \OCA\Shillinq\Support\RegisterSlug
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
final class EuDeclarationBuilderTest extends TestCase {

	/**
	 * The project under test.
	 */
	private const PROJECT = ['id' => 'eu-1', 'administrationId' => 'gem-1', 'euCoFundingRate' => 0.4];

	/**
	 * The in-memory store.
	 *
	 * @var InMemoryObjectService
	 */
	private InMemoryObjectService $store;

	/**
	 * The expenditures whose evidence the guard judges incomplete.
	 *
	 * @var list<string>
	 */
	private array $incomplete = [];

	/**
	 * The transitions the builder ran, as "id:action".
	 *
	 * @var list<string>
	 */
	private array $ran = [];

	/**
	 * Seed a project with three eligible expenditures in the third quarter of
	 * 2026, one without a certified supporting document.
	 *
	 * @return EuDeclarationBuilder
	 */
	private function builder(): EuDeclarationBuilder {
		$this->store = new InMemoryObjectService();
		$expenditure = static fn (string $id, array $fields = []): array => array_merge(
			['id' => $id, 'administrationId' => 'gem-1', 'euProjectId' => 'eu-1', 'costCategory' => 'kapitaal', 'grossAmount' => 1000.0, 'vatTreatment' => 'niet_verrekenbaar', 'declarationPeriod' => '2026-Q3', 'status' => 'gedeclareerd', 'state' => 'gedeclareerd', 'eligibilityConfirmed' => true, 'description' => 'Expenditure ' . $id],
			$fields
		);
		$this->store->seed(
			'EuExpenditure',
			[
				$expenditure('exp-a', ['declaredAmount' => 10000.0, 'euCoFundingAmount' => 4000.0]),
				$expenditure('exp-b', ['declaredAmount' => 2500.55]),
				$expenditure('exp-c', ['declaredAmount' => 3000.0]),
				$expenditure('exp-q2', ['declarationPeriod' => '2026-Q2', 'declaredAmount' => 99.0]),
				$expenditure('exp-other', ['euProjectId' => 'eu-2', 'declaredAmount' => 99.0]),
				$expenditure('exp-certified', ['state' => 'gecertificeerd', 'declaredAmount' => 99.0]),
			]
		);
		$this->store->seed(
			'SupportingDocument',
			[
				['administrationId' => 'gem-1', 'euExpenditureId' => 'exp-a', 'documentType' => 'factuur', 'sourceUri' => 'x', 'sha256Hash' => 'h', 'state' => 'certified'],
				['administrationId' => 'gem-1', 'euExpenditureId' => 'exp-b', 'documentType' => 'factuur', 'sourceUri' => 'x', 'sha256Hash' => 'h', 'state' => 'certified'],
				['administrationId' => 'gem-1', 'euExpenditureId' => 'exp-c', 'documentType' => 'factuur', 'sourceUri' => 'x', 'sha256Hash' => 'h', 'state' => 'draft'],
			]
		);

		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturn('shillinq');
		$guard = $this->createMock(EuExpenditureGuard::class);
		$guard->method('canSubmit')->willReturnCallback(fn (string $euExpenditureId): bool => in_array($euExpenditureId, $this->incomplete, true) === false);
		$runner = $this->createMock(ObjectTransitionRunnerInterface::class);
		$runner->method('run')->willReturnCallback(
			function (string $objectId, string $action): void {
				$this->ran[] = $objectId . ':' . $action;
			}
		);

		return new EuDeclarationBuilder(appConfig: $config, objectService: new DuckObjectServiceAdapter($this->store), guard: $guard, transitions: $runner);

	}//end builder()

	/**
	 * The scenario: two expenditures with their total and EU share, the third
	 * left out because its evidence is missing.
	 *
	 * @return void
	 */
	public function testTheQuarterlyDeclarationHoldsTheExpenditureWithCertifiedEvidence(): void {
		$declaration = $this->builder()->propose(project: self::PROJECT, periodFrom: '2026-07-01', periodTo: '2026-09-30');

		self::assertSame(['exp-a', 'exp-b'], $declaration['expenditureIds']);
		self::assertSame(12500.55, $declaration['eligibleTotal']);
		// 4000.00 recorded on exp-a, 40 percent of 2500.55 on exp-b.
		self::assertSame(5000.22, $declaration['euShare']);
		self::assertSame('draft', $declaration['state']);
		self::assertSame([['expenditureId' => 'exp-c', 'description' => 'Expenditure exp-c', 'reason' => 'Its evidence is missing: no certified supporting document.']], $declaration['leftOut']);

		$stored = $this->store->dump('EuDeclaration');
		self::assertCount(1, $stored);
		self::assertSame($stored[0]['id'], $declaration['id']);
		self::assertSame([], RegisterSchema::errors('EuDeclaration', array_diff_key($stored[0], ['id' => true])));

	}//end testTheQuarterlyDeclarationHoldsTheExpenditureWithCertifiedEvidence()

	/**
	 * Expenditure another declaration holds is not declared twice; undeclared,
	 * unconfirmed and incomplete expenditure is left out with its reason.
	 *
	 * @return void
	 */
	public function testEveryLeftOutExpenditureCarriesItsReasonAndNothingIsDeclaredTwice(): void {
		$builder = $this->builder();
		$builder->propose(project: self::PROJECT, periodFrom: '2026-07-01', periodTo: '2026-09-30');
		$this->store->seed(
			'EuExpenditure',
			[
				['id' => 'exp-posted', 'euProjectId' => 'eu-1', 'declarationPeriod' => '2026-Q3', 'state' => 'posted'],
				['id' => 'exp-unconfirmed', 'euProjectId' => 'eu-1', 'declarationPeriod' => '2026-Q3', 'state' => 'gedeclareerd', 'eligibilityConfirmed' => false],
				['id' => 'exp-incomplete', 'euProjectId' => 'eu-1', 'declarationPeriod' => '2026-Q3', 'state' => 'gedeclareerd', 'eligibilityConfirmed' => true, 'declaredAmount' => 50.0],
			]
		);
		$this->store->seed('SupportingDocument', [['euExpenditureId' => 'exp-incomplete', 'state' => 'certified']]);
		$this->incomplete = ['exp-incomplete'];

		$second = $builder->propose(project: self::PROJECT, periodFrom: '2026-07-01', periodTo: '2026-09-30');

		self::assertSame([], $second['expenditureIds']);
		self::assertSame(0.0, $second['eligibleTotal']);
		self::assertSame(
			[
				'exp-c'           => 'Its evidence is missing: no certified supporting document.',
				'exp-posted'      => 'Not declared yet: declare the expenditure first.',
				'exp-unconfirmed' => 'Its eligibility is not confirmed.',
				'exp-incomplete'  => 'Its evidence is incomplete: a required supporting document or the procurement file is missing.',
			],
			array_column($second['leftOut'], 'reason', 'expenditureId')
		);

	}//end testEveryLeftOutExpenditureCarriesItsReasonAndNothingIsDeclaredTwice()

	/**
	 * An open irregularity blocks a new declaration; a final or withdrawn one
	 * does not. A bad period is refused.
	 *
	 * @return void
	 */
	public function testAnOpenIrregularityBlocksANewDeclaration(): void {
		$builder = $this->builder();
		$this->store->seed('IrregularityReport', [['euProjectId' => 'eu-1', 'state' => 'final'], ['euProjectId' => 'eu-1', 'state' => 'ingetrokken']]);
		self::assertCount(2, $builder->propose(project: self::PROJECT, periodFrom: '2026-07-01', periodTo: '2026-09-30')['expenditureIds']);

		$this->store->seed('IrregularityReport', [['euProjectId' => 'eu-1', 'state' => 'vervolgcontrole', 'nature' => 'dubbelfinanciering', 'amountConcerned' => 15400.0]]);
		try {
			$builder->propose(project: self::PROJECT, periodFrom: '2026-10-01', periodTo: '2026-12-31');
			self::fail('A declaration was proposed over an open irregularity.');
		} catch (DomainException $e) {
			self::assertStringContainsString('open irregularity', $e->getMessage());
		}

		self::assertCount(1, $this->store->dump('EuDeclaration'));

		$this->expectException(DomainException::class);
		$this->builder()->propose(project: self::PROJECT, periodFrom: '2026-09-30', periodTo: '2026-07-01');

	}//end testAnOpenIrregularityBlocksANewDeclaration()

	/**
	 * The scenario: submitting the declaration submits both expenditures, through
	 * the declared submit transition.
	 *
	 * @return void
	 */
	public function testSubmittingTheDeclarationSubmitsEachExpenditure(): void {
		$builder = $this->builder();
		$declaration = $builder->propose(project: self::PROJECT, periodFrom: '2026-07-01', periodTo: '2026-09-30');

		$declared = RegisterSchema::schema('EuDeclaration')['x-openregister-lifecycle']['transitions']['submit'];
		self::assertSame('submitted', $declared['to']);
		self::assertContains(SubmitEuDeclarationAction::class, array_column($declared['actions'], 'action'));

		$declaration['state'] = 'submitted';
		$action = new SubmitEuDeclarationAction(declarations: $builder);
		$context = new LifecycleActionContext(objectData: $declaration, previousData: [], parameters: [], actionName: SubmitEuDeclarationAction::class);
		$result = $action->execute($context);

		self::assertSame(['exp-a:submit', 'exp-b:submit'], $this->ran);
		self::assertSame('submitted', $result['state']);
		self::assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, $result['submittedAt']));

	}//end testSubmittingTheDeclarationSubmitsEachExpenditure()

	/**
	 * One expenditure that can no longer be submitted stops the whole
	 * declaration before anything is submitted; an empty one is refused.
	 *
	 * @return void
	 */
	public function testADeclarationIsSubmittedWholeOrNotAtAll(): void {
		$builder = $this->builder();
		$declaration = $builder->propose(project: self::PROJECT, periodFrom: '2026-07-01', periodTo: '2026-09-30');
		$this->incomplete = ['exp-b'];

		try {
			$builder->submit(declaration: $declaration);
			self::fail('A declaration with a blocked expenditure was submitted.');
		} catch (DomainException $e) {
			self::assertStringContainsString('exp-b', $e->getMessage());
		}

		self::assertSame([], $this->ran);

		$this->expectException(DomainException::class);
		$builder->submit(declaration: ['expenditureIds' => ['', null]]);

	}//end testADeclarationIsSubmittedWholeOrNotAtAll()
}//end class
