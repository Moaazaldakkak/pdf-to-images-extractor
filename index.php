<?php
$outputDir = __DIR__ . '/extracted';
$progressDir = sys_get_temp_dir() . '/pdf2img_progress';
$uploadDir = sys_get_temp_dir() . '/pdf2img_uploads';
$files = [];
$errors = [];
$success = false;
$isXhr = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
$hasVendor = file_exists(__DIR__ . '/vendor/autoload.php');

function sanitizeToken(string $token): string
{
    return preg_replace('/[^a-zA-Z0-9_-]/', '', $token);
}

function applyPngPredictor(string $data, int $colors, int $bpc, int $columns): string
{
    if ($bpc !== 8) {
        return '';
    }
    $bytesPerPixel = (int)ceil(($colors * $bpc) / 8);
    $rowLen = (int)ceil($columns * $colors * $bpc / 8);
    $count = strlen($data);
    $out = '';
    $prev = str_repeat("\0", $rowLen);
    $i = 0;
    while ($i < $count) {
        $filter = ord($data[$i]);
        if ($filter > 4) {
            return '';
        }
        $row = substr($data, $i + 1, $rowLen);
        $i += 1 + $rowLen;
        $recon = '';
        switch ($filter) {
            case 0:
                $recon = $row;
                break;
            case 1:
                for ($j = 0; $j < $rowLen; $j++) {
                    $a = $j >= $bytesPerPixel ? ord($recon[$j - $bytesPerPixel]) : 0;
                    $recon .= chr((ord($row[$j]) + $a) & 0xff);
                }
                break;
            case 2:
                for ($j = 0; $j < $rowLen; $j++) {
                    $recon .= chr((ord($row[$j]) + ord($prev[$j])) & 0xff);
                }
                break;
            case 3:
                for ($j = 0; $j < $rowLen; $j++) {
                    $a = $j >= $bytesPerPixel ? ord($recon[$j - $bytesPerPixel]) : 0;
                    $recon .= chr((ord($row[$j]) + (($a + ord($prev[$j])) >> 1)) & 0xff);
                }
                break;
            case 4:
                for ($j = 0; $j < $rowLen; $j++) {
                    $a = $j >= $bytesPerPixel ? ord($recon[$j - $bytesPerPixel]) : 0;
                    $b = ord($prev[$j]);
                    $c = $j >= $bytesPerPixel ? ord($prev[$j - $bytesPerPixel]) : 0;
                    $p = $a + $b - $c;
                    $pa = abs($p - $a);
                    $pb = abs($p - $b);
                    $pc = abs($p - $c);
                    $recon .= chr((ord($row[$j]) + ($pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c))) & 0xff);
                }
                break;
        }
        $out .= $recon;
        $prev = $recon;
    }
    return $out;
}

function pngChunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

function rawToPng(string $raw, int $colors, int $width, int $height): ?string
{
    if ($colors === 4) {
        $rgb = '';
        $n = strlen($raw);
        for ($i = 0; $i + 3 < $n; $i += 4) {
            $c = ord($raw[$i]);
            $m = ord($raw[$i + 1]);
            $y = ord($raw[$i + 2]);
            $k = ord($raw[$i + 3]);
            $rgb .= chr(255 - min(255, $c + $k)) . chr(255 - min(255, $m + $k)) . chr(255 - min(255, $y + $k));
        }
        $raw = $rgb;
        $colors = 3;
    }
    if ($colors !== 1 && $colors !== 3) {
        return null;
    }
    $bpp = $colors;
    $rowLen = $width * $bpp;
    if (strlen($raw) < $rowLen * $height) {
        return null;
    }
    $scanlines = '';
    for ($y = 0; $y < $height; $y++) {
        $scanlines .= "\x00" . substr($raw, $y * $rowLen, $rowLen);
    }
    $colorType = $colors === 1 ? 0 : 2;
    $ihdr = pack('NNCCCCC', $width, $height, 8, $colorType, 0, 0, 0);
    return "\x89PNG\r\n\x1a\n"
        . pngChunk('IHDR', $ihdr)
        . pngChunk('IDAT', gzcompress($scanlines, 6))
        . pngChunk('IEND', '');
}

