<?php

namespace App\Controllers;

class AttachmentController extends BaseController
{
    private const MAX_BYTES = 5_242_880;

    private const ALLOWED = [
        'jpg'  => IMAGETYPE_JPEG,
        'jpeg' => IMAGETYPE_JPEG,
        'png'  => IMAGETYPE_PNG,
        'gif'  => IMAGETYPE_GIF,
        'webp' => IMAGETYPE_WEBP,
    ];

    private function directory(): string
    {
        return WRITEPATH . 'uploads' . DIRECTORY_SEPARATOR . 'tickets' . DIRECTORY_SEPARATOR;
    }

    public function store()
    {
        $file = $this->request->getFile('image');

        if ($file === null || ! $file->isValid()) {
            return $this->fail($file?->getErrorString() ?? 'No file was received.');
        }

        if ($file->getSize() > self::MAX_BYTES) {
            return $this->fail('That image is larger than 5MB. Please attach a smaller one.');
        }

        $extension = strtolower($file->getClientExtension() ?: '');

        if (! isset(self::ALLOWED[$extension])) {
            return $this->fail('Only JPG, PNG, GIF and WebP images can be attached.');
        }

        $info = @getimagesize($file->getTempName());

        if ($info === false || ($info[2] ?? null) !== self::ALLOWED[$extension]) {
            return $this->fail('That file is not a real image.');
        }

        $directory = $this->directory();

        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            log_message('error', 'Could not create the upload directory at {dir}', ['dir' => $directory]);

            return $this->fail('Attachments are not set up on the server. Tell IT.');
        }

        $name = bin2hex(random_bytes(16)) . '.' . $extension;

        try {
            $file->move($directory, $name);
        } catch (\Throwable $e) {
            log_message('error', 'Attachment upload failed: {message}', ['message' => $e->getMessage()]);

            return $this->fail('That image could not be saved. Please try again.');
        }

        $user = current_user();
        log_message('info', 'Attachment {name} uploaded by {email}', [
            'name'  => $name,
            'email' => $user['email'] ?? '?',
        ]);

        return $this->response->setJSON([
            'ok'  => true,
            'url' => '/uploads/tickets/' . $name,
        ]);
    }

    public function show(string $name)
    {

        if (preg_match('/^[a-f0-9]{32}\.(jpg|jpeg|png|gif|webp)$/', $name) !== 1) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $path = $this->directory() . $name;

        if (! is_file($path)) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $info = @getimagesize($path);
        $mime = $info === false ? 'application/octet-stream' : ($info['mime'] ?? 'application/octet-stream');

        return $this->response
            ->setHeader('Content-Type', $mime)
            ->setHeader('Content-Length', (string) filesize($path))

            ->setHeader('Cache-Control', 'private, max-age=31536000, immutable')

            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Content-Disposition', 'inline; filename="' . $name . '"')
            ->setBody((string) file_get_contents($path));
    }

    private function fail(string $message): \CodeIgniter\HTTP\ResponseInterface
    {
        return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => $message]);
    }
}
