<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Services;

/**
 * Human-readable HTML of an export: one heading per section, nested tables for lists and records.
 * Every value is escaped; no scripts, no external resources — the file opens offline in any browser.
 */
final class ExportHtmlRenderer
{
    /** @param  array{subject: array{type: string, id: int}, generated_at: string, sections: array<string, mixed>}  $export */
    public function render(array $export): string
    {
        $title = 'Персональні дані: '.$export['subject']['type'].' #'.$export['subject']['id'];
        $body = '';
        foreach ($export['sections'] as $name => $section) {
            $body .= '<h2>'.$this->e((string) $name).'</h2>'.$this->value($section);
        }

        return '<!doctype html><html lang="uk"><head><meta charset="utf-8"><title>'.$this->e($title).'</title>'
            .'<style>body{font:14px/1.4 system-ui,sans-serif;margin:24px;color:#111}table{border-collapse:collapse;margin:4px 0}'
            .'td,th{border:1px solid #ccc;padding:4px 8px;vertical-align:top;text-align:left}th{background:#f4f4f4}</style></head><body>'
            .'<h1>'.$this->e($title).'</h1><p>Сформовано: '.$this->e($export['generated_at']).'</p>'.$body.'</body></html>';
    }

    private function value(mixed $value): string
    {
        if (! is_array($value)) {
            return $this->e(match (true) {
                $value === null => '—',
                is_bool($value) => $value ? 'так' : 'ні',
                is_scalar($value) => (string) $value,
                default => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
            });
        }
        if ($value === []) {
            return '—';
        }
        $rows = '';
        foreach ($value as $key => $item) {
            $rows .= array_is_list($value)
                ? '<tr><td>'.$this->value($item).'</td></tr>'
                : '<tr><th>'.$this->e((string) $key).'</th><td>'.$this->value($item).'</td></tr>';
        }

        return '<table>'.$rows.'</table>';
    }

    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
