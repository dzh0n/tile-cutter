<?php
/**
 * Нарезка тайлов: указывается maxZoom и количество уровней
 * Генерируются уровни: [maxZoom - levels + 1] до [maxZoom]
 * Формат: /{z}/tile-{x}-{y}.png|jpg
 */

// 🔧 КОНФИГУРАЦИЯ
$config = [
    'sourceImage'      => 'map.png',         // Путь к исходному изображению
    'outputDir'        => 'tiles/',          // Папка для тайлов
    'tileSize'         => 256,               // Размер тайла
    'maxZoom'          => 10,                // Максимальный уровень zoom (например, 10)
    'levelsToGenerate' => 4,                 // Сколько уровней нарезать (с конца)
    'format'           => 'png',             // 'png' или 'jpg'
    'jpegQuality'      => 95,                // Качество JPEG (1-100)
];

$sourceImage      = $config['sourceImage'];
$outputDir        = $config['outputDir'];
$tileSize         = $config['tileSize'];
$maxZoom          = $config['maxZoom'];
$levelsToGenerate = $config['levelsToGenerate'];
$format           = strtolower($config['format']);
$jpegQuality      = $config['jpegQuality'];

if (!in_array($format, ['png', 'jpg'])) {
    die("Ошибка: формат должен быть 'png' или 'jpg'. Указано: $format\n");
}

if ($levelsToGenerate < 1) {
    die("Ошибка: levelsToGenerate должно быть >= 1\n");
}

// Проверка исходного файла
if (!file_exists($sourceImage)) {
    die("Ошибка: исходное изображение не найдено: $sourceImage\n");
}

// Создание папки
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

// Информация об изображении
$imageInfo = getimagesize($sourceImage);
if (!$imageInfo) {
    die("Ошибка: не удалось прочитать изображение.\n");
}

$mimeType = $imageInfo['mime'];
$width    = $imageInfo[0];
$height   = $imageInfo[1];

echo "📏 Исходное изображение: {$width}x{$height} px\n";
echo "📌 Максимальный zoom: $maxZoom\n";
echo "🎯 Уровней для генерации: $levelsToGenerate\n";

// --- РАСЧЁТ ДИАПАЗОНА ZOOM ---
$startZoom = $maxZoom - $levelsToGenerate + 1;

if ($startZoom < 0) {
    echo "⚠️  Внимание: startZoom = $startZoom. Уменьшаем levelsToGenerate до максимально возможного.\n";
    $startZoom = 0;
    $levelsToGenerate = $maxZoom + 1;
    echo "🔁 Теперь: levelsToGenerate = $levelsToGenerate, startZoom = 0\n";
}

echo "📊 Диапазон zoom: от $startZoom до $maxZoom (всего $levelsToGenerate уровней)\n";

// Загрузка изображения
function loadImage($path, $mimeType) {
    switch ($mimeType) {
        case 'image/jpeg':
        case 'image/jpg':
            return imagecreatefromjpeg($path);
        case 'image/png':
            return imagecreatefrompng($path);
        case 'image/webp':
            return imagecreatefromwebp($path);
        default:
            die("Ошибка: не поддерживается MIME: $mimeType\n");
    }
}

$image = loadImage($sourceImage, $mimeType);
if (!$image) {
    die("❌ Не удалось загрузить изображение.\n");
}

// Проверка прозрачности (только для PNG)
$hasAlpha = false;
if ($format === 'png' && $mimeType === 'image/png') {
    $hasAlpha = (imagecolortransparent($image) !== -1) || (imagecolorstotal($image) < 256);
}

// --- ГЛАВНЫЙ ЦИКЛ: от $startZoom до $maxZoom ---
for ($zoom = $startZoom; $zoom <= $maxZoom; $zoom++) {
    $scale = pow(2, $maxZoom - $zoom);
    $scaledWidth  = max(1, (int) ceil($width  / $scale));
    $scaledHeight = max(1, (int) ceil($height / $scale));

    $tilesX = (int) ceil($scaledWidth  / $tileSize);
    $tilesY = (int) ceil($scaledHeight / $tileSize);

    echo "➡️  Zoom $zoom: {$scaledWidth}x{$scaledHeight} px → {$tilesX}x{$tilesY} тайлов\n";

    // Создаём уменьшенную копию
    $scaledImage = imagecreatetruecolor($scaledWidth, $scaledHeight);

    if ($format === 'png' && $hasAlpha) {
        $transparent = imagecolorallocatealpha($scaledImage, 0, 0, 0, 127);
        imagefill($scaledImage, 0, 0, $transparent);
        imagesavealpha($scaledImage, true);
    } else {
        $bg = imagecolorallocate($scaledImage, 255, 255, 255);
        imagefill($scaledImage, 0, 0, $bg);
    }

    imagecopyresampled($scaledImage, $image, 0, 0, 0, 0,
        $scaledWidth, $scaledHeight,
        $width, $height
    );

    // Папка: /tiles/7/, /tiles/8/ и т.д.
    $zoomDir = $outputDir . $zoom . '/';
    if (!is_dir($zoomDir)) {
        mkdir($zoomDir, 0755, true);
    }

    // Нарезка на тайлы
    for ($y = 0; $y < $tilesY; $y++) {
        for ($x = 0; $x < $tilesX; $x++) {
            $tileImage = imagecreatetruecolor($tileSize, $tileSize);

            if ($format === 'png' && $hasAlpha) {
                $transparent = imagecolorallocatealpha($tileImage, 0, 0, 0, 127);
                imagefill($tileImage, 0, 0, $transparent);
                imagesavealpha($tileImage, true);
            } else {
                $bg = imagecolorallocate($tileImage, 255, 255, 255);
                imagefill($tileImage, 0, 0, $bg);
            }

            $srcX = $x * $tileSize;
            $srcY = $y * $tileSize;
            $srcW = min($tileSize, $scaledWidth  - $srcX);
            $srcH = min($tileSize, $scaledHeight - $srcY);

            imagecopy($tileImage, $scaledImage, 0, 0, $srcX, $srcY, $srcW, $srcH);

            // 🟩 Имя: /{z}/tile-{x}-{y}.png или .jpg
            $fileName = "tile-{$x}-{$y}." . $format;
            $tilePath = $zoomDir . $fileName;

            if ($format === 'png') {
                imagepng($tileImage, $tilePath, 0); // без сжатия
            } else {
                imagejpeg($tileImage, $tilePath, $jpegQuality);
            }

            imagedestroy($tileImage);
        }
    }

    imagedestroy($scaledImage);
}

imagedestroy($image);

echo "✅ Готово! Тайлы сохранены в: $outputDir\n";
echo "📌 Диапазон zoom: от $startZoom до $maxZoom\n";
echo "📌 Используй в Leaflet: tiles/{z}/tile-{x}-{y}.$format\n";
?>
