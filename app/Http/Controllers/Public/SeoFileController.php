<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Actions\Seo\GenerateSitemap;
use App\Http\Controllers\Controller;
use App\Support\Seo\LlmsTxt;
use App\Support\Seo\RobotsTxt;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * /sitemap.xml, /robots.txt and /llms.txt.
 */
final class SeoFileController extends Controller
{
    public function sitemap(GenerateSitemap $generate): BinaryFileResponse
    {
        $path = (string) config('site.sitemap_path');

        if (! is_file($path)) {
            $generate->handle();
        }

        return response()->file($path, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function robots(RobotsTxt $robots): Response
    {
        return response($robots->render(), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function llms(LlmsTxt $llms): Response
    {
        return response($llms->render(), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
