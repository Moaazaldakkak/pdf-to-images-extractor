<?php
$outputDir = __DIR__ . '/extracted';
$progressDir = sys_get_temp_dir() . '/pdf2img_progress';
$files = [];
$errors = [];
$success = false;
$isXhr = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
$hasVendor = file_exists(__DIR__ . '/vendor/autoload.php');

function parsePages(string $input, int $total): array
{
    $pages = [];
    foreach (explode(',', $input) as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        if (strpos($part, '-') !== false) {
            [$start, $end] = array_map('trim', explode('-', $part, 2));
            if (!ctype_digit($start) || !ctype_digit($end) || $end < $start) {
                continue;
            }
            for ($i = (int)$start; $i <= (int)$end; $i++) {
                if ($i >= 1 && $i <= $total) {
                    $pages[] = $i;
                }
            }
        } elseif (ctype_digit($part)) {
            $n = (int)$part;
            if ($n >= 1 && $n <= $total) {
                $pages[] = $n;
            }
        }
    }
    return array_values(array_unique($pages));
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
    $token = preg_replace('/[^a-zA-Z0-9_-]/', '', $token);
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
    $token = preg_replace('/[^a-zA-Z0-9_-]/', '', $token);
    if ($token !== '') {
        @unlink($progressDir . '/' . $token . '.json');
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
    $token = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['token'] ?? '');
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';
    try {
        if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Please choose a PDF file to upload.');
        }
        $file = $_FILES['pdf'];
        if (pathinfo($file['name'], PATHINFO_EXTENSION) !== 'pdf' || mime_content_type($file['tmp_name']) !== 'application/pdf') {
            throw new Exception('The uploaded file must be a PDF.');
        }
        $pdfPath = $file['tmp_name'];
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

        $pageInput = trim($_POST['pages'] ?? '');
        if ($pageInput === '' || strtolower($pageInput) === 'all') {
            $pages = range(1, $totalPages);
        } else {
            $pages = parsePages($pageInput, $totalPages);
        }

        if (empty($pages)) {
            throw new Exception("No valid pages selected. The PDF has {$totalPages} page(s). Use formats like \"1,3,5-7\" or \"all\".");
        }

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        set_time_limit(0);

        if ($mode === 'extract') {
            require_once __DIR__ . '/vendor/autoload.php';
            writeProgress($token, ['phase' => 'converting', 'done' => 0, 'total' => count($pages), 'message' => 'Scanning pages for embedded images...']);
            $skipped = 0;
            $found = extractEmbeddedImages($pdfPath, $pages, $outputDir, $files, $totalPages, $token, $skipped);
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
                $imagick->setResolution(300, 300);
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
            justify-content: center;
            padding: 60px 20px;
        }
        .card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            padding: 40px;
            width: 100%;
            max-width: 560px;
        }
        h1 { font-size: 24px; color: #1a1a2e; margin-bottom: 6px; }
        p.sub { color: #6b7280; font-size: 14px; margin-bottom: 28px; }
        label { display: block; font-weight: 600; font-size: 14px; color: #374151; margin-bottom: 8px; }
        input[type="file"] {
            width: 100%;
            padding: 14px;
            border: 2px dashed #cbd5e1;
            border-radius: 8px;
            background: #f9fafb;
            cursor: pointer;
            margin-bottom: 20px;
        }
        input[type="text"] {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 15px;
            margin-bottom: 8px;
        }
        input[type="text"]:focus { outline: none; border-color: #4f46e5; }
        .hint { font-size: 13px; color: #9ca3af; margin-bottom: 24px; }
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
        .progress-wrap { margin: 20px 0 24px; }
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
        .progress-block { display: none; }
        .progress-block.visible { display: block; }
        .progress-sub { font-size: 12px; color: #9ca3af; margin-top: 4px; text-align: right; }
        .mode-group { margin-bottom: 24px; }
        .mode-group label {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            font-size: 14px;
            color: #374151;
            margin-bottom: 8px;
            transition: border-color 0.2s, background 0.2s;
        }
        .mode-group label:hover { border-color: #4f46e5; }
        .mode-group label.checked { border-color: #4f46e5; background: #eef2ff; }
        .mode-group input { accent-color: #4f46e5; }
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
            <input type="hidden" name="token" id="token-input" value="<?php echo htmlspecialchars($_POST['token'] ?? ''); ?>">

            <label for="pdf">PDF file</label>
            <input type="file" name="pdf" id="pdf" accept="application/pdf" required>

            <label for="pages">Pages to extract</label>
            <input type="text" name="pages" id="pages" value="<?php echo htmlspecialchars($_POST['pages'] ?? 'all'); ?>">
            <p class="hint">Examples: <code>all</code> (default), <code>1,3,5</code>, or a range like <code>2-5</code></p>

            <div class="mode-group">
                <label id="mode-label-convert">
                    <input type="radio" name="mode" value="convert" id="mode-convert" <?php echo ($_POST['mode'] ?? 'convert') === 'convert' ? 'checked' : ''; ?>>
                    <span><strong>Convert pages to images</strong><br><small style="font-weight:400;">Renders each selected page as a full-page JPG</small></span>
                </label>
                <label id="mode-label-extract">
                    <input type="radio" name="mode" value="extract" id="mode-extract" <?php echo ($_POST['mode'] ?? '') === 'extract' ? 'checked' : ''; ?>>
                    <span><strong>Extract images inside pages</strong><br><small style="font-weight:400;">Pulls out the original embedded photos/images as JPGs</small></span>
                </label>
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

    <script>
    (function () {
        var form = document.getElementById('upload-form');
        var btn = document.getElementById('submit-btn');
        var tokenInput = document.getElementById('token-input');
        var errBox = document.getElementById('js-error');
        var resultsBox = document.getElementById('results');

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

        function syncModeStyle() {
            document.getElementById('mode-label-convert').classList.toggle('checked', document.getElementById('mode-convert').checked);
            document.getElementById('mode-label-extract').classList.toggle('checked', document.getElementById('mode-extract').checked);
        }
        document.getElementById('mode-convert').addEventListener('change', syncModeStyle);
        document.getElementById('mode-extract').addEventListener('change', syncModeStyle);
        syncModeStyle();

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (btn.disabled) return;

            var fileInput = document.getElementById('pdf');
            if (!fileInput.files.length) {
                showError('Please choose a PDF file to upload.');
                return;
            }

            errBox.style.display = 'none';
            resultsBox.innerHTML = '';
            btn.disabled = true;
            btn.textContent = 'Working...';

            var token = (crypto.randomUUID ? crypto.randomUUID() : 't' + Date.now() + Math.random().toString(16).slice(2));
            tokenInput.value = token;
            var totalSize = fileInput.files[0].size;

            showBlock('upload-progress', true);
            showBlock('convert-progress', true);
            setBar('upload-bar', 0, false);
            document.getElementById('upload-label').textContent = 'Uploading ' + fileInput.files[0].name + '...';
            document.getElementById('upload-pct').textContent = '0%';
            document.getElementById('upload-sub').textContent = '0 B / ' + fmt(totalSize);

            setBar('convert-bar', 0, true);
            document.getElementById('convert-label').textContent = 'Waiting for upload...';
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

            xhr.upload.addEventListener('progress', function (ev) {
                if (!ev.lengthComputable) return;
                var pct = Math.round((ev.loaded / ev.total) * 100);
                setBar('upload-bar', pct, false);
                document.getElementById('upload-pct').textContent = pct + '%';
                document.getElementById('upload-sub').textContent =
                    fmt(ev.loaded) + ' / ' + fmt(totalSize) + ' (' + fmt(Math.max(0, totalSize - ev.loaded)) + ' remaining)';
                if (pct >= 100) {
                    document.getElementById('upload-label').textContent = 'Upload complete. Converting...';
                }
            });

            xhr.addEventListener('load', function () {
                clearInterval(pollTimer);
                btn.disabled = false;
                btn.textContent = 'Extract Images';
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.ok) {
                        showBlock('upload-progress', true);
                        document.getElementById('upload-label').textContent = 'Upload complete.';
                        document.getElementById('upload-pct').textContent = '100%';
                        document.getElementById('upload-sub').textContent = '';
                        setBar('convert-bar', 100, false);
                        document.getElementById('convert-label').textContent = 'Done — extracted ' + res.files.length + ' page(s)!';
                        renderResults(res.files);
                        form.reset();
                    } else {
                        showError(res.error || 'Conversion failed.');
                        setBar('convert-bar', 0, false);
                        document.getElementById('convert-label').textContent = 'Failed.';
                        document.getElementById('convert-pct').textContent = '';
                    }
                } catch (ex) {
                    showError('Unexpected server response.');
                }
            });

            xhr.addEventListener('error', function () {
                clearInterval(pollTimer);
                btn.disabled = false;
                btn.textContent = 'Extract Images';
                showError('Network error during upload or processing.');
            });

            xhr.open('POST', '', true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.send(fd);
        });

        function renderResults(files) {
            var html = '<a class="zip-btn" id="zip-btn" href="?action=zip&files=' +
                encodeURIComponent(files.join(',')) + '">Download all as ZIP</a>';
            files.forEach(function (name) {
                html += '<div><img src="extracted/' + encodeURIComponent(name) + '" alt="' + name + '">' +
                        '<a href="extracted/' + encodeURIComponent(name) + '" download>Download ' + name + '</a></div>';
            });
            resultsBox.innerHTML = html;
        }
    })();
    </script>
</body>
</html>