<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Services\Statement\DataTransferObjects\Statement;
use App\Services\Statement\StatementService;
use App\Repositories\PdfRepository;
use App\Models\User;

class GenerateStatementPdf implements ShouldQueue
{
    use Queueable;

    private readonly User $user;
    private readonly Statement $statement;

    /**
     * Create a new job instance.
     */
    public function __construct(User $user, Statement $statement)
    {
        $this->user = $user;
        $this->statement = $statement;
        //$this->onQueue('pdf');
    }

    /**
     * Execute the job.
     */
    public function handle(StatementService $statementService): void
    {
        $pdfCache = PdfRepository::forStatements($this->user);

        try {
            $pdf = $statementService->toPdf($this->statement);
            $filename = $this->statement->getBaseFileName() . '.pdf';

            $pdfCache->store($this->statement->getDateId(), $pdf, $filename);
        } catch (\Exception $e) {
            \Log::error('PDF generation failed: ' . $e->getMessage());
            \Log::error($e->getTraceAsString());
            
            throw $e;
        }
    }
}
