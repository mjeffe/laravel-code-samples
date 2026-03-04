<?php

namespace App\Services\Statement;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use App\Models\User;
use App\Models\Payment;
use App\Services\Statement\Contracts\StatementPdfGenerator;
use App\Services\Statement\DataTransferObjects\Statement;
use App\Services\Statement\DataTransferObjects\DebtPayment;
use App\Services\Statement\DataTransferObjects\StatementSummary;

class StatementService
{
    private readonly StatementPdfGenerator $pdfGenerator;

    public function __construct(StatementPdfGenerator $pdfGenerator)
    {
        $this->pdfGenerator = $pdfGenerator;
    }

    /**
     * Get available statement dates for the user
     * Used for populating the dropdown
     */
    public function getPreviousStatementDates(): Collection
    {
        return Payment::select('payment_date')
            ->strategy()
            ->pastMonths()
            ->distinct()
            ->ordered()
            ->get()
            ->map(fn($payment) => $payment->payment_date);
    }

    /**
     * Get the next month's statement
     */
    public function getUpcomingStatement(): ?Statement
    {
        $payments = Payment::query()
            ->strategy()
            ->nextMonth()
            ->ordered()
            ->get();

        return $this->createStatementFromPayments($payments);
    }

    /**
     * Get statement for a specific month
     */
    public function getMonthlyStatement(string $month): ?Statement
    {
        $date = Carbon::parse($month);
        $payments = Payment::query()
            ->strategy()
            ->whereMonth('payment_date', $date->month)
            ->whereYear('payment_date', $date->year)
            ->ordered()
            ->with('debt')
            ->get()
            // filter out deleted debts
            ->filter(fn ($p) => !empty($p->debt));

        return $this->createStatementFromPayments($payments);
    }

    public function toPdf(Statement $statement): string
    {
        return $this->pdfGenerator->generate($statement);
    }

    /**
     * Create a Statement DTO from a collection of payments
     */
    private function createStatementFromPayments(Collection $payments): ?Statement
    {
        if ($payments->isEmpty()) {
            return null;
        }

        $firstPayment = $payments->first();
        $user = $firstPayment->user;
        
        return new Statement(
            statementDate: $firstPayment->payment_date,
            strategy: $firstPayment->payoff_strategy,
            user: $user,
            blueprintLastUpdate: $firstPayment->run?->created_at ?? Carbon::now(),
            financialFreedomDate: $user->financial_freedom_date,
            summary: $this->createSummary($payments, $user),
            debtPayments: $this->createDebtPayments($payments)
        );
    }

    /**
     * Create summary information for the statement
     */
    private function createSummary(Collection $payments, User $user): StatementSummary
    {
        return new StatementSummary(
            totalMonthlyExpenditure: $payments->sum('min_payment') + $payments->sum('extra_payment'),
            totalOriginalBalance: $this->calculateOriginalBalance($payments),
            totalCurrentBalance: $payments->sum('starting_balance'),
            newDebtSinceStart: $this->calculateNewDebtSinceStart($payments),
            debtEliminatedSoFar: $this->calculateEliminatedDebt($user, $payments),
            principalToPayThisMonth: $payments->sum('principal_amount'),
            totalInterestCostThisMonth: $payments->sum('interest_amount')
        );
    }

    /**
     * Create debt payment DTOs for each payment
     */
    private function createDebtPayments(Collection $payments): array
    {
        // FIXME: we should do two group fetches for original balance and payoff
        // date. then in the map refer to these. otherwise, we run two queries
        // for each debt within the map() loop.

        return $payments->map(function ($payment)  {
            return new DebtPayment(
                paymentDate: $payment->payment_date,
                debtId: $payment->debt->id,
                debtType: $payment->debt->type,
                creditorName: $payment->debt_name,
                originalBalance: $this->getOriginalBalance($payment->debt_id),
                currentBalance: $payment->starting_balance,
                balanceAfterPayment: $payment->ending_balance,
                paymentAmount: $payment->total_payment,
                principalAmount: $payment->principal_amount,
                interestAmount: $payment->interest_amount,
                nextMonthPayment: $payment->total_payment,
                payoffDate: $this->getPayoffDate($payment->debt_id),
                escrow: $payment->debt->escrow
            );
        })->toArray();
    }

    /**
     * Calculate how much debt has been eliminated
     *
     * @param \App\Models\User $user
     * @param \Illuminate\Support\Collection $payments
     * @return float
     */
    private function calculateEliminatedDebt(User $user, Collection $payments): float
    {
        // FIXME: this doesn't account for debts added/deleted since program
        // start or changes a user may have made to debts.
        $originalTotal = $this->calculateOriginalBalance($payments);
        $statementTotal = $payments->sum('starting_balance');

        return $originalTotal - $statementTotal;
    }

    /**
     * Get the last payment date for a debt
     */
    private function getPayoffDate(string $debtId): Carbon
    {
        $payment = Payment::query()
            ->where('debt_id', $debtId)
            ->strategy()
            ->isFinal()
            ->first();

        return $payment->payment_date;
    }

