<?php
function compressAndResizeImage($source, $destination, $maxWidth, $maxHeight, $quality = 75) {
    // Dapatkan info gambar
    $info = getimagesize($source);
    if (!$info) {
        return false;
    }

    // Buat gambar berdasarkan tipe
    switch ($info['mime']) {
        case 'image/jpeg':
            $image = imagecreatefromjpeg($source);
            break;
        case 'image/png':
            $image = imagecreatefrompng($source);
            break;
        case 'image/gif':
            $image = imagecreatefromgif($source);
            break;
        default:
            return false;
    }

    // Hitung rasio resize
    $width = imagesx($image);
    $height = imagesy($image);
    $ratio = min($maxWidth/$width, $maxHeight/$height);

    // Jika gambar lebih kecil dari maksimum, tidak perlu resize
    if ($ratio >= 1) {
        return move_uploaded_file($source, $destination);
    }

    // Buat canvas baru
    $newWidth = (int)($width * $ratio);
    $newHeight = (int)($height * $ratio);
    $newImage = imagecreatetruecolor($newWidth, $newHeight);

    // Handle transparansi PNG/GIF
    if ($info['mime'] == 'image/png' || $info['mime'] == 'image/gif') {
        imagecolortransparent($newImage, imagecolorallocatealpha($newImage, 0, 0, 0, 127));
        imagealphablending($newImage, false);
        imagesavealpha($newImage, true);
    }

    // Resize gambar
    imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    // Simpan gambar
    switch ($info['mime']) {
        case 'image/jpeg':
            $success = imagejpeg($newImage, $destination, $quality);
            break;
        case 'image/png':
            $success = imagepng($newImage, $destination, 9); // Kompresi PNG (0-9)
            break;
        case 'image/gif':
            $success = imagegif($newImage, $destination);
            break;
        default:
            $success = false;
    }

    // Bersihkan memory
    imagedestroy($image);
    imagedestroy($newImage);

    return $success;
}