<?php

namespace App\Services\Statement\Contracts;

use App\Services\Statement\DataTransferObjects\Statement;

interface StatementPdfGenerator
{
    /**
     * Generate a PDF for the given statement
     *
     * @param Statement $statement
     * @return string The PDF content as a string
     */
    public function generate(Statement $statement): string;
}
