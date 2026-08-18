# PDF Pages to Images

A simple PHP page to extract pages from a PDF file as JPG images, using ImageMagick (Imagick).

## Features

- Upload a PDF file
- Choose which pages to extract: `all`, a list like `1,3,5`, or a range like `2-5`
- Extracts selected pages at 300 DPI (print quality) as JPG
- Shows previews of the extracted images with download links

## Requirements

- PHP 8+ with the Imagick extension
- ImageMagick with Ghostscript (for PDF support)
- Apache/XAMPP recommended

## Usage

1. Place `index.php` in your web root (e.g. `htdocs/ConvertPDF/index.php`).
2. Open the page in your browser and upload a PDF.
3. Enter the pages to extract and click **Extract Images**.
4. Images are saved to the `extracted/` folder next to `index.php`.

## Configuration

Large PDFs may require raising these limits in your `php.ini`:

```ini
upload_max_filesize = 200M
post_max_size = 200M
```

Then restart Apache.