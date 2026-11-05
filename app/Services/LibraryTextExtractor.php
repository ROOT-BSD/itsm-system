<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Office\OfficeZip;

/**
 * Витягує простий текст із файлів бібліотеки для пошуку всередині файлів: txt/csv, docx/xlsx/pptx, odt/ods/odp, pdf.
 * PDF читає `pdftotext` (poppler-utils), якщо він встановлений; скан без текстового шару та зображення тексту не мають.
 * Будь-яка помилка → null (файл просто не потрапляє в пошук за вмістом, завантаження не ламається).
 */
final class LibraryTextExtractor
{
    /** Скільки символів тексту зберігаємо з одного файлу. */
    public const MAX_CHARS = 400000;

    public static function extract(string $path, string $originalName): ?string
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        try {
            $text = match ($ext) {
                'txt', 'csv' => self::plain($path),
                'docx' => self::ooxml($path, ['word/document.xml'], 'w:p'),
                'pptx' => self::pptx($path),
                'xlsx' => self::xlsx($path),
                'odt', 'ods', 'odp' => self::odf($path),
                'pdf' => self::pdf($path),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
        if ($text === null) {
            return null;
        }
        $text = trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $text)));
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);
        if ($text === '') {
            return null;
        }
        return mb_strlen($text) > self::MAX_CHARS ? mb_substr($text, 0, self::MAX_CHARS) : $text;
    }

    private static function plain(string $path): ?string
    {
        $raw = (string) file_get_contents($path, false, null, 0, 3 * 1048576);
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1251');
        }
        return $raw;
    }

    /** Текст з XML-частин: кінець абзацу ($par) → новий рядок, самі вузли тексту (w:t / a:t) склеюються. */
    private static function xmlText(string $xml, string $par): string
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            return '';
        }
        $xml = (string) preg_replace('~</' . preg_quote($par, '~') . '>|<w:br\s*/>|<w:tab\s*/>|<text:p\b[^>]*>|</text:p>|<text:tab/>~', "\n", $xml);
        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function ooxml(string $path, array $parts, string $par): ?string
    {
        $zip = OfficeZip::open($path);
        $out = '';
        foreach ($parts as $p) {
            $out .= self::xmlText((string) $zip->read($p), $par) . "\n";
        }
        return $out;
    }

    private static function pptx(string $path): ?string
    {
        $zip = OfficeZip::open($path);
        $out = '';
        for ($i = 1; $i <= 300 && $zip->has("ppt/slides/slide{$i}.xml"); $i++) {
            $out .= self::xmlText((string) $zip->read("ppt/slides/slide{$i}.xml"), 'a:p') . "\n";
        }
        return $out;
    }

    private static function xlsx(string $path): ?string
    {
        $zip = OfficeZip::open($path);
        $out = '';
        $shared = $zip->read('xl/sharedStrings.xml');
        if ($shared !== null) {
            $out .= self::xmlText($shared, 'si') . "\n";
        }
        for ($i = 1; $i <= 50 && $zip->has("xl/worksheets/sheet{$i}.xml"); $i++) {
            $xml = (string) $zip->read("xl/worksheets/sheet{$i}.xml");
            if (preg_match_all('~<is>(.*?)</is>~s', $xml, $m)) { // вбудовані рядки
                $out .= html_entity_decode(strip_tags(implode("\n", $m[1])), ENT_QUOTES | ENT_XML1, 'UTF-8') . "\n";
            }
        }
        return $out;
    }

    private static function odf(string $path): ?string
    {
        return self::xmlText((string) OfficeZip::open($path)->read('content.xml'), 'text:p');
    }

    private static function pdf(string $path): ?string
    {
        $bin = null;
        foreach (['/usr/bin/pdftotext', '/usr/local/bin/pdftotext'] as $c) {
            if (is_executable($c)) {
                $bin = $c;
                break;
            }
        }
        if ($bin === null) {
            return null;
        }
        $proc = proc_open([$bin, '-enc', 'UTF-8', '-q', '-l', '300', $path, '-'], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']], $pipes);
        if (!is_resource($proc)) {
            return null;
        }
        stream_set_blocking($pipes[1], false);
        $out = '';
        $deadline = microtime(true) + 20; // ліміт часу на «важкий» PDF
        while (!feof($pipes[1]) && microtime(true) < $deadline && strlen($out) < 3 * 1048576) {
            $chunk = fread($pipes[1], 65536);
            if ($chunk === false || $chunk === '') {
                usleep(20000);
                continue;
            }
            $out .= $chunk;
        }
        $timedOut = !feof($pipes[1]);
        fclose($pipes[1]);
        if ($timedOut) {
            proc_terminate($proc);
        }
        proc_close($proc);
        return mb_check_encoding($out, 'UTF-8') ? str_replace("\f", "\n", $out) : null;
    }
}
