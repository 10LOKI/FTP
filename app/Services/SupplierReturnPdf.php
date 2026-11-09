<?php

namespace App\Services;

class SupplierReturnPdf
{
    /** @param iterable<object> $lines */
    public function render(object $return, iterable $lines): string
    {
        $rows = ['RETOUR FOURNISSEUR '.$return->number, 'Achat '.$return->purchase_id, 'Depart UTC '.$return->dispatched_at, ''];
        foreach ($lines as $l) {
            $rows[] = 'Produit '.$l->product_id.' | Qte '.$l->quantity.' | Cout net '.$l->net_unit_cost;
        } $s = "BT\n/F1 11 Tf\n50 790 Td\n";
        foreach ($rows as $i => $row) {
            if ($i) {
                $s .= "0 -18 Td\n";
            }$s .= '('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $row).") Tj\n";
        }$s .= "ET\n";
        $o = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>', '<< /Length '.strlen($s)." >>\nstream\n{$s}endstream", '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
        $p = "%PDF-1.4\n";
        $offs = [0];
        foreach ($o as $i => $x) {
            $offs[] = strlen($p);
            $p .= ($i + 1)." 0 obj\n$x\nendobj\n";
        }$x = strlen($p);
        $p .= "xref\n0 6\n0000000000 65535 f \n";
        foreach (array_slice($offs, 1) as $off) {
            $p .= sprintf("%010d 00000 n \n", $off);
        }

        return $p."trailer << /Size 6 /Root 1 0 R >>\nstartxref\n$x\n%%EOF\n";
    }
}
