# PDF Pages to Images

A simple PHP page to extract images from a PDF file, using ImageMagick (Imagick) and [Smalot PdfParser](https://github.com/smalot/pdfparser).

## Features

- Upload a PDF file
- Flexible page selection with three choices:
  - **All** — every page
  - **Range** — from/to page numbers, validated against the PDF's actual page count
  - **Specific** — checkboxes for each page (shown after loading the PDF)
- Two extraction modes:
  - **Convert pages to images** — renders each selected page as a full-page JPG (DPI selectable: 72/96/150/200/300)
  - **Extract images inside pages** — pulls out the original embedded photos/images as JPGs (JPEG/DCTDecode, FlateDecode with PNG predictors, JPEG2000; CMYK and grayscale supported)
- Live progress bars: upload status (bytes uploaded / remaining) and conversion progress (page N of M)
- Results previews with individual download links and a **Download all as ZIP** button

## Requirements

- PHP 8+ with the Imagick extension
- ImageMagick with Ghostscript (for PDF support)
- [Composer](https://getcomposer.org/) to install dependencies

## Setup

1. Clone the repository and install dependencies:

   ```sh
   composer install
   ```

2. Place the project in your web root (e.g. `htdocs/ConvertPDF/`).
3. Open the page in your browser, upload a PDF, pick the pages and mode, and click **Extract Images**.
4. Images are saved to the `extracted/` folder next to `index.php`.

## Configuration

Large PDFs may require raising these limits in your `php.ini`:

```ini
upload_max_filesize = 200M
post_max_size = 200M
max_execution_time = 600
```

Then restart Apache.