<?php

namespace Tests\Feature\Services\PayoffCalculator;

use App\Models\Debt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\PayoffCalculator\PayoffCalculator;

class PaymentProcessingTest extends PayoffCalculatorBase
{
    use RefreshDatabase;
    
    public function test_minimum_payments_are_always_made()
    {
        // Arrange
        $this->createTieredInterestScenario();
        
        // Act
        $engine = PayoffCalculator::createEngine('snowball', $this->debts, $this->extraAmount);
        $history = $engine->run();
        
        // Assert
        foreach ($history as $debtHistory) {
            foreach ($debtHistory as $payment) {
                // Every payment should be at least the minimum payment
                $this->assertGreaterThanOrEqual(
                    $payment->min_payment,
                    $payment->total_payment,
                    'Payment was less than minimum required'
                );
            }
        }
    }

    public function test_extra_payment_is_applied_to_target_debt()
    {
        $this->createSimpleDebtScenario();
        $expectedMinPayments = $this->debts->sum('min_payment');
        
        $engine = PayoffCalculator::createEngine('avalanche', $this->debts, $this->extraAmount);
        $history = $engine->run();
        
        // Get the first month's payments
        $firstMonthPayments = $history->map(fn ($debt) => $debt->first());
        $totalFirstMonthPayment = $firstMonthPayments->sum('total_payment');
        
        // Total payments in first month should equal minimum payments plus extra amount
        $this->assertEquals(
            $expectedMinPayments + $this->extraAmount,
            $totalFirstMonthPayment,
            'Extra payment was not fully distributed'
        );

        // Find the highest interest rate debt's first payment
        $highestInterestDebtPayment = $firstMonthPayments
            ->sortByDesc('interest_rate')
            ->first();

        // Highest interest debt should receive its minimum payment plus the extra amount
        $this->assertEquals(
            $highestInterestDebtPayment->min_payment + $this->extraAmount,
            $highestInterestDebtPayment->total_payment,
            'Extra payment was not applied to highest interest debt'
        );
    }

    public function test_leftover_payments_are_applied_correctly()
    {
        // Create a scenario where one debt will be paid off with money left over
        $this->debts = collect([
            Debt::factory()->create([
                'user_id' => $this->user->id,
                'balance' => 200, // Small balance that will be paid off immediately
                'interest_rate' => 15.99,
                'min_payment' => 50
            ]),
            Debt::factory()->create([
                'user_id' => $this->user->id,
                'balance' => 10000,
                'interest_rate' => 22.99,
                'min_payment' => 300
            ])
        ]);
        
        // min payments + extra amount
        $totalAvailableForPayments = 50 + 300 + $this->extraAmount;

        $engine = PayoffCalculator::createEngine('snowball', $this->debts, $this->extraAmount);
        $history = $engine->run();
        
        // Get the first month's payments
        $firstMonthPayments = $history->map(fn ($debt) => $debt->first());
        
        // The small balance debt should be paid off in the first month
        $smallDebtHistory = $history->first();
        $this->assertCount(
            1, 
            $smallDebtHistory,
            'Small debt should be paid off in first month'
        );
        
        $smallDebtPayment = $firstMonthPayments->first();
        $this->assertEquals(
            0, 
            $smallDebtPayment->ending_balance,
            'Small debt should have zero balance after first payment'
        );
        
        // Verify total amount paid minus interest, matches the original balance
        $this->assertEquals(
            200,
            $smallDebtPayment->total_payment - $smallDebtPayment->interest_amount,
            'Total payment (regular + extra) should equal original balance'
        );

        // The remaining amount should be applied to the other debt
        $largeDebtPayment = $firstMonthPayments->last();
        $expectedExtra = $totalAvailableForPayments - $smallDebtPayment->total_payment; // 647.335
        $this->assertEquals(
            $expectedExtra,
            $largeDebtPayment->total_payment,
            'Leftover payment was not applied correctly'
        );
    }
}