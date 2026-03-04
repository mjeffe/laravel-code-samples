<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Carbon\Carbon;
use App\Services\PayoffCalculator\Actions\SavePayoffCalculations;
use App\Services\PayoffCalculator\PayoffCalculator;
use App\Models\User;

class CalculatePayoff implements ShouldQueue
{
    use Queueable;

    protected User $user;
    protected ?Carbon $startDate = null;
    protected $paymentSaver;

    /**
     * Create a new job instance.
     * 
     * You can dispatch this job using:
     * 
     *      CalculatePayoff::dispatch($user);
     * 
     * You may also pass an optional start date which tells the calculator to start calculations at the given date.
     * NOTE: this was implemented to provide a retroactive fix, so is relatively experimental.
     * 
     * NOTE: jobs are a little backwards to what we are used to when using the
     * Laravel IOC Container. The constructor receives whatever is passed in the
     * dispatch call.  The `handle()` function on the other hand, is invoked
     * when the job is run, and can therefore receive type hinted dependencies
     * you want injected by the service container.
     */
    public function __construct(User $user, ?Carbon $startDate = null)
    {
        $this->user = $user;
        $this->startDate = $startDate;
        $this->onQueue('calculator');
    }

    /**
     * Execute the job.
     * 
     * Accepts type hinted dependencies to be injected by the service container.
     */
    public function handle(SavePayoffCalculations $paymentSaver): void
    {
        $this->paymentSaver = $paymentSaver;

        if (!$this->shouldRun($this->user)) {
            return;
        }

        $extra = $this->gleanExtraFromDebts($this->user);

        $this->runPayoffCalculator($this->user, $extra);
    }

    /**
     * Define the conditions that determine if the Payoff calculations should be run
     * 
     * @param \App\Models\User $user
     * @return bool
     */
    protected function shouldRun(User $user): bool
    {
        return (
            $user->debts()->count()         // has some debts
            && $user->setup_completed_at    // finished with setup
            && $user->payoffOptions()->exists()   // payoff options are defined
        );
    }

    /**
     * Glean the difference between min_payments and actual_payment
     * 
     * Gleaned amount is a technique used with our default payoff strategy as
     * the difference between what a debt's min payment is and what the user
     * actually pays.  We want to reduce payments to the min, and use any extra
     * amount we can find to put towards the target debt.  This is added to what
     * the user has said is an extra amount they can put towards debt.
     * 
     * @param \App\Models\User $user
     * @return float
     */
    protected function gleanExtraFromDebts(User $user): float
    {
        $gleanedAmount = $user->debts->sum('gleaned_amount');

        return $gleanedAmount + $user->payoffOptions->extra_amount;
    }

    /**
     * Run the PayoffCalculator and save results
     * 
     * @param \App\Models\User $user
     * @param mixed $extra
     * @return void
     */
    protected function runPayoffCalculator(User $user, $extra): void
    {
        // calculate payments using both the user's payoff strategy and baseline
        $strategies = [$user->payoffOptions->payoff_strategy, 'baseline'];

        foreach ($strategies as $strategy) {
            try {
                $engine = PayoffCalculator::createEngine($strategy, $user->debts, $extra);

                if (!empty($this->startDate)) {
                    $engine->addStartDate($this->startDate);
                }

                $engine->run();

                $this->paymentSaver->execute($user, $engine);
            } catch (\Exception $e) {
                \Log::error("There was a problem running PayoffCalculator", [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'payoff_strategy' => $strategy,
                    'error_message' => $e->getMessage(),
                    'stack_trace' => $e->getTraceAsString(),
                ]);
            }
        }
    }
}
