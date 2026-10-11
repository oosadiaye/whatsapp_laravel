<?php

declare(strict_types=1);

namespace Tests\Feature\Calls;

use Tests\TestCase;

/**
 * The Africa's Talking softphone SDK is vendored (public/vendor) and served
 * same-origin, and upstream hardcodes its ringback/dial tones to Cloudinary.
 * Production CSP is `media-src 'self' blob: data:`, so those upstream URLs are
 * blocked and the agent hears no ringback while an outbound call connects.
 * We rewrite them to self-hosted copies — see public/vendor/README.md.
 *
 * These tests fail loudly if the SDK is ever re-downloaded/upgraded without
 * re-applying that patch, which would silently reintroduce the blocked tones.
 */
class VendoredAtSdkTest extends TestCase
{
    private function sdk(): string
    {
        $path = public_path('vendor/africastalking-1.0.7.js');
        $this->assertFileExists($path, 'the vendored AT softphone SDK must be committed');

        return (string) file_get_contents($path);
    }

    public function test_vendored_sdk_references_no_external_media(): void
    {
        // Any absolute http(s) media URL would be blocked by `media-src 'self'`.
        $this->assertSame(
            [],
            $this->findAllMatches('~https?://[^"\'\s]+\.(?:mp3|wav|ogg|m4a)~i', $this->sdk()),
            'the vendored SDK must not load audio from an external origin — CSP blocks it. '
            .'Re-apply the URL rewrites in public/vendor/README.md after upgrading the SDK.'
        );
    }

    public function test_vendored_sdk_no_longer_points_at_cloudinary(): void
    {
        $this->assertStringNotContainsString(
            'res.cloudinary.com',
            $this->sdk(),
            'the Cloudinary tone URLs must stay patched out (see public/vendor/README.md)'
        );
    }

    public function test_vendored_sdk_points_at_the_self_hosted_tones(): void
    {
        $sdk = $this->sdk();

        $this->assertStringContainsString('/vendor/at-audio/ringing.mp3', $sdk);
        $this->assertStringContainsString('/vendor/at-audio/dialing.mp3', $sdk);
    }

    public function test_self_hosted_tone_files_are_present_and_non_empty(): void
    {
        foreach (['ringing', 'dialing'] as $tone) {
            $path = public_path("vendor/at-audio/{$tone}.mp3");
            $this->assertFileExists($path, "the self-hosted {$tone} tone must be committed");
            $this->assertGreaterThan(
                1024,
                (int) filesize($path),
                "the {$tone} tone looks truncated — re-download it per public/vendor/README.md"
            );
        }
    }

    /**
     * @return list<string>
     */
    private function findAllMatches(string $pattern, string $subject): array
    {
        preg_match_all($pattern, $subject, $m);

        return array_values(array_unique($m[0] ?? []));
    }
}
