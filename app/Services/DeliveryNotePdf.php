<?php

namespace App\Services;

class DeliveryNotePdf
{
    /** @param iterable<object> $lines */
    public function render(object $batch, iterable $lines): string
    {
        $text = [
            'BON DE LIVRAISON '.$batch->number,
            'Commande #'.$batch->order_id,
            'Depot '.$batch->warehouse_id,
            'Depart UTC '.$batch->dispatched_at,
            '',
            'SKU | Designation | Quantite livree',
        ];
        foreach ($lines as $line) {
            $text[] = $line->sku.' | '.$line->designation.' | '.$line->prepared_quantity;
        }
        $commands = "BT\n/F1 11 Tf\n50 790 Td\n";
        foreach ($text as $index => $line) {
            if ($index > 0) {
                $commands .= "0 -18 Td\n";
            }
            $commands .= '('.$this->escape($line).") Tj\n";
        }
        $commands .= "ET\n";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            '<< /Length '.strlen($commands)." >>\nstream\n{$commands}endstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $number => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($number + 1)." 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $value);
    }
}
