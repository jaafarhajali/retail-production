<?php
declare(strict_types=1);

namespace App\Services;

/**
 * PDF export (owner, 2026-10-29): a print page saved as a PDF file, for the invoices, the statement of account and the
 * reports. The page is rendered exactly as the browser prints it: the HTML is written to a temporary file and Google
 * Chrome (or Microsoft Edge, present on every Windows PC) prints it to PDF headless. No PDF library: the Arabic names,
 * the logo and the stylesheet come out as on paper, and the print pages stay the only layout to maintain.
 */
final class PdfService
{
    /** The browser that prints: Chrome first, then Edge. Null when neither is installed. */
    public static function browser(): ?string
    {
        $candidates = [
            getenv('RETAIL_POS_PDF_BROWSER') ?: '',
            'C:/Program Files/Google/Chrome/Application/chrome.exe',
            'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
            'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
            'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
            '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser',
        ];
        foreach ($candidates as $path) {
            if ($path !== '' && is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Renders the HTML of a print page to a PDF file and returns its path. The HTML gets a <base> that points at the
     * public folder, so the stylesheet, the icons and the logo load from disk, no web server or login needed.
     *
     * @throws \DomainException when no browser is installed or the print failed
     */
    public static function fromHtml(string $html): string
    {
        $browser = self::browser() ?? throw new \DomainException('Making a PDF needs Google Chrome or Microsoft Edge installed on the server.');
        $dir = STORAGE_PATH . '/tmp';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $stem = $dir . '/pdf-' . bin2hex(random_bytes(6));
        $base = 'file:///' . str_replace(['\\', ' '], ['/', '%20'], BASE_PATH . '/public') . '/';
        $html = preg_replace('/<head>/i', '<head><base href="' . $base . '">', $html, 1) ?? $html;
        file_put_contents($stem . '.html', $html);
        $cmd = '"' . $browser . '" --headless=new --disable-gpu --no-sandbox --no-first-run --no-default-browser-check --disable-extensions'
             . ' --user-data-dir="' . $dir . '/chrome-profile" --no-pdf-header-footer --run-all-compositor-stages-before-draw --virtual-time-budget=3000'
             . ' --print-to-pdf="' . $stem . '.pdf" "file:///' . str_replace(['\\', ' '], ['/', '%20'], $stem) . '.html" 2>&1';
        exec($cmd, $out, $rc);
        @unlink($stem . '.html');
        if (!is_file($stem . '.pdf') || filesize($stem . '.pdf') < 100) {
            throw new \DomainException('The PDF could not be made (' . basename($browser) . ' said: ' . trim(implode(' ', $out)) . ').');
        }

        return $stem . '.pdf';
    }

    /** Sends the PDF of a print page as a download and ends the request. */
    public static function send(string $html, string $filename): never
    {
        $path = self::fromHtml($html);
        $name = preg_replace('/[^\p{L}\p{N} ._-]+/u', '', $filename) ?: 'document';
        header('Content-Type: application/pdf');
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '.pdf"; filename*=UTF-8\'\'' . rawurlencode($name . '.pdf'));
        header('Cache-Control: private, no-store');
        readfile($path);
        @unlink($path);
        exit;
    }
}
