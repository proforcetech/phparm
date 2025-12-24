<?php

namespace App\Services\Inspection;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;
use RuntimeException;

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

        $templates = $this->templates->listDetailed();
        return $templates;
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
     * Update inspection template
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateTemplate(User $user, int $templateId, array $data): array
    {
        if (!$this->gate->can($user, 'inspections.update')) {
            throw new UnauthorizedException('Cannot update inspection templates');
        }

        $template = $this->templates->update($templateId, $data, $user->id);
        if ($template === null) {
            throw new InvalidArgumentException('Inspection template not found');
        }

        return $this->templates->templateWithSections($template->id) ?? $template->toArray();
    }

    /**
     * Delete inspection template
     */
    public function deleteTemplate(User $user, int $templateId): void
    {
        if (!$this->gate->can($user, 'inspections.delete')) {
            throw new UnauthorizedException('Cannot delete inspection templates');
        }

        if (!$this->templates->delete($templateId, $user->id)) {
            throw new InvalidArgumentException('Inspection template not found');
        }
    }

    /**
     * Show template with sections
     *
     * @return array<string, mixed>
     */
    public function showTemplate(User $user, int $templateId): array
    {
        if (!$this->gate->can($user, 'inspections.view')) {
            throw new UnauthorizedException('Cannot view inspection templates');
        }

        $template = $this->templates->templateWithSections($templateId);
        if ($template === null) {
            throw new InvalidArgumentException('Inspection template not found');
        }

        return $template;
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
     * Start a draft inspection report
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function start(User $user, array $data): array
    {
        if (!$this->gate->can($user, 'inspections.update')) {
            throw new UnauthorizedException('Cannot start inspections');
        }

        $report = $this->completion->start($data, $user->id);

        return $report->toArray();
    }

    /**
     * Show inspection report detail
     *
     * @return array<string, mixed>
     */
    public function show(User $user, int $reportId): array
    {
        if (!$this->gate->can($user, 'inspections.view')) {
            throw new UnauthorizedException('Cannot view inspections');
        }

        $report = $this->completion->detail($reportId);
        if ($report === null) {
            throw new InvalidArgumentException('Inspection report not found');
        }

        return $report;
    }

    /**
     * Upload media file for a report
     *
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    public function uploadMedia(User $user, int $reportId, array $file): array
    {
        if (!$this->gate->can($user, 'inspections.update')) {
            throw new UnauthorizedException('Cannot upload inspection media');
        }

        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new InvalidArgumentException('Invalid media upload');
        }

        $mimeType = mime_content_type($file['tmp_name']) ?: 'application/octet-stream';
        $type = str_starts_with($mimeType, 'video') ? 'video' : 'image';
        $extension = strtolower(pathinfo((string) ($file['name'] ?? 'upload'), PATHINFO_EXTENSION));

        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'mp4', 'mov', 'webm'];
        if (!in_array($extension, $allowed, true)) {
            throw new InvalidArgumentException('Unsupported media type');
        }

        $uploadDir = dirname(__DIR__, 3) . '/public/uploads/inspections';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Unable to prepare upload directory');
        }

        $filename = sprintf('inspection_%d_%s.%s', $reportId, uniqid(), $extension);
        $destination = $uploadDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException('Unable to store media');
        }

        $relativePath = '/uploads/inspections/' . $filename;
        $media = $this->completion->attachMedia($reportId, $relativePath, $mimeType, $type, $user->id);

        return $media->toArray();
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
     * Show a single inspection report in customer portal
     *
     * @return array<string, mixed>
     */
    public function customerShow(User $user, int $reportId): array
    {
        if ($user->role !== 'customer' || $user->customer_id === null) {
            throw new UnauthorizedException('Only customers can access this endpoint');
        }

        $report = $this->portal->detailForCustomer($user->customer_id, $reportId);
        if ($report === null) {
            throw new InvalidArgumentException('Inspection not found');
        }

        return $report;
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
        $report = $this->completion->find($reportId);
        if ($report === null) {
            throw new InvalidArgumentException('Inspection report not found');
        }

        return $this->pdfGenerator->generate($report, $settings);
    }
}
