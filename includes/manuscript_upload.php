<?php

function store_manuscript_upload(array $file): array
{
    $max_size = 20 * 1024 * 1024;
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new InvalidArgumentException('Choose a PDF manuscript to upload.');
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $message = 'The manuscript could not be uploaded. Please try again.';
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            $message = 'The manuscript exceeds the upload size limit.';
        } elseif ($file['error'] === UPLOAD_ERR_NO_FILE) {
            $message = 'Choose a PDF manuscript to upload.';
        }
        throw new InvalidArgumentException($message);
    }

    if (!isset($file['tmp_name'], $file['size']) || (int)$file['size'] <= 0 || (int)$file['size'] > $max_size) {
        throw new InvalidArgumentException('The manuscript must be smaller than 20 MB.');
    }

    $file_info = new finfo(FILEINFO_MIME_TYPE);
    $mime_type = $file_info->file($file['tmp_name']);
    $header = file_get_contents($file['tmp_name'], false, null, 0, 1024);
    if ($mime_type !== 'application/pdf' || !is_string($header) || strpos($header, '%PDF-') === false) {
        throw new InvalidArgumentException('Only valid PDF manuscripts are accepted.');
    }

    $storage_dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'manuscripts';
    if (!is_dir($storage_dir) && !mkdir($storage_dir, 0700, true) && !is_dir($storage_dir)) {
        throw new RuntimeException('Unable to create the manuscript storage directory.');
    }

    $stored_filename = bin2hex(random_bytes(16)) . '.pdf';
    $stored_path = $storage_dir . DIRECTORY_SEPARATOR . $stored_filename;
    if (!move_uploaded_file($file['tmp_name'], $stored_path)) {
        throw new RuntimeException('Unable to store the uploaded manuscript.');
    }

    $original_filename = trim(str_replace(["\0", "\r", "\n"], '', basename((string)($file['name'] ?? ''))));
    if ($original_filename === '') {
        $original_filename = 'manuscript.pdf';
    }
    if (function_exists('mb_substr')) {
        $original_filename = mb_substr($original_filename, 0, 255);
    } else {
        $original_filename = substr($original_filename, 0, 255);
    }

    return [
        'stored_filename' => $stored_filename,
        'stored_path' => $stored_path,
        'original_filename' => $original_filename,
        'file_size' => (int)$file['size'],
    ];
}
