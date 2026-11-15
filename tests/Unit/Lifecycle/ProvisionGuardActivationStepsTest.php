<?php

/**
 * Characterisation tests for ProvisionGuard::canActivateProvision.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-voorzieningen-claims/specs/bookkeeping-voorzieningen-claims/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\ProvisionGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCA\Shillinq\Tests\Unit\Service\Support\OpenRegisterFaithfulObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins every allowed and refused branch of the Provision activation check:
 * recognition criteria, discounting, linked-detail readiness, materiality
 * sign-offs and the fail-closed exception path.
 */
class ProvisionGuardActivationStepsTest extends TestCase {

	/**
	 * Build a guard on the given object service.
	 *
	 * @param ObjectServiceInterface $objectService The object service to read from.
	 * @param LoggerInterface|null   $logger        Optional logger to observe.
	 *
	 * @return ProvisionGuard The guard under test.
	 */
	private function guard(ObjectServiceInterface $objectService, ?LoggerInterface $logger = null): ProvisionGuard {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new ProvisionGuard(
			appConfig: $appConfig,
			logger: $logger ?? $this->createMock(LoggerInterface::class),
			objectService: $objectService,
		);

	}//end guard()

	/**
	 * Build a guard whose store carries the given rows of one schema.
	 *
	 * @param string                    $schema The schema slug.
	 * @param array<int,array<string,mixed>> $rows   The rows to seed.
	 *
	 * @return ProvisionGuard The guard under test.
	 */
	private function guardWithRows(string $schema, array $rows): ProvisionGuard {
		$store = new OpenRegisterFaithfulObjectService();
		$store->seed(schema: $schema, rows: $rows);

		return $this->guard(objectService: new DuckObjectServiceAdapter($store));

	}//end guardWithRows()

	/**
	 * A complete, immaterial, short-term provision that may activate.
	 *
	 * @return array<string,mixed> The provision payload.
	 */
	private function provision(): array {
		return [
			'provisionType' => 'guarantee',
			'legalOrConstructiveObligation' => 'legal',
			'obligatingEvent' => 'Verkoop met waarborg',
			'probabilityOfOutflow' => 0.75,
			'bestEstimate' => 40000.0,
			'bestEstimateRationale' => 'Historische claim-rate',
			'expectedTiming' => ['shortTerm' => 40000.0, 'longTerm' => 0.0],
			'priorYearBalanceTotal' => 90000000.0,
		];

	}//end provision()

	/**
	 * A material provision carrying all four sign-offs.
	 *
	 * @return array<string,mixed> The provision payload.
	 */
	private function signedMaterialProvision(): array {
		$provision = $this->provision();
		$provision['bestEstimate'] = 150000.0;
		$provision['peerReviewer'] = 'reviewer-1';
		$provision['peerReviewDate'] = '2026-10-01';
		$provision['cfoApprover'] = 'cfo-1';
		$provision['cfoApprovalDate'] = '2026-10-02';

		return $provision;

	}//end signedMaterialProvision()

	/**
	 * Apply overrides to the base provision and check activation.
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 *
	 * @return bool The guard outcome.
	 */
	private function activate(array $overrides): bool {
		$guard = $this->guard(objectService: $this->createMock(ObjectServiceInterface::class));

		return $guard->canActivateProvision(provisionId: 'p-1', object: array_merge($this->provision(), $overrides));

	}//end activate()

	/**
	 * Field overrides that refuse activation.
	 *
	 * @return array<string,array{0:array<string,mixed>}> Cases.
	 */
	public static function refusedOverrides(): array {
		return [
			'unknown obligation'      => [['legalOrConstructiveObligation' => 'moral']],
			'whitespace event'        => [['obligatingEvent' => '   ']],
			'non-numeric probability' => [['probabilityOfOutflow' => 'likely']],
			'probability exactly 0.5' => [['probabilityOfOutflow' => 0.5]],
			'negative estimate'       => [['bestEstimate' => -10.0]],
			'non-numeric estimate'    => [['bestEstimate' => 'lots']],
			'missing rationale'       => [['bestEstimateRationale' => null]],
			'long term, zero rate'    => [['expectedTiming' => ['longTerm' => 5.0], 'discountRateApplied' => 0]],
			'long term, text rate'    => [['expectedTiming' => ['longTerm' => '5'], 'discountRateApplied' => 'x']],
			'ratio above one percent' => [['bestEstimate' => 20000.0, 'priorYearBalanceTotal' => 1000000.0]],
		];

	}//end refusedOverrides()

	/**
	 * Each refusing override denies activation.
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 *
	 * @return void
	 */
	#[DataProvider('refusedOverrides')]
	public function testRefusedOverrideDeniesActivation(array $overrides): void {
		self::assertFalse($this->activate(overrides: $overrides));

	}//end testRefusedOverrideDeniesActivation()

	/**
	 * Field overrides that still allow activation.
	 *
	 * @return array<string,array{0:array<string,mixed>}> Cases.
	 */
	public static function allowedOverrides(): array {
		return [
			'base provision'            => [[]],
			'non-numeric long term'     => [['expectedTiming' => ['longTerm' => 'soon']]],
			'long term with rate'       => [['expectedTiming' => ['longTerm' => 5.0], 'discountRateApplied' => 0.03]],
			'ratio exactly one percent' => [['bestEstimate' => 10000.0, 'priorYearBalanceTotal' => 1000000.0]],
			'zero balance total'        => [['bestEstimate' => 90000.0, 'priorYearBalanceTotal' => 0]],
			'missing balance total'     => [['priorYearBalanceTotal' => null]],
			'unknown provision type'    => [['provisionType' => 'other']],
		];

	}//end allowedOverrides()