function extractEmbeddedImages(string $pdfPath, array $pages, string $outputDir, array &$files, int $totalPages, string $token, int &$skipped): int
{
    $parser = new \Smalot\PdfParser\Parser();
    $pdf = $parser->parseFile($pdfPath);
    $allPages = $pdf->getPages();
    if (count($allPages) !== $totalPages) {
        $totalPages = count($allPages);
    }
    $seen = [];
    $stamp = time();
    foreach ($pages as $pi => $pageNo) {
        $page = $allPages[$pageNo - 1];
        $xobjs = $page->getXObjects();
        $seq = 0;
        foreach ($xobjs as $obj) {
            if (!$obj instanceof \Smalot\PdfParser\XObject\Image) {
                continue;
            }
            $det = $obj->getDetails();
            if (($det['Subtype'] ?? '') !== 'Image') {
                continue;
            }
            $data = $obj->getContent();
            if ($data === null || $data === '') {
                continue;
            }
            $hash = md5($data);
            if (isset($seen[$hash])) {
                continue;
            }
            $seen[$hash] = true;
            $seq++;

            try {
                $width = (int)($det['Width'] ?? 0);
                $height = (int)($det['Height'] ?? 0);
                $filter = is_array($det['Filter'] ?? null) ? ($det['Filter'][0] ?? '') : (string)($det['Filter'] ?? '');
                $filter = is_array($filter) ? (string)($filter[0] ?? '') : (string)$filter;
                $colorspace = is_array($det['ColorSpace'] ?? null) ? (string)($det['ColorSpace'][0] ?? $det['ColorSpace'][1] ?? '') : (string)($det['ColorSpace'] ?? '');
                $colorspaceFull = is_array($det['ColorSpace'] ?? null) ? $det['ColorSpace'] : (string)($det['ColorSpace'] ?? '');
                $decparms = $det['DecodeParms'] ?? null;
                if (is_array($decparms) && isset($decparms['Colors'])) {
                    $colors = (int)$decparms['Colors'];
                } elseif (is_array($colorspaceFull) && ($colorspaceFull[0] ?? '') === 'ICCBased' && isset($colorspaceFull[2])) {
                    $colors = (int)$colorspaceFull[2];
                } else {
                    $colors = in_array($colorspace, ['DeviceGray', 'G'], true) ? 1 : (in_array($colorspace, ['DeviceCMYK', 'CMYK'], true) ? 4 : 3);
                }
                $bpc = is_array($decparms) && isset($decparms['BitsPerComponent']) ? (int)$decparms['BitsPerComponent'] : (int)($det['BitsPerComponent'] ?? 8);
                $columns = is_array($decparms) && isset($decparms['Columns']) ? (int)$decparms['Columns'] : $width;
                $predictor = is_array($decparms) && isset($decparms['Predictor']) ? (int)$decparms['Predictor'] : 1;

                $im = null;
                $normalizeCmyk = false;

                if (in_array($colorspace, ['Indexed', 'DeviceN', 'Separation'], true) || (is_array($colorspaceFull) && $colorspaceFull[0] === 'Indexed')) {
                    $skipped++;
                    continue;
                }

                if ($filter === 'DCTDecode') {
                    $im = new Imagick();
                    $im->readImageBlob($data);
                    $normalizeCmyk = strpos($colorspace, 'CMYK') !== false;
                } elseif ($filter === 'FlateDecode') {
                    $raw = $data;
                    if ($predictor >= 10) {
                        if ($bpc === 8) {
                            $raw = applyPngPredictor($raw, $colors, $bpc, $columns);
                        } else {
                            $raw = '';
                        }
                    }
                    if ($raw === '' || $width <= 0 || $height <= 0 || $colors < 1 || $colors > 4) {
                        $raw = '';
                    }
                    if ($raw !== '') {
                        $png = rawToPng($raw, $colors, $columns ?: $width, $height);
                        if ($png !== null) {
                            $im = new Imagick();
                            $im->readImageBlob($png);
                        }
                    }
                } elseif ($filter === 'JPXDecode') {
                    $im = new Imagick();
                    $im->readImageBlob($data);
                }

                if ($im === null) {
                    try {
                        $im = new Imagick();
                        $im->readImageBlob($data);
                    } catch (Exception $e) {
                        $im = null;
                    }
                }

                if ($im === null) {
                    $skipped++;
                    continue;
                }

                if ($normalizeCmyk) {
                    $im->transformImageColorspace(Imagick::COLORSPACE_SRGB);
                }
                $im->setImageFormat('jpg');
                $im->setImageCompressionQuality(90);
                $name = 'img_p' . $pageNo . '_' . $seq . '_' . $stamp . '.jpg';
                $im->writeImage($outputDir . '/' . $name);
                $im->clear();
                $im->destroy();
                $files[] = $name;
            } catch (Exception $e) {
                $skipped++;
                continue;
            }
        }
        writeProgress($token, ['phase' => 'converting', 'done' => $pi + 1, 'total' => count($pages), 'message' => 'Scanning page ' . $pageNo . ' of ' . $totalPages . ' for images...']);
    }
    return count($seen);
}

function writeProgress(string $token, array $data): void
{
    global $progressDir;
    if ($token === '') {
        return;
    }
    $token = sanitizeToken($token);
    if ($token === '') {
        return;
    }
    if (!is_dir($progressDir)) {
        @mkdir($progressDir, 0777, true);
    }
    $data['ts'] = time();
    @file_put_contents($progressDir . '/' . $token . '.json', json_encode($data), LOCK_EX);
}

function clearProgress(string $token): void
{
    global $progressDir;
    $token = sanitizeToken($token);
    if ($token !== '') {
        @unlink($progressDir . '/' . $token . '.json');
    }
}

function sweepUploads(string $dir, int $maxAgeSec = 21600): void
{
    foreach (glob($dir . '/*.pdf') ?: [] as $f) {
        if (time() - filemtime($f) > $maxAgeSec) {
            @unlink($f);
        }
    }
}

