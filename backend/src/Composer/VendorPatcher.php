<?php

namespace App\Composer;

use Composer\Script\Event;

class VendorPatcher
{
    public static function fixLexikApiPlatform(Event $event): void
    {
        $vendorDir = $event->getComposer()->getConfig()->get('vendor-dir');
        $file = $vendorDir . '/api-platform/symfony/Bundle/DependencyInjection/ApiPlatformExtension.php';

        if (!file_exists($file)) {
            $event->getIO()->write('<comment>VendorPatcher: ApiPlatformExtension.php not found, skipping.</comment>');
            return;
        }

        $content = file_get_contents($file);

        // Normalize line endings for reliable matching, remember original had CRLF or LF
        $hasCrlf = str_contains($content, "\r\n");
        $normalized = str_replace("\r\n", "\n", $content);

        $pattern = '/\n\s*if \(isset\(\$container->getExtensions\(\)\[\'lexik_jwt_authentication\'\]\)\) \{\s*\n\s*\$container->prependExtensionConfig\(\'lexik_jwt_authentication\', \[\s*\n\s*\'api_platform\' => \[\s*\n\s*\'enabled\' => true,\s*\n\s*\],\s*\n\s*\]\);\s*\n\s*\}/';

        $patched = preg_replace($pattern, '', $normalized, 1, $count);

        if ($count === 0) {
            $event->getIO()->write('<comment>VendorPatcher: target block not found (already patched or upstream changed), skipping.</comment>');
            return;
        }

        if ($hasCrlf) {
            $patched = str_replace("\n", "\r\n", $patched);
        }

        file_put_contents($file, $patched);
        $event->getIO()->write('<info>VendorPatcher: removed invalid lexik_jwt_authentication "enabled" option from api-platform/symfony.</info>');
    }
}