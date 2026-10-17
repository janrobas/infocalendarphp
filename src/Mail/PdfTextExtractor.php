<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\Bytes;
use Smalot\PdfParser\Parser;

/**
 * Extracts selectable text from a PDF.
 *
 * Parsing is deliberately on demand: it is the expensive part of reading an
 * attachment (a PDF is inflated and its content streams are interpreted, which
 * can cost far more memory than the file itself). Nothing calls this unless the
 * user explicitly asks for the contents of a specific attachment.
 *
 * Pages are read one at a time and extraction stops as soon as enough text has
 * been collected, so asking for the first paragraph of a long document does not
 * parse the whole document.
 *
 * Scanned/image-only PDFs contain no text objects and legitimately yield an
 * empty string. There is no OCR.
 */
class PdfTextExtractor
{
    public function __construct(private readonly int $maxBytes = 10485760)
    {
    }

    /**
     * @return array{text: string, pages_read: int, pages_total: int, truncated: bool}
     */
    public function extract(string $content, int $maxChars): array
    {
        if ('' === $content) {
            throw new MailException('The attachment is empty.');
        }

        $size = \strlen($content);
        if ($size > $this->maxBytes) {
            throw new MailException(sprintf(
                'The attachment is %s, which exceeds the configured %s limit for reading attachments.',
                Bytes::format($size),
                Bytes::format($this->maxBytes),
            ));
        }

        $limit = max(1, $maxChars);

        try {
            $pages = (new Parser())->parseContent($content)->getPages();
        } catch (\Throwable) {
            throw new MailException('Unable to read the PDF. The file may be encrypted, damaged or not a PDF at all.');
        }

        $text = '';
        $read = 0;

        foreach ($pages as $page) {
            ++$read;

            try {
                $chunk = $page->getText();
            } catch (\Throwable) {
                // A single unreadable page should not fail the whole extraction.
                continue;
            }

            if ('' !== trim($chunk)) {
                $text .= '' === $text ? $chunk : "\n\n".$chunk;
            }

            if (mb_strlen($text) >= $limit) {
                break;
            }
        }

        $text = self::normalize($text);
        $truncated = mb_strlen($text) > $limit;
        if ($truncated) {
            $text = mb_substr($text, 0, $limit).'…';
        }

        return [
            'text' => $text,
            'pages_read' => $read,
            'pages_total' => \count($pages),
            'truncated' => $truncated,
        ];
    }

    /**
     * Collapse the whitespace quirks of PDF text extraction into something a
     * model can read: normal line endings, no trailing spaces, no runs of blank
     * lines.
     */
    private static function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+\n/', "\n", $text) ?? $text;
        $text = preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
