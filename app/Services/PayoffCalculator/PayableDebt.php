<?php

namespace App\Services\PayoffCalculator;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use App\Models\Debt;
use App\Models\Payment;
use App\Services\PayoffCalculator\Contracts\InterestCalculationStrategy;

/**
 * Decorate Debt models with monthly payment calculations and stat tracking
 * 
 * NOTE !!!!!!!!!!!  The debt is not immutable!
 * While the member debt is a clone of the source debt, we are mutating the debt
 * model until the balance is 0. This is probably fine for our purposes. To make
 * this immutable, we might look into something like:
 * 
 *   https://www.yellowduck.be/posts/making-eloquent-models-immutable-with-a-trait-in-laravel 
 */
class PayableDebt {
    /**
     * The source debt model
     * 
     * @var \App\Models\Debt
     */
    private Debt $debt;

    /**
     * The strategy function used to calculate interest on the debt
     * 
     * @var InterestCalculationStrategy
     */
    private InterestCalculationStrategy $interestStrategy;

    /**
     * All payments made on this debt
     * 
     * @var array
     */
    private array $paymentHistory = [];

    /**
     * Keep track of which payment we are on
     * 
     * @var int
     */
    private int $paymentNumber;

    /**
     * The date at which to start calculations
     * 
     * @var Carbon
     */
    private ?Carbon $startDate = null;

    /**
     * Instantiate a PayableDebt
     * 
     * A PayableDebt is a Debt model decorator that adds functionality for
     * making monthly payments and keeping track of payments over time.
     * 
     * @param \App\Models\Debt $debt
     * @param \App\Services\PayoffCalculator\Contracts\InterestCalculationStrategy $strategy
     */
    public function __construct(Debt $debt, InterestCalculationStrategy $strategy)
    {
        // doesn't exactly make debts immutable, but gets us closer
        $this->debt = $debt->replicate();
        $this->debt->id = $debt->id;

        $this->interestStrategy = $strategy;

        // we only care about year and month, so arbitrarily set to first of month.
        $this->startDate = Carbon::now()->firstOfMonth();

        // initialize the paymentNumber so the first payment will be for THIS month.
        //
        // $this->processPaymentCycle() increments $paymentNumber each time it's
        // called, so setting to -1 will give us month 0 as the first payment.
        //
        // NOTE If you change this, it may affect how DebtPaymentSyncinator's
        // filter works, so be sure to check that.
        $this->paymentNumber = -1;
    }

    /**
     * Set the date at which to start calculations
     * 
     * @param \Carbon\Carbon $startDate
     * @return void
     */
    public function addStartDate(Carbon $startDate): void
    {
        // we only care about year and month, so arbitrarily set to first of month.
        $this->startDate = $startDate->firstOfMonth();
    }

    /*
     * The magic methods that preserve the source Eloquent Model functionality
     * 
     * TODO: explore extracting this to a trait and add a static ::wrap() method to instantiate
     * see: https://github.com/igaster/eloquent-decorator/tree/master
     * see: https://dev.to/ahmedash95/design-patterns-in-php-decorator-with-laravel-5hk6
     */
    public function __get($name)
    {
        return $this->debt->$name;
    }
    public function __set($name, $value)
    {
        $this->debt->$name = $value;
    }
    public function __call($method, $parameters)
    {
        return $this->debt->$method(...$parameters);
    }
    public function __isset ($name)
    {
        return isset($this->debt->$name);
    }

    /*
     * The remaining are the additional Decorator methods
     */

    /**
     * Return the collection of Payments made to payoff the debt
     * 
     * @return Collection
     */
    public function getPaymentHistory(): Collection
    {
        return collect($this->paymentHistory);
    }

    /**
     * Run the sequence of operations to handle a monthly payment cycle
     * 
     * @param float $paymentAmount
     * @return float
     */
    public function processPaymentCycle(float $amount): float
    {
        // Start a new payment cycle.
        // Note, the constructor initializes this so that the first payment will be for **THIS** month.
        $this->paymentNumber++;

        $paymentDate = $this->startDate->copy()->addMonths($this->paymentNumber);

        $startingBalance = $this->debt->balance;

        $amountAfterInterest = $this->applyInterest($amount);

        $actualPayment = $this->applyPayment($amountAfterInterest);

        $this->paymentHistory[$this->paymentNumber] = new Payment([
            'payment_date' => $paymentDate,
            'debt_id' => $this->debt->id,
            'debt_name' => $this->debt->name,
            'interest_rate' => $this->debt->interest_rate,
            //'escrow' => $this->debt->escrow,
            'min_payment' => $this->debt->min_payment,
            'extra_payment' => null,
            'total_payment' => $amount,
            'interest_amount' => $amount - $amountAfterInterest,
            'principal_amount' => $actualPayment,
            'starting_balance' => $startingBalance,
            'ending_balance' => $this->debt->balance,
        ]);

        // return any leftover
        return $amountAfterInterest - $actualPayment;
    }

    /**
     * In any given payment cycle, optionally apply an additional payment to the principal
     * 
     * @param float $amount
     * @return float
     */
    public function processExtraPayment(float $amount): float
    {
        if (!array_key_exists($this->paymentNumber, $this->paymentHistory)) {
            throw new \Exception(
                'Cannot apply an extra payment until after the normal monthly payment cycle has been processed'
            );
        }

        $actualPayment = $this->applyPayment($amount);

        $this->paymentHistory[$this->paymentNumber]->extra_payment = $actualPayment;
        $this->paymentHistory[$this->paymentNumber]->total_payment += $actualPayment;
        $this->paymentHistory[$this->paymentNumber]->principal_amount += $actualPayment;
        $this->paymentHistory[$this->paymentNumber]->ending_balance -= $actualPayment;

        // return any leftover
        return $amount - $actualPayment;
    }

    /**
     * Calculate and apply a cycle's interest to the payment amount
     * 
     * @return float payment amount after subtracting interest
     */
    protected function applyInterest(float $amount): float
    {
        $interest = $this->interestStrategy->calculateInterest(
            $this->debt->balance,
            $this->debt->interest_rate
        );

        // pay the interest (if any)
        $amountAfterInterest = $amount - $interest;
        if ($amountAfterInterest < 0) {
            throw new \Exception('the payment amount is insufficient to cover the interest');
        }

        return $amountAfterInterest;
    }

    /**
     * Apply a payment directly to the balance
     * 
     * @param float $amount
     * @return float Amount actually applied
     */
    protected function applyPayment(float $amount): float
    {
        // avoid overpaying on the last payment
        $actualPayment = min($this->debt->balance, $amount);

        $this->debt->balance -= $actualPayment;

        return $actualPayment;
    }
}
