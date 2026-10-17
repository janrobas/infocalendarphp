<?php

declare(strict_types=1);

namespace App\Tests;

use App\Mail\MailException;
use App\Mail\PdfTextExtractor;

final class PdfTest extends TestCase
{
    public function testItExtractsTheTextOfAPdf(): void
    {
        $result = (new PdfTextExtractor())->extract(PdfFixture::create('Hello PDF'), 1000);

        self::assertSame('Hello PDF', $result['text']);
        self::assertSame(1, $result['pages_read']);
        self::assertSame(1, $result['pages_total']);
        self::assertFalse($result['truncated']);
    }

    public function testItJoinsPages(): void
    {
        $result = (new PdfTextExtractor())->extract(PdfFixture::create('First page', 'Second page'), 1000);

        self::assertSame("First page\n\nSecond page", $result['text']);
        self::assertSame(2, $result['pages_read']);
        self::assertSame(2, $result['pages_total']);
    }

    public function testItStopsReadingPagesOnceEnoughTextWasCollected(): void
    {
        $result = (new PdfTextExtractor())->extract(PdfFixture::create('First page', 'Second page'), 5);

        self::assertSame('First…', $result['text']);
        self::assertSame(1, $result['pages_read'], 'the second page must not be parsed');
        self::assertSame(2, $result['pages_total']);
        self::assertTrue($result['truncated']);
    }

    public function testItRejectsEmptyContent(): void
    {
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('The attachment is empty.');

        (new PdfTextExtractor())->extract('', 100);
    }

    public function testItRejectsContentThatIsNotAPdf(): void
    {
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('Unable to read the PDF');

        (new PdfTextExtractor())->extract('this is not a PDF', 100);
    }

    public function testItRejectsAnAttachmentLargerThanTheConfiguredLimit(): void
    {
        $extractor = new PdfTextExtractor(maxBytes: 16);

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('exceeds the configured');

        $extractor->extract(PdfFixture::create('Hello PDF'), 100);
    }
}