function sweepOld(string $dir, string $ext, int $maxAgeSec = 21600): void
{
    foreach (glob($dir . '/*.' . $ext) ?: [] as $f) {
        if (time() - filemtime($f) > $maxAgeSec) {
            @unlink($f);
        }
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'zip') {
    $names = array_filter(array_map('basename', explode(',', $_GET['files'] ?? '')));
    $zipPath = tempnam(sys_get_temp_dir(), 'pdf2img_') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        exit('Could not create ZIP archive.');
    }
    $added = 0;
    foreach ($names as $name) {
        $src = $outputDir . '/' . $name;
        if ($name !== '' && is_file($src)) {
            $zip->addFile($src, $name);
            $added++;
        }
    }
    $zip->close();
    if ($added === 0) {
        @unlink($zipPath);
        http_response_code(404);
        exit('No images found to download.');
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="pdf-images.zip"');
    header('Content-Length: ' . filesize($zipPath));
    readfile($zipPath);
    @unlink($zipPath);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'progress') {
    header('Content-Type: application/json');
    $token = sanitizeToken($_GET['token'] ?? '');
    if ($token === '') {
        echo json_encode(['phase' => 'none']);
        exit;
    }
    $raw = @file_get_contents($progressDir . '/' . $token . '.json');
    if ($raw === false) {
        echo json_encode(['phase' => 'none']);
    } else {
        echo $raw;
    }
    exit;
}

if (($_POST['ajax'] ?? '') === 'upload') {
    header('Content-Type: application/json');
    $token = sanitizeToken($_POST['token'] ?? '');
    if ($token === '' || !isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'There was a problem receiving the PDF.']);
        exit;
    }
    $file = $_FILES['pdf'];
    if (pathinfo($file['name'], PATHINFO_EXTENSION) !== 'pdf' || mime_content_type($file['tmp_name']) !== 'application/pdf') {
        echo json_encode(['ok' => false, 'error' => 'The uploaded file must be a PDF.']);
        exit;
    }
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }
    sweepUploads($uploadDir);
    sweepOld($progressDir, 'json');
    $dest = $uploadDir . '/' . $token . '.pdf';
    @unlink($dest);
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        echo json_encode(['ok' => false, 'error' => 'Could not store the uploaded PDF.']);
        exit;
    }
    try {
        $count = new Imagick();
        $count->pingImage($dest);
        $total = $count->getNumberImages();
        $count->clear();
        $count->destroy();
        if ($total <= 0) {
            throw new Exception('Could not read the PDF.');
        }
        echo json_encode(['ok' => true, 'total' => $total, 'name' => $file['name']]);
    } catch (Exception $e) {
        @unlink($dest);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = sanitizeToken($_POST['token'] ?? '');
    try {
        $useCache = !empty($_POST['useCache']);
        $cached = $uploadDir . '/' . $token . '.pdf';
        if ($useCache) {
            if ($token === '' || !is_file($cached)) {
                throw new Exception('The uploaded PDF expired. Please upload the file and load the pages again.');
            }
            $pdfPath = $cached;
        } else {
            if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Please choose a PDF file to upload.');
            }
            $file = $_FILES['pdf'];
            if (pathinfo($file['name'], PATHINFO_EXTENSION) !== 'pdf' || mime_content_type($file['tmp_name']) !== 'application/pdf') {
                throw new Exception('The uploaded file must be a PDF.');
            }
            $pdfPath = $file['tmp_name'];
        }
        $mode = $_POST['mode'] ?? 'convert';
        if (!in_array($mode, ['convert', 'extract'], true)) {
            $mode = 'convert';
        }
        if ($mode === 'extract' && !$hasVendor) {
            throw new Exception('Missing dependencies. Run "composer install" in the project directory.');
        }
        writeProgress($token, ['phase' => 'reading', 'done' => 0, 'total' => 0, 'message' => 'Reading PDF...']);

        $count = new Imagick();
        $count->pingImage($pdfPath);
        $totalPages = $count->getNumberImages();
        $count->clear();
        $count->destroy();

        if ($totalPages <= 0) {
            throw new Exception('Could not read the PDF.');
        }

        $pageMode = $_POST['pageMode'] ?? 'all';
        switch ($pageMode) {
            case 'range':
                $from = max(1, min($totalPages, (int)($_POST['rangeFrom'] ?? 1)));
                $to = max(1, min($totalPages, (int)($_POST['rangeTo'] ?? $totalPages)));
                if ($from > $to) {
                    throw new Exception('Invalid range: the start page must not be after the end page.');
                }
                $pages = range($from, $to);
                break;
            case 'specific':
                $pages = [];
                foreach (explode(',', $_POST['specificPages'] ?? '') as $p) {
                    $n = (int)trim($p);
                    if ($n >= 1 && $n <= $totalPages) {
                        $pages[] = $n;
                    }
                }
                $pages = array_values(array_unique($pages));
                break;
            default:
                $pages = range(1, $totalPages);
        }

        if (empty($pages)) {
            throw new Exception("No valid pages selected. The PDF has {$totalPages} page(s).");
        }

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        set_time_limit(0);

        $dpi = (int)($_POST['dpi'] ?? 150);
        if (!in_array($dpi, [72, 96, 150, 200, 300], true)) {
            $dpi = 150;
        }
        if ($mode === 'extract') {
            require_once __DIR__ . '/vendor/autoload.php';
            writeProgress($token, ['phase' => 'converting', 'done' => 0, 'total' => count($pages), 'message' => 'Scanning pages for embedded images...']);
            $skipped = 0;
            extractEmbeddedImages($pdfPath, $pages, $outputDir, $files, $totalPages, $token, $skipped);
            if (empty($files)) {
                throw new Exception($skipped > 0 ? 'Found ' . $skipped . ' embedded image(s) but none could be extracted (unsupported filter or color space).' : 'No embedded images found on the selected pages.');
            }
            $success = true;
            $doneMsg = 'Extracted ' . count($files) . ' image(s) from ' . count($pages) . ' page(s).';
            if ($skipped > 0) {
                $doneMsg .= ' ' . $skipped . ' image(s) skipped (unsupported format or duplicate).';
            }
            writeProgress($token, ['phase' => 'done', 'done' => count($pages), 'total' => count($pages), 'message' => $doneMsg, 'files' => $files]);
        } else {
            writeProgress($token, ['phase' => 'converting', 'done' => 0, 'total' => count($pages), 'message' => 'Converting...']);

            foreach ($pages as $i => $page) {
                $imagick = new Imagick();
                $imagick->setResolution($dpi, $dpi);
                $imagick->readImage($pdfPath . '[' . ($page - 1) . ']');
                $imagick->setImageFormat('jpg');
                $imagick->setImageCompressionQuality(90);
                $name = 'page_' . $page . '_' . time() . '.jpg';
                $imagick->writeImage($outputDir . '/' . $name);
                $imagick->clear();
                $imagick->destroy();
                $files[] = $name;
                writeProgress($token, ['phase' => 'converting', 'done' => $i + 1, 'total' => count($pages), 'message' => 'Converting page ' . $page . ' of ' . $totalPages . '...']);
            }

            $success = true;
            writeProgress($token, ['phase' => 'done', 'done' => count($pages), 'total' => count($pages), 'message' => 'Done.', 'files' => $files]);
        }
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
        writeProgress($token, ['phase' => 'error', 'message' => $e->getMessage()]);
    }

    if ($isXhr) {
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => $success,
            'files' => $files,
            'error' => $success ? null : implode('<br>', $errors),
        ]);
        exit;
    }

    clearProgress($token);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PDF to Images</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: "Segoe UI", Arial, sans-serif;
            background: #f0f2f5;
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 100vh;
            padding: 60px 20px 0;
        }
        .card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            padding: 40px;
            width: 100%;
            max-width: 600px;
            margin-bottom: 24px;
        }
        h1 { font-size: 24px; color: #1a1a2e; margin-bottom: 6px; }
        p.sub { color: #6b7280; font-size: 14px; margin-bottom: 28px; }
        label.block { display: block; font-weight: 600; font-size: 14px; color: #374151; margin-bottom: 8px; }
        input[type="file"] {
            width: 100%;
            padding: 14px;
            border: 2px dashed #cbd5e1;
            border-radius: 8px;
            background: #f9fafb;
            cursor: pointer;
            margin-bottom: 20px;
        }
        button {
            width: 100%;
            padding: 14px;
            background: #4f46e5;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        button:hover { background: #4338ca; }
        button:disabled { background: #a5b4fc; cursor: not-allowed; }
        .mini-btn {
            display: inline-block;
            width: auto;
            padding: 8px 14px;
            background: #4f46e5;
            font-size: 13px;
            font-weight: 600;
            border-radius: 6px;
        }
        .mini-btn:hover { background: #4338ca; }
        .mini-btn.ghost { background: #fff; color: #4f46e5; border: 1px solid #c7d2fe; }
        .mini-btn.ghost:hover { background: #eef2ff; }
        .alert { border-radius: 8px; padding: 14px; margin-bottom: 20px; font-size: 14px; }
        .alert.error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .alert.success { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
        .results { margin-top: 24px; display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; }
        .results .zip-btn {
            grid-column: 1 / -1;
            display: block;
            text-align: center;
            padding: 12px;
            background: #16a34a;
            color: #fff;
            border-radius: 8px;
            font-weight: 600;
            text-decoration: none;
            transition: background 0.2s;
        }
        .results .zip-btn:hover { background: #15803d; }
        .results img { width: 100%; border-radius: 6px; border: 1px solid #e5e7eb; display: block; }
        .results a { font-size: 12px; color: #4f46e5; text-decoration: none; display: block; margin-top: 6px; }
        .results a:hover { text-decoration: underline; }
        .progress-label { display: flex; justify-content: space-between; font-size: 13px; color: #374151; margin-bottom: 6px; }
        .progress-label .pct { font-weight: 700; color: #4f46e5; }
        .bar-track { height: 12px; background: #e5e7eb; border-radius: 99px; overflow: hidden; }
        .bar-fill {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, #4f46e5, #7c3aed);
            border-radius: 99px;
            transition: width 0.3s ease;
        }
        .bar-fill.indeterminate {
            width: 100%;
            animation: pulse 1.2s ease-in-out infinite;
        }
        @keyframes pulse { 0%, 100% { opacity: 0.35; } 50% { opacity: 1; } }
        .progress-block { display: none; margin: 18px 0; }
        .progress-block.visible { display: block; }
        .progress-sub { font-size: 12px; color: #9ca3af; margin-top: 4px; text-align: right; }
        .section { margin-bottom: 24px; }
        .mode-group { display: flex; gap: 8px; margin-bottom: 12px; }
        .mode-group input { accent-color: #4f46e5; }
        .mode-card {
            flex: 1;
            padding: 10px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            color: #374151;
            text-align: center;
            transition: border-color 0.2s, background 0.2s;
        }
        .mode-card:hover { border-color: #4f46e5; }
        .mode-card.checked { border-color: #4f46e5; background: #eef2ff; }
        .mode-detail { display: none; padding: 14px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; margin-top: 10px; }
        .mode-detail.visible { display: block; }
        .mode-detail input[type="number"] {
            width: 90px;
            padding: 8px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 14px;
        }
        select {
            padding: 8px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 14px;
            background: #fff;
        }
        .detail-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 10px; font-size: 14px; color: #374151; }
        .loaded-badge { display: none; margin-top: 10px; padding: 8px 12px; background: #eef2ff; color: #4338ca; border-radius: 6px; font-size: 13px; font-weight: 600; }
        .loaded-badge.visible { display: block; }
        .page-grid { margin-top: 10px; max-height: 260px; overflow-y: auto; display: grid; grid-template-columns: repeat(auto-fill, minmax(64px, 1fr)); gap: 6px; }
        .page-grid label {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            padding: 7px 4px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            background: #fff;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            color: #374151;
        }
        .page-grid label.checked { border-color: #4f46e5; background: #eef2ff; color: #4338ca; }
        .page-grid input { accent-color: #4f46e5; }
        .grid-tools { display: none; margin-top: 8px; gap: 8px; }
        .grid-tools.visible { display: flex; }
        .page-hint { font-size: 13px; color: #6b7280; }
        .hidden { display: none !important; }
        .form-fields.busy { opacity: 0.55; pointer-events: none; transition: opacity 0.2s; }
        .footer-bar {
            margin-top: auto;
            margin-left: -20px;
            margin-right: -20px;
            width: calc(100% + 40px);
            padding: 24px 24px 16px;
            border-top: 1px solid #e5e7eb;
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
            font-size: 13px;
            color: #6b7280;
            border-radius: 0;
        }
        .footer-bar .links { display: flex; align-items: center; gap: 12px; }
        .footer-bar a { color: #6b7280; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; transition: color 0.2s; }
        .footer-bar a:hover { color: #4f46e5; }
        .footer-bar svg { width: 18px; height: 18px; fill: currentColor; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Extract PDF Pages as Images</h1>
        <p class="sub">Upload a PDF, choose the pages you want, and convert them to JPG images.</p>

        <?php if ($errors): ?>
            <div class="alert error"><?php echo htmlspecialchars(implode('<br>', $errors)); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert success">Extracted <?php echo count($files); ?> page(s) successfully.</div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" id="upload-form">
            <input type="hidden" name="token" id="token-input" value="">
            <input type="hidden" name="useCache" id="use-cache-input" value="">
            <input type="hidden" name="specificPages" id="specific-pages-input" value="">

            <div id="form-fields" class="form-fields">
            <div class="section">
                <label class="block" for="pdf">PDF file</label>
                <input type="file" name="pdf" id="pdf" accept="application/pdf" required>
            </div>

            <div class="section">
                <label class="block">Pages to extract</label>
                <div class="mode-group">
                    <label class="mode-card" id="page-card-all">
                        <input type="radio" name="pageMode" value="all" checked> All
                    </label>
                    <label class="mode-card" id="page-card-range">
                        <input type="radio" name="pageMode" value="range"> Range
                    </label>
                    <label class="mode-card" id="page-card-specific">
                        <input type="radio" name="pageMode" value="specific"> Specific
                    </label>
                </div>

                <div class="mode-detail" id="detail-all">
                    <span class="page-hint">All pages of the uploaded PDF will be processed.</span>
                </div>

                <div class="mode-detail" id="detail-range">
                    <div class="detail-row">
                        From <input type="number" name="rangeFrom" id="range-from" min="1" value="1">
                        to <input type="number" name="rangeTo" id="range-to" min="1" value="">
                        <button type="button" class="mini-btn" id="load-btn-range">Load pages from PDF</button>
                    </div>
                    <div class="loaded-badge" id="range-loaded"></div>
                </div>

                <div class="mode-detail" id="detail-specific">
                    <div class="detail-row">
                        <button type="button" class="mini-btn" id="load-btn-specific">Load pages from PDF</button>
                    </div>
                    <div class="loaded-badge" id="specific-loaded"></div>
                    <div class="grid-tools" id="grid-tools">
                        <button type="button" class="mini-btn ghost" id="select-all-btn">Select all</button>
                        <button type="button" class="mini-btn ghost" id="clear-all-btn">Clear</button>
                    </div>
                    <div class="page-grid" id="page-grid"></div>
                </div>
            </div>

            <div class="section">
                <label class="block">Extraction mode</label>
                <div class="mode-group">
                    <label class="mode-card" id="mode-label-convert">
                        <input type="radio" name="mode" value="convert" id="mode-convert" checked> Page images
                    </label>
                    <label class="mode-card" id="mode-label-extract">
                        <input type="radio" name="mode" value="extract" id="mode-extract"> Embedded images
                    </label>
                </div>
                <div class="mode-detail visible" id="dpi-row">
                    <div class="detail-row">
                        Resolution:
                        <select id="dpi-select" name="dpi">
                            <option value="72" <?php echo ($_POST['dpi'] ?? 150) == 72 ? 'selected' : ''; ?>>72 DPI (screen)</option>
                            <option value="96" <?php echo ($_POST['dpi'] ?? 150) == 96 ? 'selected' : ''; ?>>96 DPI (web)</option>
                            <option value="150" <?php echo ($_POST['dpi'] ?? 150) == 150 ? 'selected' : ''; ?>>150 DPI (good)</option>
                            <option value="200" <?php echo ($_POST['dpi'] ?? 150) == 200 ? 'selected' : ''; ?>>200 DPI (high)</option>
                            <option value="300" <?php echo ($_POST['dpi'] ?? 150) == 300 ? 'selected' : ''; ?>>300 DPI (print)</option>
                        </select>
                        <span class="page-hint" id="dpi-hint"></span>
                    </div>
                </div>
            </div>
            </div>

            <div class="progress-block" id="upload-progress">
                <div class="progress-label">
                    <span id="upload-label">Uploading...</span>
                    <span class="pct" id="upload-pct">0%</span>
                </div>
                <div class="bar-track"><div class="bar-fill" id="upload-bar"></div></div>
                <div class="progress-sub" id="upload-sub"></div>
            </div>

            <div class="progress-block" id="convert-progress">
                <div class="progress-label">
                    <span id="convert-label">Converting...</span>
                    <span class="pct" id="convert-pct">0%</span>
                </div>
                <div class="bar-track"><div class="bar-fill" id="convert-bar"></div></div>
                <div class="progress-sub" id="convert-sub"></div>
            </div>

            <button type="submit" id="submit-btn">Extract Images</button>
        </form>

        <div id="js-error" class="alert error" style="display:none;"></div>

        <div class="results" id="results"></div>
    </div>

    <footer class="footer-bar">
            <span>Developed by <strong>Muaz Aldakkak</strong></span>
            <div class="links">
                <a href="https://www.linkedin.com/in/moaaz-aldakkak/" target="_blank" rel="noopener" title="LinkedIn">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h14m-.5 15.5v-5.3a3.26 3.26 0 0 0-3.26-3.26c-.85 0-1.84.52-2.32 1.3v-1.11h-2.79v8.37h2.79v-4.93c0-.77.62-1.4 1.39-1.4a1.4 1.4 0 0 1 1.4 1.4v4.93h2.79M6.88 8.56a1.68 1.68 0 0 0 1.68-1.68c0-.93-.75-1.69-1.68-1.69a1.69 1.69 0 0 0-1.69 1.69c0 .93.76 1.68 1.69 1.68m1.39 9.94v-8.37H5.5v8.37h2.77z"/></svg>
                    <span>LinkedIn</span>
                </a>
                <a href="https://github.com/Moaazaldakkak" target="_blank" rel="noopener" title="GitHub">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 0C5.37 0 0 5.37 0 12c0 5.3 3.44 9.8 8.21 11.39.6.11.82-.26.82-.58v-2.03c-3.34.72-4.04-1.61-4.04-1.61-.55-1.39-1.34-1.76-1.34-1.76-1.09-.74.08-.73.08-.73 1.21.09 1.84 1.24 1.84 1.24 1.07 1.83 2.81 1.3 3.5 1 .11-.78.42-1.31.76-1.61-2.67-.3-5.47-1.33-5.47-5.93 0-1.31.47-2.38 1.24-3.22-.13-.3-.54-1.52.11-3.18 0 0 1.01-.32 3.3 1.23a11.5 11.5 0 0 1 6.01 0c2.29-1.55 3.3-1.23 3.3-1.23.65 1.66.24 2.88.12 3.18.77.84 1.23 1.91 1.23 3.22 0 4.61-2.8 5.62-5.48 5.92.43.37.81 1.1.81 2.22v3.29c0 .32.22.7.83.58C20.57 21.79 24 17.3 24 12c0-6.63-5.37-12-12-12z"/></svg>
                    <span>GitHub</span>
                </a>
                <a href="mailto:muazaldakkak@gmail.com" title="muazaldakkak@gmail.com">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4-8 5-8-5V6l8 5 8-5v2z"/></svg>
                    <span>Email</span>
                </a>
            </div>
        </footer>
    </div>

    <script>
    (function () {
        var form = document.getElementById('upload-form');
        var btn = document.getElementById('submit-btn');
        var tokenInput = document.getElementById('token-input');
        var useCacheInput = document.getElementById('use-cache-input');
        var specificPagesInput = document.getElementById('specific-pages-input');
        var errBox = document.getElementById('js-error');
        var resultsBox = document.getElementById('results');
        var fileInput = document.getElementById('pdf');

        var state = { loaded: false, total: 0, loadedName: '' };

        function fmt(bytes) {
            if (bytes < 1024) return bytes + ' B';
            var units = ['KB', 'MB', 'GB'];
            var i = -1;
            do { bytes /= 1024; i++; } while (bytes >= 1024 && i < units.length - 1);
            return bytes.toFixed(1) + ' ' + units[i];
        }

        function showBlock(id, on) {
            document.getElementById(id).classList.toggle('visible', on);
        }

        function setBar(id, pct, indeterminate) {
            var bar = document.getElementById(id);
            bar.style.width = (indeterminate ? 100 : Math.min(100, pct)) + '%';
            bar.classList.toggle('indeterminate', !!indeterminate);
        }

        function showError(msg) {
            errBox.innerHTML = msg;
            errBox.style.display = 'block';
        }

        function hideError() {
            errBox.style.display = 'none';
        }

        function setBusy(on) {
            document.getElementById('form-fields').classList.toggle('busy', on);
            btn.disabled = on;
            btn.textContent = on ? 'Working...' : 'Extract Images';
        }

        function notifyUser(body) {
            try {
                if (('Notification' in window) && Notification.permission === 'granted') {
                    new Notification('PDF to Images', { body: body });
                }
            } catch (e) {}
            var old = document.title;
            document.title = body + ' — PDF to Images';
            setTimeout(function () { document.title = old; }, 8000);
        }

        function cardRadio(name, cardId, checkedId) {
            var checked = document.getElementById(checkedId);
            document.getElementById(cardId).classList.toggle('checked', checked.checked);
            return checked.checked;
        }

        function getPageMode() {
            var r = document.querySelector('input[name="pageMode"]:checked');
            return r ? r.value : 'all';
        }

        function syncPageMode() {
            var mode = getPageMode();
            document.getElementById('page-card-all').classList.toggle('checked', mode === 'all');
            document.getElementById('page-card-range').classList.toggle('checked', mode === 'range');
            document.getElementById('page-card-specific').classList.toggle('checked', mode === 'specific');
            document.getElementById('detail-all').classList.toggle('visible', mode === 'all');
            document.getElementById('detail-range').classList.toggle('visible', mode === 'range');
            document.getElementById('detail-specific').classList.toggle('visible', mode === 'specific');
        }

        document.querySelectorAll('input[name="pageMode"]').forEach(function (el) {
            el.addEventListener('change', syncPageMode);
        });
        syncPageMode();

        document.getElementById('mode-convert').addEventListener('change', function () {
            document.getElementById('mode-label-convert').classList.toggle('checked', this.checked);
            syncDpiRow();
        });
        document.getElementById('mode-extract').addEventListener('change', function () {
            document.getElementById('mode-label-extract').classList.toggle('checked', this.checked);
            syncDpiRow();
        });
        document.getElementById('mode-convert').checked = true;
        document.getElementById('mode-label-convert').classList.add('checked');

        document.getElementById('dpi-select').addEventListener('change', function () {
            var hint = document.getElementById('dpi-hint');
            hint.textContent = (this.value === '300') ? 'Slow — takes ~4x longer.' : '';
        });

        function syncDpiRow() {
            document.getElementById('dpi-row').classList.toggle('visible', document.getElementById('mode-convert').checked);
        }
        syncDpiRow();

        function rotateToken() {
            tokenInput.value = (crypto.randomUUID ? crypto.randomUUID() : 't' + Date.now() + Math.random().toString(16).slice(2));
        }

        fileInput.addEventListener('change', function () {
            state.loaded = false;
            state.total = 0;
            state.loadedName = '';
            useCacheInput.value = '';
            document.getElementById('range-loaded').classList.remove('visible');
            document.getElementById('specific-loaded').classList.remove('visible');
            document.getElementById('grid-tools').classList.remove('visible');
            document.getElementById('page-grid').innerHTML = '';
            document.getElementById('range-to').value = '';
            document.getElementById('range-from').value = '1';
        });

        function uploadForPageCount(onDone) {
            if (!fileInput.files.length) {
                showError('Please choose a PDF file first.');
                return;
            }
            rotateToken();
            var totalSize = fileInput.files[0].size;
            hideError();
            showBlock('upload-progress', true);
            showBlock('convert-progress', false);
            setBar('upload-bar', 0, false);
            document.getElementById('upload-label').textContent = 'Uploading ' + fileInput.files[0].name + '...';
            document.getElementById('upload-pct').textContent = '0%';
            document.getElementById('upload-sub').textContent = '0 B / ' + fmt(totalSize);

            var fd = new FormData();
            fd.append('ajax', 'upload');
            fd.append('token', tokenInput.value);
            fd.append('pdf', fileInput.files[0]);
            var xhr = new XMLHttpRequest();

            xhr.upload.addEventListener('progress', function (ev) {
                if (!ev.lengthComputable) return;
                var pct = Math.round((ev.loaded / ev.total) * 100);
                setBar('upload-bar', pct, false);
                document.getElementById('upload-pct').textContent = pct + '%';
                document.getElementById('upload-sub').textContent =
                    fmt(ev.loaded) + ' / ' + fmt(totalSize) + ' (' + fmt(Math.max(0, totalSize - ev.loaded)) + ' remaining)';
            });

            xhr.addEventListener('load', function () {
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.ok) {
                        onDone(res);
                    } else {
                        showBlock('upload-progress', false);
                        showError(res.error || 'Could not read the PDF.');
                    }
                } catch (ex) {
                    showBlock('upload-progress', false);
                    showError('Unexpected server response.');
                }
            });

            xhr.addEventListener('error', function () {
                showBlock('upload-progress', false);
                showError('Network error while uploading.');
            });

            xhr.open('POST', '', true);
            xhr.send(fd);
        }

        function buildPageGrid(total) {
            var grid = document.getElementById('page-grid');
            grid.innerHTML = '';
            for (var i = 1; i <= total; i++) {
                var label = document.createElement('label');
                label.textContent = i;
                var cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.checked = true;
                cb.addEventListener('change', function () {
                    this.parentNode.classList.toggle('checked', this.checked);
                });
                label.appendChild(cb);
                label.classList.add('checked');
                grid.appendChild(label);
            }
        }

        document.getElementById('load-btn-range').addEventListener('click', function () {
            uploadForPageCount(function (res) {
                showBlock('upload-progress', false);
                state.loaded = true;
                state.total = res.total;
                state.loadedName = res.name;
                useCacheInput.value = '1';
                var from = document.getElementById('range-from');
                var to = document.getElementById('range-to');
                from.value = 1;
                from.max = res.total;
                to.value = res.total;
                to.max = res.total;
                to.min = 1;
                var badge = document.getElementById('range-loaded');
                badge.textContent = 'PDF loaded — ' + res.total + ' pages. Valid range: 1 to ' + res.total + '.';
                badge.classList.add('visible');
            });
        });

        document.getElementById('load-btn-specific').addEventListener('click', function () {
            uploadForPageCount(function (res) {
                showBlock('upload-progress', false);
                state.loaded = true;
                state.total = res.total;
                state.loadedName = res.name;
                useCacheInput.value = '1';
                buildPageGrid(res.total);
                var badge = document.getElementById('specific-loaded');
                badge.textContent = 'PDF loaded — ' + res.total + ' pages. Pick which pages to extract:';
                badge.classList.add('visible');
                document.getElementById('grid-tools').classList.add('visible');
            });
        });

        document.getElementById('select-all-btn').addEventListener('click', function () {
            document.querySelectorAll('#page-grid input').forEach(function (cb) {
                cb.checked = true;
                cb.parentNode.classList.add('checked');
            });
        });
        document.getElementById('clear-all-btn').addEventListener('click', function () {
            document.querySelectorAll('#page-grid input').forEach(function (cb) {
                cb.checked = false;
                cb.parentNode.classList.remove('checked');
            });
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (btn.disabled) return;

            if (!fileInput.files.length) {
                showError('Please choose a PDF file to upload.');
                return;
            }
            var mode = getPageMode();
            if (mode !== 'all' && (!state.loaded || useCacheInput.value !== '1')) {
                showError('Click "Load pages from PDF" first to prepare the page selection.');
                return;
            }
            if (mode === 'range') {
                var from = parseInt(document.getElementById('range-from').value, 10);
                var to = parseInt(document.getElementById('range-to').value, 10);
                if (!from || !to || from < 1 || to < 1 || from > to) {
                    showError('Enter a valid range (start page must be 1 or more and not after the end page).');
                    return;
                }
                if (state.loaded && (from > state.total || to > state.total)) {
                    showError('Range is out of bounds — the PDF has ' + state.total + ' page(s).');
                    return;
                }
            }
            if (mode === 'specific') {
                var checked = [];
                document.querySelectorAll('#page-grid input:checked').forEach(function (cb) {
                    checked.push(parseInt(cb.parentNode.textContent.trim(), 10) || parseInt(cb.value, 10));
                });
                if (!checked.length) {
                    showError('Select at least one page to extract.');
                    return;
                }
                specificPagesInput.value = checked.join(',');
            }

            hideError();
            resultsBox.innerHTML = '';
            setBusy(true);

            if (('Notification' in window) && Notification.permission === 'default') {
                Notification.requestPermission();
            }

            var token = tokenInput.value;
            setBar('convert-bar', 0, true);
            showBlock('upload-progress', false);
            showBlock('convert-progress', true);
            document.getElementById('convert-label').textContent = 'Starting...';
            document.getElementById('convert-pct').textContent = '';

            var pollTimer = setInterval(function () {
                fetch('?ajax=progress&token=' + encodeURIComponent(token))
                    .then(function (r) { return r.json(); })
                    .then(function (p) {
                        if (!p || !p.phase || p.phase === 'none') return;
                        var bar = document.getElementById('convert-bar');
                        var pct = document.getElementById('convert-pct');
                        var label = document.getElementById('convert-label');
                        if (p.phase === 'reading') {
                            setBar('convert-bar', 0, true);
                            label.textContent = p.message || 'Reading PDF...';
                            pct.textContent = '';
                        } else if (p.phase === 'converting' && p.total > 0) {
                            setBar('convert-bar', (p.done / p.total) * 100, false);
                            label.textContent = p.message || ('Converting ' + p.done + ' of ' + p.total);
                            pct.textContent = Math.round((p.done / p.total) * 100) + '%';
                        } else if (p.phase === 'done') {
                            setBar('convert-bar', 100, false);
                            label.textContent = 'Done!';
                            pct.textContent = '100%';
                        } else if (p.phase === 'error') {
                            setBar('convert-bar', 0, false);
                            label.textContent = p.message || 'Conversion failed.';
                            pct.textContent = '';
                        }
                    }).catch(function () {});
            }, 700);

            var fd = new FormData(form);
            var xhr = new XMLHttpRequest();

            xhr.addEventListener('load', function () {
                clearInterval(pollTimer);
                setBusy(false);
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.ok) {
                        setBar('convert-bar', 100, false);
                        document.getElementById('convert-label').textContent = 'Done — extracted ' + res.files.length + ' image(s)!';
                        document.getElementById('convert-pct').textContent = '100%';
                        renderResults(res.files);
                        notifyUser('Done! Extracted ' + res.files.length + ' image(s).');
                        form.reset();
                        resetLoadedUI();
                    } else {
                        showError(res.error || 'Conversion failed.');
                        setBar('convert-bar', 0, false);
                        document.getElementById('convert-label').textContent = 'Failed.';
                        document.getElementById('convert-pct').textContent = '';
                        notifyUser('Conversion failed.');
                    }
                } catch (ex) {
                    showError('Unexpected server response.');
                    notifyUser('Unexpected server response.');
                }
            });

            xhr.addEventListener('error', function () {
                clearInterval(pollTimer);
                setBusy(false);
                showError('Network error during processing.');
                notifyUser('Network error during processing.');
            });

            xhr.open('POST', '', true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.send(fd);
        });

        function resetLoadedUI() {
            state.loaded = false;
            state.total = 0;
            state.loadedName = '';
            useCacheInput.value = '';
            document.getElementById('range-loaded').classList.remove('visible');
            document.getElementById('specific-loaded').classList.remove('visible');
            document.getElementById('grid-tools').classList.remove('visible');
            document.getElementById('page-grid').innerHTML = '';
            document.getElementById('range-to').value = '';
            document.getElementById('range-from').value = '1';
        }

        function renderResults(files) {
            var html = '<a class="zip-btn" id="zip-btn" href="?action=zip&files=' +
                encodeURIComponent(files.join(',')) + '">Download all as ZIP</a>';
            files.forEach(function (name) {
                html += '<div><img src="extracted/' + encodeURIComponent(name) + '" alt="' + name + '">' +
                        '<a href="extracted/' + encodeURIComponent(name) + '" download>Download ' + name + '</a></div>';
            });
            resultsBox.innerHTML = html;
        }

        rotateToken();
    })();
    </script>
</body>
</html>