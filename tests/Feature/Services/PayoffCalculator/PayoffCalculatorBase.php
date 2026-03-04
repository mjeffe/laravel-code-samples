<?php

namespace Tests\Feature\Services\PayoffCalculator;

use Tests\TestCase;
use App\Models\User;
use App\Models\Debt;
use App\Models\PayoffOptions;
use Illuminate\Support\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\PayoffCalculator\PayoffCalculator;

class PayoffCalculatorBase extends TestCase
{
    use RefreshDatabase;
    
    protected User $user;
    protected Collection $debts;
    protected PayoffOptions $options;
    protected float $extraAmount = 500.00;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Create a user and their payoff options
        $this->user = User::factory()->create();
        $this->options = PayoffOptions::factory()->create([
            'user_id' => $this->user->id
        ]);
    }

    /**
     * Creates a simple scenario with two debts of different balances and interest rates
     */
    protected function createSimpleDebtScenario(): void
    {
        $this->debts = collect([
            Debt::factory()->create([
                'user_id' => $this->user->id,
                'balance' => 5000,
                'interest_rate' => 15.99,
                'min_payment' => 150
            ]),
            Debt::factory()->create([
                'user_id' => $this->user->id,
                'balance' => 10000,
                'interest_rate' => 22.99,
                'min_payment' => 300
            ])
        ]);
    }

    /**
     * Creates a scenario with debts having different interest rates
     * Useful for testing Avalanche strategy prioritization
     */
    protected function createTieredInterestScenario(): void
    {
        $this->debts = collect([
            Debt::factory()->create([
                'user_id' => $this->user->id,
                'balance' => 10000,
                'interest_rate' => 22.99,
                'min_payment' => 300
            ]),
            Debt::factory()->create([
                'user_id' => $this->user->id,
                'balance' => 8000,
                'interest_rate' => 15.99,
                'min_payment' => 240
            ]),
            Debt::factory()->create([
                'user_id' => $this->user->id,
                'balance' => 5000,
                'interest_rate' => 9.99,
                'min_payment' => 150
            ])
        ]);
    }

    /**
     * Creates a scenario with debts having the same interest rate
     * Useful for testing tiebreaker behavior
     */
    protected function createSameInterestScenario(): void
    {
        $this->debts = collect([
            Debt::factory()->create([
                'user_id' => $this->user->id,
                'balance' => 10000,
                'interest_rate' => 15.99,
                'min_payment' => 300
            ]),
            Debt::factory()->create([
                'user_id' => $this->user->id,
                'balance' => 3500,
                'interest_rate' => 15.99,
                'min_payment' => 105
            ])
        ]);
    }

    /**
     * Creates a scenario where we know which debt will be paid off fastest with extra payment
     * Initially, Debt 1 (5000) gets all extra money and pays off in ~9 months
     * After that, totalAvailablePayment = 1300 (all min payments + extra)
     * At that point, Debt 3 should become the target as it would take fewer payments
     * than Debt 2 with the new larger available payment
     */
    protected function createFastestPayoffScenario(): void
    {
        $this->debts = collect([
            Debt::factory()->create([
                'user_id' => $this->user->id,
                'balance' => 5000,
                'interest_rate' => 10.99,
                'min_payment' => 100
                // Gets paid first due to lowest balance
            ]),
            Debt::factory()->create([
                'user_id' => $this->user->id,
                'balance' => 15000,
                'interest_rate' => 15.99,
                'min_payment' => 400
                // With 1300 available after first debt pays off
                // Takes ~14 payments to pay remaining balance
            ]),
            Debt::factory()->create([
                'user_id' => $this->user->id,
                'balance' => 10000,
                'interest_rate' => 22.99,
                'min_payment' => 300
                // With 1300 available after first debt pays off
                // Takes ~9 payments to pay remaining balance
                // Should become target after first debt is paid
            ])
        ]);
    }

    /**
     * Creates a scenario where a minimum payment exceeds the remaining balance
     * Useful for testing leftover payment handling
     */
    protected function createExcessMinPaymentScenario(): void
    {
        $this->debts = collect([
            Debt::factory()->create([
                'user_id' => $this->user->id,
                'balance' => 75, // Very low balance
                'interest_rate' => 15.99,
                'min_payment' => 100 // Min payment exceeds balance
            ]),
            Debt::factory()->create([
                'user_id' => $this->user->id,
                'balance' => 10000,
                'interest_rate' => 22.99,
                'min_payment' => 300
            ])
        ]);
    }
}