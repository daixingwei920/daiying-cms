<?php

declare(strict_types=1);

namespace Cms\Core\MarketServer;

use Cms\Core\Config\Settings;
use Cms\Core\Database\ConnectionFactory;
use Cms\Core\Http\Request;
use Cms\Core\Http\Response;
use Throwable;

final class MarketServerController
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function search(Request $request): Response
    {
        try {
            $type = (string) $request->input('type', '');
            $items = (new MarketServerRepository(ConnectionFactory::make($this->settings)))->publishedCatalog($type);
        } catch (Throwable $exception) {
            return Response::json(['items' => [], 'status' => 'unavailable', 'error' => $exception->getMessage()]);
        }

        return Response::json(['items' => array_map(static fn (array $item): array => [
            'market_id' => (string) $item['market_id'],
            'extension_id' => (string) $item['market_id'],
            'type' => (string) $item['extension_type'],
            'name' => (string) $item['name'],
            'version' => (string) $item['version'],
            'price_label' => 'Free',
            'review_status' => 'published',
            'capabilities' => [],
            'package_sha256' => (string) $item['package_sha256'],
        ], $items)]);
    }

    public function authorizeInstall(Request $request): Response
    {
        $marketId = trim((string) $request->input('market_id', ''));
        $siteId = trim((string) $request->input('site_id', ''));
        if ($marketId === '' || $siteId === '') {
            return Response::json(['error' => 'market_id and site_id are required'], 422);
        }
        $licenseKey = trim((string) $request->input('license_key', ''));
        if ($this->commercialPolicyFlag('require_license_for_install', 'market.require_license_for_install')) {
            if ($licenseKey === '') {
                return Response::json(['error' => 'license_key is required for installs'], 403);
            }
            try {
                (new MarketServerRepository(ConnectionFactory::make($this->settings)))->assertLicenseActive($marketId, $siteId, $licenseKey);
            } catch (Throwable $exception) {
                return Response::json(['error' => $exception->getMessage()], 403);
            }
        }
        $expiresAt = time() + 600;
        $scopes = ['download'];

        return Response::json([
            'market_id' => $marketId,
            'site_id' => $siteId,
            'license_key' => $licenseKey,
            'token' => hash('sha256', $marketId . '|' . $siteId . '|' . gmdate('Y-m-d-H')),
            'download_token' => $this->downloadToken($marketId, $siteId, $expiresAt, $scopes),
            'download_expires_at' => $expiresAt,
            'expires_at' => gmdate('c', $expiresAt),
            'scope' => ['install', 'download'],
        ]);
    }

    public function versionDetail(Request $request): Response
    {
        $marketId = trim((string) $request->input('market_id', ''));
        if ($marketId === '') {
            return Response::json(['error' => 'market_id is required'], 422);
        }

        try {
            $detail = (new MarketServerRepository(ConnectionFactory::make($this->settings)))->publishedVersionDetail($marketId, trim((string) $request->input('version', '')));
        } catch (Throwable $exception) {
            return Response::json(['error' => $exception->getMessage()], 404);
        }

        return Response::json(['version' => $detail]);
    }

    public function downloadPackage(Request $request): Response
    {
        $marketId = trim((string) $request->input('market_id', ''));
        if ($marketId === '') {
            return Response::json(['error' => 'market_id is required'], 422);
        }
        $siteId = trim((string) $request->input('site_id', ''));
        $token = trim((string) $request->input('token', ''));
        $expiresAt = (int) $request->input('expires_at', 0);

        try {
            $repo = new MarketServerRepository(ConnectionFactory::make($this->settings));
            $licenseKey = trim((string) $request->input('license_key', ''));
            if ($this->commercialPolicyFlag('require_license_for_download', 'market.require_license_for_download') && $licenseKey === '') {
                throw new MarketServerException('license_key is required for downloads.');
            }
            if ($licenseKey !== '') {
                $repo->assertLicensedDownloadAuthorized($marketId, $siteId, $token, $expiresAt, $licenseKey, ['download']);
            } else {
                $repo->assertDownloadAuthorized($marketId, $siteId, $token, $expiresAt, ['download']);
            }
            $detail = $repo->publishedVersionDetail($marketId, trim((string) $request->input('version', '')));
            $packagePath = (string) ($detail['package_path'] ?? '');
            if (!is_file($packagePath)) {
                return Response::json(['error' => 'package file was not found'], 404);
            }
            $body = file_get_contents($packagePath);
            if (!is_string($body)) {
                return Response::json(['error' => 'package file could not be read'], 500);
            }
            $repo->recordDownloadAudit(
                (string) $detail['market_id'],
                (string) $detail['version'],
                $siteId,
                (string) $detail['package_sha256'],
                (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
            );
        } catch (Throwable $exception) {
            return Response::json(['error' => $exception->getMessage()], 404);
        }

        return new Response($body, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="' . basename($packagePath) . '"',
            'X-Package-Sha256' => (string) ($detail['package_sha256'] ?? ''),
        ]);
    }

    public function reportLicenseUsage(Request $request): Response
    {
        $marketId = trim((string) $request->input('market_id', ''));
        $siteId = trim((string) $request->input('site_id', ''));
        $licenseKey = trim((string) $request->input('license_key', ''));
        if ($marketId === '' || $siteId === '' || $licenseKey === '') {
            return Response::json(['error' => 'market_id, site_id and license_key are required'], 422);
        }
        try {
            $repo = new MarketServerRepository(ConnectionFactory::make($this->settings));
            $repo->assertLicenseActive($marketId, $siteId, $licenseKey);
            $usage = $repo->recordLicenseUsage($licenseKey, $siteId, (int) $request->input('active_seats', 0), [
                'hostname' => (string) $request->input('hostname', ''),
                'php_version' => (string) $request->input('php_version', ''),
            ]);

            return Response::json(['usage' => $usage]);
        } catch (Throwable $exception) {
            return Response::json(['error' => $exception->getMessage()], 403);
        }
    }

    public function licensePortal(Request $request): Response
    {
        $token = trim((string) $request->input('token', ''));
        $marketId = trim((string) $request->input('market_id', ''));
        $siteId = trim((string) $request->input('site_id', ''));
        $licenseKey = trim((string) $request->input('license_key', ''));
        if ($token === '' && ($marketId === '' || $siteId === '' || $licenseKey === '')) {
            return Response::json(['error' => 'market_id, site_id and license_key are required'], 422);
        }
        try {
            $repo = new MarketServerRepository(ConnectionFactory::make($this->settings));
            if ($token !== '') {
                if ((string) $request->input('format', '') === 'html') {
                    return Response::html($repo->customerLicensePortalHtmlByToken($token));
                }

                return Response::json(['portal' => $repo->customerLicensePortalByToken($token)]);
            }
            if ((string) $request->input('format', '') === 'html') {
                return Response::html($repo->customerLicensePortalHtml($marketId, $siteId, $licenseKey));
            }
            $portal = $repo->customerLicensePortal($marketId, $siteId, $licenseKey);

            return Response::json(['portal' => $portal]);
        } catch (Throwable $exception) {
            return Response::json(['error' => $exception->getMessage()], 403);
        }
    }

    /** @param list<string> $scopes */
    private function downloadToken(string $marketId, string $siteId, int $expiresAt, array $scopes): string
    {
        sort($scopes);

        return hash('sha256', $marketId . '|' . $siteId . '|' . $expiresAt . '|' . implode(',', $scopes));
    }

    private function commercialPolicyFlag(string $policyKey, string $configKey): bool
    {
        try {
            $policy = (new MarketServerRepository(ConnectionFactory::make($this->settings)))->commercialPolicy();
            if (array_key_exists($policyKey, $policy)) {
                return (int) $policy[$policyKey] === 1;
            }
        } catch (Throwable) {
        }

        return (bool) $this->settings->get($configKey, false);
    }
}