    /**
     * Get the starting balance for a debt
     * 
     * @param string $debtId
     * @return float
     */
    private function getOriginalBalance(string $debtId): float
    {
        $payment = Payment::query()
            ->where('debt_id', $debtId)
            ->strategy()
            ->ordered()
            ->first();

        return $payment->starting_balance;
    }

    /**
     * Sum up any manually edited debt changes since the user first started.
     * 
     * Handles:
     *  - user adds a new debt
     *  - user deletes a debt
     *  - user edits a debt and adds to the balance
     *  - user edits a debt and decreases the balance
     *  
     * Any difference between a month's starting balance and the previous month's
     * ending balance must be from user action (adding or reducing debt), since
     * normal payments would make them match.
     *  
     * @param \Illuminate\Support\Collection $payments
     * @return float
     */
    private function calculateNewDebtSinceStart(Collection $payments): float
    {
        $paymentDate = $payments->first()->payment_date;
        $paymentHistory = Payment::query()
            ->where('payment_date', '<=', $paymentDate)
            ->strategy()
            ->get()
            ->groupBy('payment_date');

        $firstPayment = $paymentHistory->first();
        $paymentDates = $paymentHistory->keys()->sort()->values()->toArray();

        $startingBalance = $firstPayment->sum('starting_balance');
        $maxBalance = $startingBalance; // tracks net changes (increases - decreases)

        for ($i = 0; $i < count($paymentDates); $i++) {
            if ($i == 0) { continue; }
            $lastMonthPayment = $paymentHistory[$paymentDates[$i - 1]];
            $thisMonthPayment = $paymentHistory[$paymentDates[$i]];

            $lastMonthBalance = $lastMonthPayment->sum('ending_balance');
            $thisMonthBalance = $thisMonthPayment->sum('starting_balance');

            // TODO: waiting on client feedback before we implement in production
            //$userDeletedDebts = $this->getUserDeletedDebts($lastMonthPayment, $thisMonthPayment);

            if ($thisMonthBalance > $lastMonthBalance) {
                // user added a debt or manually increased the balance of an existing debt
                $maxBalance += $thisMonthBalance - $lastMonthBalance;
            } else if ($thisMonthBalance < $lastMonthBalance) {
                // user deleted a debt or manually decreased the balance of an existing debt
                $maxBalance -= $lastMonthBalance - $thisMonthBalance;
            }
        }

        return $maxBalance - $startingBalance;
    }

    /**
     * detect if user deleted a debt
     * 
     * @param \App\Models\Payment $lastMonthPayment
     * @param \App\Models\Payment $thisMonthPayment
     */
    protected function getUserDeletedDebts(Payment $lastMonthPayment, Payment $thisMonthPayment)
    {
        $userDeletedDebts = collect([]);

        if ($thisMonthPayment->count() < $lastMonthPayment->count()) {
            // find Last month debts missing from this month
            $thisMonthDebtIds = $thisMonthPayment->pluck('debt_id')->toArray();
            $missing = $lastMonthPayment->reject(fn ($p) => in_array($p->debt_id, $thisMonthDebtIds));

            // reject any that were simply paid off, remaining should be user deleted
            $userDeletedDebts = $missing->reject(fn ($p) => ($p->ending_balance == 0));
        }

        return $userDeletedDebts;
    }

    /**
     * Sum up how much debt the user had with the original version of this payment's debts
     * 
     * This uses history to find the original version of a debt. But it is
     * problematic. Since this is simply a record of all changes. A user can
     * mistakenly set a debt to 9000, then change it to the intended 8000. The
     * following would return 9000.
     * 
     *   return $payments
     *       ->map(fn ($payment) => $payment->debt->original()->balance)
     *       ->sum();
     * 
     * What this function does instead is find the first payment we have for
     * each debt, since payments are a record of the finalized state of a debt
     * as it was locked in at the end of the month.
     * 
     * NOTE: It would be more efficient use the following, to have the db filter
     * our debts down to the first payment for each debt, but that would require
     * a DB::raw() which can make queries db specfic (i.e. doesn't work for
     * sqlite tests, etc).
     * 
     *   $firstPayments = Payment::whereIn('debt_id', $debtIds)
     *       ->whereIn('payment_date', function ($query) use ($debtIds) {
     *           $query->select(DB::raw('MIN(payment_date)'))
     *               ->from('payments')
     *               ->whereIn('debt_id', $debtIds)
     *               ->groupBy('debt_id');
     *       })
     *       ->get();
     * 
     * So, to get the first payment for each of the current payment's debts,
     * without using raw:
     * 
     *   $debtIds = $payments->pluck('debt_id');
     *   $firstPayments = Payment::whereIn('debt_id', $debtIds)
     *       ->strategy()
     *       ->ordered()
     *       ->get()
     *       ->groupBy('debt_id')
     *       ->map(fn ($payment) => $payment->first());
     * 
     * @param \Illuminate\Support\Collection $payments
     */
    private function calculateOriginalBalance(Collection $payments)
    {
        $firstPayment = Payment::programStart()->strategy()->get();

        return $firstPayment
            ->map(fn ($debt) => $debt->starting_balance)
            ->sum();
    }
}
