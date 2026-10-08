<?php

namespace App\Service;

use App\Api\ApiProblem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ProjectPictureStorage
{
    public function __construct(#[Autowire('%upload_directory%')] private readonly string $uploadDirectory)
    {
    }

    public function store(UploadedFile $file): string
    {
        if (!$file->isValid()) {
            throw new ApiProblem(422, 'validation_failed', 'Upload failed.', ['picture' => 'Upload must complete successfully.']);
        }
        if ($file->getSize() > 5 * 1024 * 1024) {
            throw new ApiProblem(422, 'validation_failed', 'Image is too large.', ['picture' => 'Maximum size is 5 MiB.']);
        }
        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$file->getMimeType()] ?? null;
        if ($extension === null || @getimagesize($file->getPathname()) === false) {
            throw new ApiProblem(422, 'validation_failed', 'Unsupported image.', ['picture' => 'Use a JPEG, PNG, or WebP image.']);
        }
        $filename = bin2hex(random_bytes(16)).'.'.$extension;
        try {
            $file->move($this->uploadDirectory, $filename);
        } catch (FileException $e) {
            throw new ApiProblem(500, 'upload_storage_failed', 'Unable to store the image.');
        }
        return $filename;
    }

    public function remove(string $filename): void
    {
        if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/D', $filename)) {
            throw new \InvalidArgumentException('Invalid stored picture filename.');
        }
        $path = $this->uploadDirectory.DIRECTORY_SEPARATOR.$filename;
        if (is_file($path) && !unlink($path)) {
            throw new \RuntimeException('Unable to clean up the stored image.');
        }
    }
}
