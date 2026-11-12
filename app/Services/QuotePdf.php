<?php

namespace App\Services;

class QuotePdf
{
    /** @param array<string, mixed> $snapshot */
    public function render(array $snapshot): string
    {
        $rows = [];
        $this->add($rows, 'VENDEUR');
        $this->identity($rows, $snapshot['seller']);
        $this->add($rows, '');
        $this->add($rows, 'CLIENT');
        $this->identity($rows, $snapshot['customer']);
        $this->add($rows, '');
        $this->add($rows, 'Conditions de paiement : '.$snapshot['payment_terms']['label']);
        if (($snapshot['payment_terms']['due_date'] ?? null) !== null) {
            $this->add($rows, 'Echeance proposee : '.$snapshot['payment_terms']['due_date']);
        }
        if (($snapshot['payment_terms']['due_days'] ?? null) !== null) {
            $this->add($rows, 'Delai (jours) : '.$snapshot['payment_terms']['due_days']);
        }
        $this->add($rows, 'Montants en MAD TTC. Quantites en conditionnements.');
        $this->add($rows, $snapshot['tax_note']);
        $this->add($rows, '');
        $this->add($rows, str_repeat('-', 85));
        $this->add($rows, 'ARTICLE / ORIGINE                  QUANTITE        PU TTC                TOTAL TTC');
        $this->add($rows, str_repeat('-', 85));
        $intro = $rows;
        $blocks = [];
        foreach ($snapshot['lines'] as $index => $line) {
            $rows = [];
            $this->add($rows, ($index + 1).'. '.$line['sku'].' - '.$line['designation']);
            $this->add($rows, '   Ref. '.$line['original_reference'].' / Unites par conditionnement : '.$line['units_per_package']);
            $this->add($rows, sprintf('%42s %16s %24s', (string) $line['quantity'], $line['unit_price'], $line['line_subtotal']));
            $this->add($rows, '');
            $blocks[] = $rows;
        }
        $rows = [];
        $this->add($rows, str_repeat('-', 85));
        $this->add($rows, 'TOTAL MAD TTC : '.$snapshot['estimated_subtotal']);
        $this->add($rows, 'Validite UTC : '.$snapshot['valid_until']);
        $this->add($rows, 'Devis sans reservation de stock. Accord sur cette version uniquement.');
        $blocks[] = $rows;
        $pages = array_chunk($intro, 43);
        $current = array_pop($pages);
        foreach ($blocks as $block) {
            foreach (array_chunk($block, 39) as $part) {
                if (count($current) + count($part) > 43) {
                    $pages[] = $current;
                    $current = ['ARTICLES / MONTANTS (suite)', str_repeat('-', 85),
                        'ARTICLE / ORIGINE                  QUANTITE        PU TTC                TOTAL TTC', str_repeat('-', 85)];
                }
                $current = array_merge($current, $part);
            }
        }
        $pages[] = $current;
        $objects = ['', '', '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'];
        $kids = [];
        $headingSize = min(17, 485 / max(1, strlen('DEVIS '.$snapshot['number'])));
        foreach ($pages as $index => $page) {
            $pageNumber = count($objects) + 1;
            $streamNumber = $pageNumber + 1;
            $kids[] = $pageNumber.' 0 R';
            $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.$streamNumber.' 0 R >>';
            $commands = "0.07 0.14 0.24 rg\n40 752 515 60 re f\n1 1 1 rg\nBT /F2 {$headingSize} Tf 55 782 Td (DEVIS ".$this->escape($snapshot['number']).") Tj ET\n";
            $commands .= 'BT /F1 9 Tf 55 764 Td (Emission UTC : '.$this->escape($snapshot['issued_at']).") Tj ET\n0.08 0.12 0.19 rg\nBT /F1 10 Tf 40 728 Td\n";
            foreach ($page as $rowIndex => $row) {
                if ($rowIndex > 0) {
                    $commands .= "0 -15 Td\n";
                }
                $commands .= '('.$this->escape($row).") Tj\n";
            }
            $commands .= "ET\n0.4 0.4 0.4 rg\nBT /F1 9 Tf 40 42 Td (Page ".($index + 1).' / '.count($pages).") Tj ET\n";
            $objects[] = '<< /Length '.strlen($commands)." >>\nstream\n{$commands}endstream";
        }
        $objects[0] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[1] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($pages).' >>';
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    /** @param list<string> $rows
     * @param  array<string, string|null>  $identity
     */
    private function identity(array &$rows, array $identity): void
    {
        foreach (['name' => '', 'address' => '', 'customer_code' => 'Code client : ', 'ice' => 'ICE : ', 'tax_identifier' => 'Identifiant fiscal : ', 'rc' => 'RC : '] as $field => $label) {
            if (($identity[$field] ?? null) !== null && $identity[$field] !== '') {
                $this->add($rows, $label.$identity[$field]);
            }
        }
    }

    /** @param list<string> $rows */
    private function add(array &$rows, string $value): void
    {
        $encoded = @iconv('UTF-8', 'Windows-1252', $value);
        abort_unless($encoded !== false && iconv('Windows-1252', 'UTF-8', $encoded) === $value, 422, 'Un caractère documentaire nécessite un moteur PDF Unicode avant émission.');
        $encoded = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $encoded);
        abort_if(preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $encoded) === 1, 422, 'Un caractère de contrôle documentaire est interdit.');
        foreach (explode("\n", $encoded) as $paragraph) {
            foreach (explode("\n", wordwrap($paragraph, 85, "\n", true)) as $row) {
                $rows[] = $row;
            }
        }
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }
}
