<?php

namespace App\Services\Statement;

use App\Services\Statement\Contracts\StatementPdfGenerator;
use App\Services\Statement\DataTransferObjects\Statement;

class StubPdfGenerator implements StatementPdfGenerator
{
    /**
     * Generate a stub PDF for the given statement
     * This is a placeholder until we implement actual PDF generation
     *
     * @param Statement $statement
     * @return string
     */
    public function generate(Statement $statement): string
    {
        // In a real implementation, this would use a PDF library to generate
        // the PDF using views from resources/views/statements/pdf/
        return sprintf(
            'PDF stub for statement %s - This will be replaced with actual PDF generation',
            $statement->statementDate->format('Y-m-d')
        );
    }
}
