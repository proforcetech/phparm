<?php

namespace App\Services\Inspection;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

class InspectionController
{
    private InspectionTemplateService $templates;
    private InspectionCompletionService $completion;
    private InspectionPortalService $portal;
    private AccessGate $gate;
    private ?\App\Support\Pdf\InspectionPdfGenerator $pdfGenerator;

    public function __construct(
        InspectionTemplateService $templates,
        InspectionCompletionService $completion,
        InspectionPortalService $portal,
        AccessGate $gate,
        ?\App\Support\Pdf\InspectionPdfGenerator $pdfGenerator = null
    ) {
        $this->templates = $templates;
        $this->completion = $completion;
        $this->portal = $portal;
        $this->gate = $gate;
        $this->pdfGenerator = $pdfGenerator;
    }

    /**
     * List inspection templates
     *
     * @return array<int, array<string, mixed>>
     */
    public function templates(User $user): array
    {
        if (!$this->gate->can($user, 'inspections.view')) {
            throw new UnauthorizedException('Cannot view inspection templates');
        }

        $templates = $this->templates->listActive();
        return array_map(static fn ($t) => $t->toArray(), $templates);
    }

    /**
     * Create inspection template
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createTemplate(User $user, array $data): array
    {
        if (!$this->gate->can($user, 'inspections.create')) {
            throw new UnauthorizedException('Cannot create inspection templates');
        }

        $template = $this->templates->create($data, $user->id);
        return $template->toArray();
    }

    /**
     * Complete inspection
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function complete(User $user, int $reportId, array $data): array
    {
        if (!$this->gate->can($user, 'inspections.update')) {
            throw new UnauthorizedException('Cannot complete inspections');
        }

        $responses = $data['responses'] ?? [];
        $signature = $data['signature'] ?? null;

        $report = $this->completion->complete($reportId, $responses, $user->id, $signature);

        if ($report === null) {
            throw new InvalidArgumentException('Inspection report not found');
        }

        return $report->toArray();
    }

    /**
     * List inspections for customer portal
     *
     * @return array<int, array<string, mixed>>
     */
    public function customerList(User $user): array
    {
        if ($user->role !== 'customer' || $user->customer_id === null) {
            throw new UnauthorizedException('Only customers can access this endpoint');
        }

        $inspections = $this->portal->listForCustomer($user->customer_id);
        return array_map(static fn ($i) => $i->toArray(), $inspections);
    }

    /**
     * Generate and download inspection PDF
     *
     * @param array<string, mixed> $settings
     */
    public function downloadPdf(User $user, int $reportId, array $settings = []): string
    {
        if (!$this->gate->can($user, 'inspections.view')) {
            throw new UnauthorizedException('Cannot view inspection reports');
        }

        if ($this->pdfGenerator === null) {
            throw new \RuntimeException('PDF generation not available');
        }

        // Fetch the report
        $report = $this->completion->findById($reportId);
        if ($report === null) {
            throw new InvalidArgumentException('Inspection report not found');
        }

        return $this->pdfGenerator->generate($report, $settings);
    }
}
