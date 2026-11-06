<?php

namespace App\Services;

use App\Http\Resources\AuditEventResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

class AuditWorkbook
{
    /** @return array{path: string, count: int} */
    public function create(Builder $query): array
    {
        $directory = Storage::disk('local')->path(config('audit.export_directory'));
        if (file_exists($directory) && ! is_dir($directory)) {
            throw new RuntimeException('Private export storage is unavailable.');
        }
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Private export storage is unavailable.');
        }
        $path = $directory.'/'.Str::uuid().'.xlsx';
        $sheet = tempnam($directory, 'sheet-');
        if ($sheet === false) {
            throw new RuntimeException('Cannot prepare private export storage.');
        }
        $stream = null;
        $count = 0;
        try {
            $stream = fopen($sheet, 'wb');
            if ($stream === false || ! chmod($sheet, 0600)) {
                throw new RuntimeException('Cannot open private export storage.');
            }
            $this->write($stream, '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');
            $headers = ['id', 'schema_version', 'actor_id', 'actor_kind', 'role_context', 'role_context_available', 'request_id', 'action', 'object_type', 'object_id', 'result', 'reason', 'changes', 'context', 'occurred_at'];
            $this->row($stream, $headers);
            foreach ($query->orderBy('occurred_at')->orderBy('id')->lazy(500) as $event) {
                abort_if($count >= 1048575, 413, 'Le fichier dépasse la capacité Excel. Réduisez les filtres.');
                $this->row($stream, array_values((new AuditEventResource($event))->resolve()));
                $count++;
            }
            $this->write($stream, '</sheetData></worksheet>');
            if (! fflush($stream) || ! fclose($stream)) {
                throw new RuntimeException('Cannot complete the export data.');
            }
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Cannot prepare the workbook.');
            }
            $parts = [
                '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
                '_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
                'xl/workbook.xml' => '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Audit" sheetId="1" r:id="rId1"/></sheets></workbook>',
                'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            ];
            foreach ($parts as $name => $data) {
                if (! $zip->addFromString($name, $data)) {
                    throw new RuntimeException('Cannot attach workbook metadata.');
                }
            }
            if (! $zip->addFile($sheet, 'xl/worksheets/sheet1.xml')) {
                throw new RuntimeException('Cannot attach the export data.');
            }
            if (! $zip->close()) {
                throw new RuntimeException('Cannot finish the workbook.');
            }
            chmod($path, 0600);

            return ['path' => $path, 'count' => $count];
        } catch (Throwable $exception) {
            if (is_file($path)) {
                unlink($path);
            }
            throw $exception;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            unlink($sheet);
        }
    }

    /** @param resource $stream */
    private function write($stream, string $text): void
    {
        $offset = 0;
        while ($offset < strlen($text)) {
            $written = fwrite($stream, substr($text, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Cannot write the complete export data.');
            }
            $offset += $written;
        }
    }

    /** @param resource $stream
     * @param  list<mixed>  $values
     */
    private function row($stream, array $values): void
    {
        $this->write($stream, '<row>');
        foreach ($values as $value) {
            $text = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
            $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text);
            $this->write($stream, '<c t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</t></is></c>');
        }
        $this->write($stream, '</row>');
    }
}
