<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

final class DriverAppRelease
{
    public function __construct(
        private readonly string $projectDir,
        private readonly int $versionCode,
        private readonly string $versionName,
        private readonly bool $forceUpdate,
        private readonly string $changelog,
        private readonly string $apkFilename,
        private readonly string $companyName,
    ) {
    }

    public function versionCode(): int
    {
        return $this->versionCode;
    }

    public function versionName(): string
    {
        return $this->versionName;
    }

    public function forceUpdate(): bool
    {
        return $this->forceUpdate;
    }

    public function changelog(): string
    {
        return $this->changelog;
    }

    public function apkFilename(): string
    {
        return $this->apkFilename;
    }

    public function companyName(): string
    {
        return $this->companyName;
    }

    public function apkPath(): string
    {
        return $this->projectDir.\DIRECTORY_SEPARATOR.'var'.\DIRECTORY_SEPARATOR.'driver'.\DIRECTORY_SEPARATOR.$this->apkFilename;
    }

    public function isApkAvailable(): bool
    {
        return is_file($this->apkPath());
    }

    /**
     * @return array{
     *     versionCode: int,
     *     versionName: string,
     *     force: bool,
     *     changelog: string,
     *     apkAvailable: bool,
     *     apkUrl: ?string,
     *     downloadPageUrl: string,
     *     downloadUrl: ?string
     * }
     */
    public function toPublicArray(Request $request): array
    {
        $available = $this->isApkAvailable();

        return [
            'versionCode' => $this->versionCode,
            'versionName' => $this->versionName,
            'force' => $this->forceUpdate,
            'changelog' => $this->changelog,
            'apkAvailable' => $available,
            'apkUrl' => $available ? $request->getUriForPath('/api/driver/app.apk') : null,
            'downloadPageUrl' => $request->getUriForPath('/download'),
            'downloadUrl' => $available ? $request->getUriForPath('/download/app.apk') : null,
        ];
    }

    /**
     * @return array{
     *     available: bool,
     *     versionName: string,
     *     versionCode: int,
     *     changelog: string,
     *     apkUrl: string,
     *     pageUrl: string,
     *     companyName: string
     * }
     */
    public function downloadPageVars(Request $request): array
    {
        return [
            'available' => $this->isApkAvailable(),
            'versionName' => $this->versionName,
            'versionCode' => $this->versionCode,
            'changelog' => $this->changelog,
            'apkUrl' => $request->getUriForPath('/download/app.apk'),
            'pageUrl' => $request->getUriForPath('/download'),
            'companyName' => $this->companyName,
        ];
    }

    public function apkResponse(): Response
    {
        if (!$this->isApkAvailable()) {
            return new JsonResponse(
                ['message' => 'Driver APK is not published yet'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $response = new BinaryFileResponse($this->apkPath());
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $this->apkFilename,
        );
        $response->headers->set('Content-Type', 'application/vnd.android.package-archive');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');

        return $response;
    }
}
