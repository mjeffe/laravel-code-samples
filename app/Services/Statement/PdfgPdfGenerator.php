<?php

namespace App\Services\Statement;

use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use App\Services\Statement\Contracts\StatementPdfGenerator;
use App\Services\Statement\DataTransferObjects\Statement;

class PdfgPdfGenerator implements StatementPdfGenerator
{
    /**
     * Generate a PDF for the given statement
     * This is a placeholder until we implement actual PDF generation
     *
     * @param Statement $statement
     * @return string
     */
    public function generate(Statement $statement): string
    {
        $html = $this->renderViewForPdf($statement);

        return $this->getPdf($html);
    }

    protected function renderViewForPdf(Statement $statement): string
    {
        // Prepare image data
        $images = [
            'equity-creator-logo' => $this->getBase64Image('assets/img/equity-creator-logo-horizontal.png'),
            'lamp-logo' => $this->getBase64Image('assets/img/lamp-logo-v2-horizontal.png')
        ];

        $html = View::make('statements.page', [
            'statement' => $statement,
            'isPdfMode' => true,
            'images' => $images
        ])->render();

        return $html;
    }

    protected function getBase64Image(string $path): string
    {
        $imagePath = public_path($path);
        $imageData = file_get_contents($imagePath);
        $type = pathinfo($imagePath, PATHINFO_EXTENSION);
        return 'data:image/' . $type . ';base64,' . base64_encode($imageData);
    }

    private function getPdf(string $html) {
        /*
        --header 'Content-Type: application/json' \     // Http default
        --header "Authorization: Bearer $API_KEY" \     // Http withToken()
        */

        $pdfOptions = [
            'format' => 'Letter',
            'scale' => 0.8,
        ];

        // FIXME: should use sink() to stream response body directly to disk. We
        // will have to do this for Blueprints as they will be very large. But
        // that changes the interface since we would probably have to return the
        // $path instead of the pdf.  Also need to make sure the file path we
        // use will be compatible with the Storage facade's local, or s3, or
        // whatever

        $url = config('app.pdfg.api_url');
        $apiKey = config('app.pdfg.api_key');
        $previewMode = config('app.pdfg.preview_mode');

        $response = Http::withToken($apiKey)
            ->post($url, [
                'preview' => $previewMode,
                'html' => $html,
                'pdfOptions' => $pdfOptions,
            ]);

        if ($response->successful() && $response->header('Content-Type') === 'application/pdf') {
            return $response->body();
        }

        throw new \Exception('Failed to retrieve PDF: ' . $response->body());
    }
}
