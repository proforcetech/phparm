<?php

namespace App\DTO\Dashboard;

class ChartSeries
{
    public string $label;

    /**
     * @var array<int, float>
     */
    public array $data;

    /**
     * @var array<int, string>|null
     */
    public ?array $categories;

    public function __construct(string $label, array $data, ?array $categories = null)
    {
        $this->label = $label;
        $this->data = $data;
        $this->categories = $categories;
    }

    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'data' => $this->data,
            'categories' => $this->categories,
        ];
    }
}
