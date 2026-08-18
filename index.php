<?php
$outputDir = __DIR__ . '/extracted';
$progressDir = sys_get_temp_dir() . '/pdf2img_progress';
$files = [];
$errors = [];
$success = false;
$isXhr = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

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
            var html = '';
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