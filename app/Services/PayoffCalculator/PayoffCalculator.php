<?php

namespace App\Services\PayoffCalculator;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use App\Services\PayoffCalculator\DebtCollection;
use App\Services\PayoffCalculator\Factories\PayoffFactory;
use App\Services\PayoffCalculator\Contracts\PayoffStrategy;
use App\Services\PayoffCalculator\Factories\PayableDebtFactory;


class PayoffCalculator
{
    private PayoffStrategy $payoffStrategy;
    private DebtCollection $debts;
    private float $extraPayment = 0;
    private float $runTimeSeconds = 0;
    private Carbon $runAt;

    /**
     * Create a new PayoffCalculator engine
     * 
     * @param \App\Services\PayoffCalculator\Contracts\PayoffStrategy $strategy
     * @param \App\Services\PayoffCalculator\DebtCollection $debts
     * @param float $extraPayment
     */
    public function __construct(PayoffStrategy $strategy, DebtCollection $debts, float $extraPayment = 0)
    {
        $this->payoffStrategy = $strategy;
        $this->debts = $debts;
        $this->extraPayment = $extraPayment;
    }

    /**
     * Static factory method, aka alternative constructor.
     * This will instantiate and return an instance of this class
     * 
     * @param string $payoffStrategy
     * @param \Illuminate\Support\Collection $debts
     * @param float $extraPayment
     * @return \App\Services\PayoffCalculator\PayoffCalculator
     */
    public static function createEngine(string $payoffStrategy, Collection $debts, float $extraPayment = 0): self
    {
        $payableDebts = $debts->map(fn($debt) => PayableDebtFactory::create($debt));
        $payableDebtCollection = new DebtCollection($payableDebts);

        $strategy = PayoffFactory::create($payoffStrategy);

        return new static($strategy, $payableDebtCollection, $extraPayment);
    }

    public function addStartDate(Carbon $startDate): void
    {
        foreach ($this->debts as $debt) {
            $debt->addStartDate($startDate);
        }
    }

    /**
     * Run payoff simulation to completion
     * 
     * Returns a Collection of each debt's Collection of Payments
     * 
     * @return Collection<mixed, mixed>|DebtCollection
     */
    public function run(): Collection|DebtCollection
    {
        $this->runAt = Carbon::now();

        $start = microtime(true);

        if ($this->debts->unpaid()->count() > 0) {
            $this->payoffStrategy->executePayoff($this->debts->unpaid(), $this->getTotalPayment());
        }

        $this->runTimeSeconds = microtime(true) - $start;

        return $this->getPayments();
    }

    /**
     * Return the results of a run.  This returns a Collection of debt Payment
     * Collections where debts are sorted into the order in which they paid off:
     * 
     *   Collection (
     *     debtB Collection (
     *       Payment1,
     *       Payment2,
     *       ...
     *     )
     *     debtA => Collection (
     *       Payment1,
     *       Payment2,
     *       ...
     *     )
     *   )
     * 
     * @return Collection<mixed, mixed>|DebtCollection
     */
    public function getPayments(): Collection|DebtCollection
    {
        return $this->debts
            ->map(fn($debt) => $debt->getPaymentHistory())
            ->filter(fn($debt) => $debt->count() > 0) // filter out debts where no payments were made (previously paid off)
            ->sortBy(fn ($debt) => count($debt));
    }

    /**
     * Return statstics and summary info about the run
     * 
     * Possible refactor:
     *  - We may want to break this into other functions. runtime stats, payment
     *    summary, etc, or it might be better to unload that to some other class.
     * 
     * @return array
     */
    public function getStats(): array
    {
        $payments = $this->getPayments();

        return [
            // run time parameters
            'run_at' => $this->runAt,
            'payoff_strategy' => $this->payoffStrategy->getType(),
            'min_payment' => $this->getMinPayment(),
            'extra_payment' => $this->getExtraPayment(),
            'total_payment' => $this->getTotalPayment(),
            // run summary
            'financial_freedom_date' => $payments->max(fn ($debt) => $debt->last()->payment_date),
            'interest_paid' => $payments->sum(fn($debt) => $debt->sum('interest_amount')),
            'principal_paid' => $payments->sum(fn($debt) => $debt->sum('principal_amount')),
            'total_paid' => $payments->sum(fn($debt) => $debt->sum('total_payment')),
            'run_time' => $this->runTimeSeconds,
        ];
    }

    public function getPayoffStrategy(): string
    {
        return $this->payoffStrategy->getType();
    }

    public function getDebts(): DebtCollection
    {
        return $this->debts;
    }

    public function getExtraPayment(): float
    {
        return $this->extraPayment;
    }

    public function getMinPayment(): float
    {
        return $this->debts->sum('min_payment');
    }

    public function getTotalPayment(): float
    {
        return $this->getMinPayment() + $this->getExtraPayment();
    }
}
