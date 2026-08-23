<?php

declare(strict_types=1);

if (!function_exists('getAppBasePath')) {
    function getAppBasePath(): string
    {
        $documentRoot = str_replace('\\', '/', rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/'));
        $appRoot = str_replace('\\', '/', dirname(__DIR__, 2));

        if ($documentRoot !== '' && strncmp($appRoot, $documentRoot, strlen($documentRoot)) === 0) {
            $relative = trim(substr($appRoot, strlen($documentRoot)), '/');
            return $relative !== '' ? '/' . $relative : '';
        }

        $scriptName = trim(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        if ($scriptName === '') {
            return '';
        }

        $parts = explode('/', $scriptName);
        return !empty($parts[0]) ? '/' . $parts[0] : '';
    }
}

if (!function_exists('finditAssetUrl')) {
    function finditAssetUrl(string $assetPath): string
    {
        $basePath = rtrim(getAppBasePath(), '/');
        return $basePath . '/assets/' . ltrim($assetPath, '/');
    }
}
