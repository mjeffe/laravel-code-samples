<?php

namespace Tests\Feature\Services\PayoffCalculator;

use App\Models\Debt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\PayoffCalculator\PayoffCalculator;

class PayoffStrategyBehaviorTest extends PayoffCalculatorBase
{
    use RefreshDatabase;
    
    public function test_avalanche_strategy_prioritizes_highest_interest_rate_first()
    {
        // Arrange
        $this->createTieredInterestScenario();
        
        // Act
        $engine = PayoffCalculator::createEngine('avalanche', $this->debts, $this->extraAmount);
        $history = $engine->run();
        
        // the first payment, of the first debt that paid off
        $firstPayment = $history->first()->first();
        
        // Assert
        // Verify the first debt paid off was the one with highest interest
        $this->assertEquals(22.99, $firstPayment->interest_rate);
        
        // Verify it received more than minimum payment (extra amount was applied)
        $this->assertGreaterThan(
            $firstPayment->min_payment,
            $firstPayment->total_payment
        );
    }

    public function test_avalanche_strategy_uses_balance_as_tiebreaker()
    {
        $this->createSameInterestScenario();
        
        $engine = PayoffCalculator::createEngine('avalanche', $this->debts, $this->extraAmount);
        $history = $engine->run();
        
        // the first payment, of the first debt that paid off
        $firstPayment = $history->first()->first();
        
        // When interest rates are equal, verify lower balance was paid first
        $this->assertEquals(3500, $firstPayment->starting_balance);
        
        // Verify it received more than minimum payment (extra amount was applied)
        $this->assertGreaterThan(
            $firstPayment->min_payment,
            $firstPayment->total_payment
        );
    }

    public function test_snowball_strategy_prioritizes_lowest_balance_first()
    {
        $this->createTieredInterestScenario();
        
        $engine = PayoffCalculator::createEngine('snowball', $this->debts, $this->extraAmount);
        $history = $engine->run();
        
        // get the first payment, of the first debt that paid off
        $firstPayment = $history->first()->first();
        
        // Verify the first debt paid off was the one with lowest balance
        $this->assertEquals(5000, $firstPayment->starting_balance);
        
        // Verify it received more than minimum payment (extra amount was applied)
        $this->assertGreaterThan(
            $firstPayment->min_payment,
            $firstPayment->total_payment
        );
    }

    public function test_snowball_strategy_uses_id_as_tiebreaker()
    {
        // Create two debts with the same balance but different IDs
        $this->debts = collect([
            Debt::factory()->create([
                'user_id' => $this->user->id,
                'balance' => 5000,
                'interest_rate' => 15.99,
                'min_payment' => 150
            ]),
            Debt::factory()->create([
                'user_id' => $this->user->id,
                'balance' => 5000,
                'interest_rate' => 22.99,
                'min_payment' => 150
            ])
        ]);
        
        $engine = PayoffCalculator::createEngine('snowball', $this->debts, $this->extraAmount);
        $history = $engine->run();
        
        // get the first payment, of the first debt that paid off
        $firstPayment = $history->first()->first();
        
        // When balances are equal, verify the first created debt was paid first
        $this->assertEquals($this->debts->first()->id, $firstPayment->debt_id);
        
        // Verify it received more than minimum payment (extra amount was applied)
        $this->assertGreaterThan(
            $firstPayment->min_payment,
            $firstPayment->total_payment
        );
    }

    public function test_lamp_prioritizes_debt_with_fewest_payments()
    {
        $this->createFastestPayoffScenario();
        
        $engine = PayoffCalculator::createEngine('lamp', $this->debts, $this->extraAmount);
        $history = $engine->run();
        
        // First debt to be paid off should be the 5000 balance debt
        $firstPayment = $history->first()->first();
        $this->assertEquals(5000, $firstPayment->starting_balance);
        
        // After first debt is paid off, the 10000 balance debt should become the target
        // because it would take fewer payments with the increased available amount
        $secondDebtFirstPayment = $history->skip(1)->first()->first();
        $this->assertEquals(10000, $secondDebtFirstPayment->starting_balance);
        
        // Last debt to be paid off should be the 15000 balance debt
        $lastDebtFirstPayment = $history->last()->first();
        $this->assertEquals(15000, $lastDebtFirstPayment->starting_balance);
        
        // Verify payment counts follow expected pattern
        $this->assertLessThan(
            $history->skip(1)->first()->count(),
            $history->first()->count(),
            'First debt should take fewer payments than second debt'
        );
        $this->assertLessThan(
            $history->last()->count(),
            $history->skip(1)->first()->count(),
            'Second debt should take fewer payments than last debt'
        );
    }

