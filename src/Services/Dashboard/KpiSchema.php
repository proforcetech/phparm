<?php

namespace App\Services\Dashboard;

class KpiSchema
{
    public static function kpiPayload(): array
    {
        return [
            'type' => 'object',
            'required' => ['estimates', 'invoices', 'tax', 'warranty', 'appointments', 'inventory'],
            'properties' => [
                'estimates' => ['type' => 'object', 'patternProperties' => ['.*' => ['type' => 'integer']]],
                'invoices' => [
                    'type' => 'object',
                    'properties' => [
                        'total' => ['type' => 'number'],
                        'average' => ['type' => 'number'],
                        'paid' => ['type' => 'number'],
                        'outstanding' => ['type' => 'number'],
                    ],
                ],
                'tax' => ['type' => 'object', 'patternProperties' => ['.*' => ['type' => 'number']]],
                'warranty' => ['type' => 'object', 'patternProperties' => ['.*' => ['type' => 'integer']]],
                'appointments' => ['type' => 'object', 'patternProperties' => ['.*' => ['type' => 'integer']]],
                'inventory' => [
                    'type' => 'object',
                    'properties' => [
                        'low_stock' => ['type' => 'integer'],
                        'out_of_stock' => ['type' => 'integer'],
                    ],
                ],
            ],
        ];
    }

    public static function chartSeries(): array
    {
        return [
            'type' => 'object',
            'required' => ['label', 'data'],
            'properties' => [
                'label' => ['type' => 'string'],
                'data' => ['type' => 'array', 'items' => ['type' => 'number']],
                'categories' => ['type' => ['array', 'null'], 'items' => ['type' => 'string']],
            ],
        ];
    }
}
