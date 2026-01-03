<?php

namespace App\Support\Notifications;

class TemplateEngine
{
    public function render(string $template, array $data): string
    {
        $replacements = [];
        foreach ($data as $key => $value) {
            $replacements['{{' . $key . '}}'] = (string) $value;
        }

        return strtr($template, $replacements);
    }
}
