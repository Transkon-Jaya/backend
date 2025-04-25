<?php
function compressAndResizeImage($sourcePath, $destinationPath, $maxWidth = 800, $maxHeight = 800, $quality = 75) {
    $info = getimagesize($sourcePath);

    if (!$info) {
        throw new Exception("Invalid image file.");
    }

    list($width, $height) = $info;
    $mime = $info['mime'];

    $ratio = min($maxWidth / $width, $maxHeight / $height);
    $newWidth = (int)($width * $ratio);
    $newHeight = (int)($height * $ratio);

    $dstImage = imagecreatetruecolor($newWidth, $newHeight);

    switch ($mime) {
        case 'image/jpeg':
            $srcImage = imagecreatefromjpeg($sourcePath);
            break;
        case 'image/png':
            $srcImage = imagecreatefrompng($sourcePath);
            imagealphablending($dstImage, false);
            imagesavealpha($dstImage, true);
            break;
        case 'image/webp':
            $srcImage = imagecreatefromwebp($sourcePath);
            break;
        default:
            throw new Exception("Unsupported image type: $mime");
    }

    imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    switch ($mime) {
        case 'image/jpeg':
            imagejpeg($dstImage, $destinationPath, $quality);
            break;
        case 'image/png':
            imagepng($dstImage, $destinationPath, (int)($quality / 10));
            break;
        case 'image/webp':
            imagewebp($dstImage, $destinationPath, $quality);
            break;
    }

    imagedestroy($srcImage);
    imagedestroy($dstImage);
    
    return true;
}
