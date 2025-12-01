<?php

namespace App\Services\Dashboard;

use App\DTO\Dashboard\ChartSeries;

class ChartImageRenderer
{
    /**
     * @param array<int, ChartSeries> $series
     */
    public function renderBarChart(array $series, string $title = '', int $width = 900, int $height = 480): string
    {
        if ($series === []) {
            return '';
        }

        $categories = $series[0]->categories ?? [];
        $padding = 60;
        $chartWidth = $width - ($padding * 2);
        $chartHeight = $height - ($padding * 2);
        $image = imagecreatetruecolor($width, $height);

        $white = imagecolorallocate($image, 255, 255, 255);
        $gray = imagecolorallocate($image, 230, 230, 230);
        $axis = imagecolorallocate($image, 80, 80, 80);
        $colors = [
            imagecolorallocate($image, 78, 115, 223),
            imagecolorallocate($image, 28, 200, 138),
            imagecolorallocate($image, 255, 159, 64),
            imagecolorallocate($image, 234, 85, 69),
            imagecolorallocate($image, 54, 185, 204),
        ];

        imagefilledrectangle($image, 0, 0, $width, $height, $white);

        imageline($image, $padding, $padding, $padding, $height - $padding, $axis);
        imageline($image, $padding, $height - $padding, $width - $padding, $height - $padding, $axis);

        $maxValue = 0.0;
        foreach ($series as $item) {
            foreach ($item->data as $value) {
                if ($value > $maxValue) {
                    $maxValue = $value;
                }
            }
        }
        $maxValue = $maxValue > 0 ? $maxValue : 1;

        $categoryCount = max(count($categories), 1);
        $setCount = max(count($series), 1);
        $barWidth = (int) floor(($chartWidth / $categoryCount) / ($setCount + 1));

        foreach ($categories as $index => $category) {
            $xStart = (int) ($padding + ($chartWidth / $categoryCount) * $index);
            foreach ($series as $setIndex => $item) {
                $value = (float) ($item->data[$index] ?? 0);
                $barHeight = (int) ($value / $maxValue * $chartHeight);
                $x1 = $xStart + ($barWidth * $setIndex) + 10;
                $x2 = $x1 + $barWidth - 4;
                $y1 = $height - $padding;
                $y2 = $y1 - $barHeight;
                imagefilledrectangle($image, $x1, $y2, $x2, $y1, $colors[$setIndex % count($colors)]);
            }

            imagestringup($image, 2, $xStart + (int) ($chartWidth / $categoryCount / 2), $height - 5, $category, $axis);
        }

        $step = max(1, (int) floor($maxValue / 5));
        for ($value = 0; $value <= $maxValue; $value += $step) {
            $y = $height - $padding - (int) ($value / $maxValue * $chartHeight);
            imageline($image, $padding - 5, $y, $width - $padding, $y, $gray);
            imagestring($image, 2, 10, $y - 7, (string) $value, $axis);
        }

        if ($title !== '') {
            imagestring($image, 5, (int) ($width / 2) - ((int) strlen($title) * 3), 10, $title, $axis);
        }

        ob_start();
        imagepng($image);
        $data = ob_get_clean();
        imagedestroy($image);

        return $data !== false ? $data : '';
    }
}
