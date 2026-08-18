<?php
$outputDir = __DIR__ . '/extracted';
$files = [];
$errors = [];
$success = false;

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Please choose a PDF file to upload.';
    } else {
        $file = $_FILES['pdf'];
        if (pathinfo($file['name'], PATHINFO_EXTENSION) !== 'pdf' || mime_content_type($file['tmp_name']) !== 'application/pdf') {
            $errors[] = 'The uploaded file must be a PDF.';
        } else {
            $pdfPath = $file['tmp_name'];

            try {
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

                foreach ($pages as $page) {
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
                }

                $success = true;
            } catch (Exception $e) {
                $errors[] = 'Conversion failed: ' . $e->getMessage();
            }
        }
    }
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
        .alert { border-radius: 8px; padding: 14px; margin-bottom: 20px; font-size: 14px; }
        .alert.error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .alert.success { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
        .results { margin-top: 24px; display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; }
        .results img { width: 100%; border-radius: 6px; border: 1px solid #e5e7eb; display: block; }
        .results a { font-size: 12px; color: #4f46e5; text-decoration: none; display: block; margin-top: 6px; }
        .results a:hover { text-decoration: underline; }
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
            <div class="alert success">
                Extracted <?php echo count($files); ?> page(s) successfully.
            </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
            <label for="pdf">PDF file</label>
            <input type="file" name="pdf" id="pdf" accept="application/pdf" required>

            <label for="pages">Pages to extract</label>
            <input type="text" name="pages" id="pages" value="<?php echo htmlspecialchars($_POST['pages'] ?? 'all'); ?>">
            <p class="hint">Examples: <code>all</code> (default), <code>1,3,5</code>, or a range like <code>2-5</code></p>

            <button type="submit">Extract Images</button>
        </form>

        <?php if ($success && $files): ?>
            <div class="results">
                <?php foreach ($files as $name): ?>
                    <div>
                        <img src="extracted/<?php echo $name; ?>" alt="<?php echo $name; ?>">
                        <a href="extracted/<?php echo $name; ?>" download>Download <?php echo $name; ?></a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
