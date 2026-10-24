<?php

declare(strict_types=1);

namespace HektaPay\GooglePlay;

use PDO;

final class GooglePlayFactory
{
    public static function service(PDO $db, InvoiceLifecycle $lifecycle, ?HttpTransport $transport = null): GooglePlayService
    {
        $transport = $transport ?? new CurlTransport();
        $credentials = ServiceAccountAuth::credentialsFromEnvironment();
        if ($credentials === null) {
            throw new GooglePlayException('GOOGLE_NOT_CONFIGURED', 'Google Play credentials are not configured.', 503);
        }
        try {
            $vault = TokenVault::fromEnvironment();
            $auth = new ServiceAccountAuth($credentials, $transport);
        } catch (\RuntimeException $error) {
            GooglePlayService::log('configuration_invalid', ['error' => $error->getMessage()]);
            throw new GooglePlayException('GOOGLE_NOT_CONFIGURED', 'Google Play credentials are not configured.', 503);
        }
        return new GooglePlayService($db, new PlayApiClient($auth, $transport), new PlayProductCatalog($db), $vault, $lifecycle);
    }

    public static function rtdn(PDO $db, GooglePlayService $service, ?HttpTransport $transport = null): GooglePlayRtdn
    {
        try {
            $verifier = PubSubJwtVerifier::fromEnvironment($transport ?? new CurlTransport());
        } catch (\RuntimeException $error) {
            throw new GooglePlayException('GOOGLE_NOT_CONFIGURED', 'Google Play notifications are not configured.', 503);
        }
        return new GooglePlayRtdn($service, new PlayProductCatalog($db), $verifier);
    }
}