    public function test_lamp_reprioritizes_after_debt_payoff()
    {
        $this->createExcessMinPaymentScenario();
        
        $engine = PayoffCalculator::createEngine('lamp', $this->debts, $this->extraAmount);
        $history = $engine->run();
        
        // Get the first month's payments
        $firstMonthPayments = $history->map(fn ($debt) => $debt->first());
        
        // The small debt should be paid off in first payment
        $smallDebtPayment = $firstMonthPayments->first();
        $this->assertEquals(0, $smallDebtPayment->ending_balance);
        
        // The second debt should receive:
        // - Its minimum payment ($300)
        // - The extra amount ($500)
        // - Most of the leftover from small debt's min payment 
        //   (slightly less than $25 due to interest on small debt)
        $largeDebtPayment = $firstMonthPayments->last();
        
        // Verify it received more than minimum + extra
        $this->assertGreaterThan(
            $largeDebtPayment->min_payment + $this->extraAmount,
            $largeDebtPayment->total_payment,
            'Second debt should receive more than minimum plus extra amount'
        );
        
        // But less than minimum + extra + full leftover (due to interest)
        $maxExpected = $largeDebtPayment->min_payment + $this->extraAmount + 25;
        $this->assertLessThan(
            $maxExpected,
            $largeDebtPayment->total_payment,
            'Second debt payment should be less than min + extra + full leftover due to interest'
        );
    }

    public function test_lamp_maintains_consistent_payment_amounts()
    {
        $this->createFastestPayoffScenario();
        
        $engine = PayoffCalculator::createEngine('lamp', $this->debts, $this->extraAmount);
        $history = $engine->run();
        
        // Get the first target debt's history (5000 balance)
        $targetDebtHistory = $history->first(function ($debtHistory) {
            $payment = $debtHistory->first();
            return $payment->starting_balance == 5000;
        });
        
        // Get all payments except the last one (which might be partial to finish payoff)
        $regularPayments = $targetDebtHistory->slice(0, -1);
        
        // First target should receive consistent payments until paid off
        $expectedAmount = $this->debts->first()->min_payment + $this->extraAmount;
        foreach ($regularPayments as $payment) {
            $this->assertEquals(
                $expectedAmount,
                $payment->total_payment,
                'Target debt should receive consistent payment amount until paid off'
            );
        }
    }

    public function test_mindue_only_makes_minimum_payments()
    {
        $this->createSimpleDebtScenario();
        
        $engine = PayoffCalculator::createEngine('min_due', $this->debts, $this->extraAmount);
        $history = $engine->run();
        
        // Get first month's payments
        $firstMonthPayments = $history->map(fn ($debt) => $debt->first());
        
        // Each debt only receives its minimum payment
        foreach ($firstMonthPayments as $payment) {
            $this->assertEquals(
                $payment->min_payment,
                $payment->total_payment,
                'Each debt should only receive its minimum payment'
            );
            
            $this->assertNull(
                $payment->extra_payment,
                'No extra payments should be made'
            );
        }
    }

    public function test_mindue_never_applies_leftover_amounts()
    {
        $this->createExcessMinPaymentScenario();
        
        $engine = PayoffCalculator::createEngine('min_due', $this->debts, $this->extraAmount);
        $history = $engine->run();
        
        // Get the first month's payments
        $firstMonthPayments = $history->map(fn ($debt) => $debt->first());
        
        // The small debt should receive exactly its minimum payment
        $smallDebtPayment = $firstMonthPayments->first();
        $this->assertEquals(
            $smallDebtPayment->min_payment,
            $smallDebtPayment->total_payment,
            'Small debt should receive exactly minimum payment'
        );
        
        // The large debt should receive only its minimum payment
        // even though there's leftover from the small debt
        $largeDebtPayment = $firstMonthPayments->last();
        $this->assertEquals(
            $largeDebtPayment->min_payment,
            $largeDebtPayment->total_payment,
            'Large debt should receive only minimum payment despite leftover from small debt'
        );
        
        // Verify no extra payments were made
        foreach ($firstMonthPayments as $payment) {
            $this->assertNull(
                $payment->extra_payment,
                'No extra payments should be made even with leftover amounts'
            );
        }
    }
}