	/**
	 * Each allowing override keeps activation possible.
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 *
	 * @return void
	 */
	#[DataProvider('allowedOverrides')]
	public function testAllowedOverrideActivates(array $overrides): void {
		self::assertTrue($this->activate(overrides: $overrides));

	}//end testAllowedOverrideActivates()

	/**
	 * The four sign-off fields.
	 *
	 * @return array<string,array{0:string}> Cases.
	 */
	public static function signOffFields(): array {
		return [
			'peer reviewer'     => ['peerReviewer'],
			'peer review date'  => ['peerReviewDate'],
			'cfo approver'      => ['cfoApprover'],
			'cfo approval date' => ['cfoApprovalDate'],
		];

	}//end signOffFields()

	/**
	 * A material provision missing any one sign-off is refused.
	 *
	 * @param string $field The sign-off field left blank.
	 *
	 * @return void
	 */
	#[DataProvider('signOffFields')]
	public function testMaterialProvisionMissingOneSignOffIsRefused(string $field): void {
		$guard = $this->guard(objectService: $this->createMock(ObjectServiceInterface::class));
		$provision = $this->signedMaterialProvision();
		$provision[$field] = ' ';

		self::assertFalse($guard->canActivateProvision(provisionId: 'p-1', object: $provision));

	}//end testMaterialProvisionMissingOneSignOffIsRefused()

	/**
	 * A material provision with all sign-offs activates.
	 *
	 * @return void
	 */
	public function testSignedMaterialProvisionActivates(): void {
		$guard = $this->guard(objectService: $this->createMock(ObjectServiceInterface::class));

		self::assertTrue($guard->canActivateProvision(provisionId: 'p-1', object: $this->signedMaterialProvision()));

	}//end testSignedMaterialProvisionActivates()

	/**
	 * Restructuring detail variants and the expected outcome.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:bool}> Cases.
	 */
	public static function restructuringDetails(): array {
		return [
			'no balance date'       => [['detailedPlanDate' => '2027-05-01', 'planCommunicatedTo' => ['staff']], true],
			'plan on balance date'  => [['detailedPlanDate' => '2026-12-31', 'balanceDate' => '2026-12-31', 'planCommunicatedTo' => ['staff']], true],
			'missing plan date'     => [['planCommunicatedTo' => ['staff'], 'balanceDate' => '2026-12-31'], false],
			'nobody communicated'   => [['detailedPlanDate' => '2026-11-01', 'planCommunicatedTo' => []], false],
		];

	}//end restructuringDetails()

	/**
	 * A restructuring provision follows its linked detail.
	 *
	 * @param array<string,mixed> $detail   The detail fields.
	 * @param bool                $expected The expected outcome.
	 *
	 * @return void
	 */
	#[DataProvider('restructuringDetails')]
	public function testRestructuringFollowsLinkedDetail(array $detail, bool $expected): void {
		$guard = $this->guardWithRows(
			schema: 'HerstructureringsvoorzieningDetail',
			rows: [array_merge(['id' => 'her-9'], $detail)]
		);
		$provision = $this->provision();
		$provision['provisionType'] = 'restructuring';
		$provision['linkedRestructuringProvisionDetail'] = 'her-9';

		self::assertSame($expected, $guard->canActivateProvision(provisionId: 'p-1', object: $provision));

	}//end testRestructuringFollowsLinkedDetail()

	/**
	 * A linked detail id that does not resolve refuses both detail types.
	 *
	 * @return void
	 */
	public function testUnresolvableLinkedDetailIsRefused(): void {
		$guard = $this->guardWithRows(schema: 'ClaimsVoorzieningDetail', rows: [['id' => 'other']]);

		$claims = $this->provision();
		$claims['provisionType'] = 'claims';
		$claims['linkedClaimsProvisionDetail'] = 'missing';
		self::assertFalse($guard->canActivateProvision(provisionId: 'p-1', object: $claims));

		$restructuring = $this->provision();
		$restructuring['provisionType'] = 'restructuring';
		$restructuring['linkedRestructuringProvisionDetail'] = 'missing';
		self::assertFalse($guard->canActivateProvision(provisionId: 'p-1', object: $restructuring));

	}//end testUnresolvableLinkedDetailIsRefused()

	/**
	 * Without an object and without an id nothing resolves.
	 *
	 * @return void
	 */
	public function testNoObjectAndNoIdIsRefused(): void {
		$guard = $this->guard(objectService: $this->createMock(ObjectServiceInterface::class));

		self::assertFalse($guard->canActivateProvision(provisionId: ''));

	}//end testNoObjectAndNoIdIsRefused()

	/**
	 * The provision is looked up by id when no object is supplied.
	 *
	 * @return void
	 */
	public function testProvisionIsLookedUpById(): void {
		$guard = $this->guardWithRows(schema: 'Provision', rows: [array_merge(['id' => 'p-7'], $this->provision())]);

		self::assertTrue($guard->canActivateProvision(provisionId: 'p-7'));
		self::assertFalse($guard->canActivateProvision(provisionId: 'p-8'));

	}//end testProvisionIsLookedUpById()

	/**
	 * A failing lookup denies the transition and logs the failure.
	 *
	 * @return void
	 */
	public function testLookupFailureDeniesAndLogs(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willThrowException(new \RuntimeException('store down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('error')
			->with(
				'ProvisionGuard: provision activation check failed — denying transition (fail-closed)',
				['provisionId' => 'p-3', 'exception' => 'store down']
			);

		$guard = $this->guard(objectService: $objectService, logger: $logger);

		self::assertFalse($guard->canActivateProvision(provisionId: 'p-3'));

	}//end testLookupFailureDeniesAndLogs()
}//end class
