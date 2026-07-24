<?php

declare(strict_types=1);

namespace Tests\Feature\Mailbox;

use App\Services\MailClient\AttachmentName;
use PHPUnit\Framework\TestCase;

/**
 * Pre-merge review: attachment filenames are attacker-controlled and flow into
 * storage paths. AttachmentName::safe() must reduce any of them to a harmless
 * basename.
 */
class AttachmentNameTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function names(): array
    {
        return [
            'plain' => ['report.pdf', 'report.pdf'],
            'unix traversal' => ['../../etc/passwd', 'passwd'],
            'nested unix path' => ['/var/www/evil.php', 'evil.php'],
            'windows traversal' => ['..\\..\\windows\\system32\\evil', 'evil'],
            'dot-dot only' => ['..', 'attachment'],
            'single dot' => ['.', 'attachment'],
            'leading dots stripped' => ['...hidden.txt', 'hidden.txt'],
            'empty falls back' => ['', 'attachment'],
            'control chars stripped' => ["in\x00voi\x1fce.pdf", 'invoice.pdf'],
            'keeps unicode' => ['rapport-café.pdf', 'rapport-café.pdf'],
        ];
    }

    /**
     * @dataProvider names
     */
    public function test_it_reduces_a_name_to_a_safe_basename(string $input, string $expected): void
    {
        $this->assertSame($expected, AttachmentName::safe($input));
    }

    public function test_the_result_never_contains_a_path_separator(): void
    {
        foreach (['../a/b', '..\\a\\b', '/a/b/c', 'a/../../b'] as $hostile) {
            $safe = AttachmentName::safe($hostile);
            $this->assertStringNotContainsString('/', $safe);
            $this->assertStringNotContainsString('\\', $safe);
            $this->assertStringNotContainsString('..', $safe);
        }
    }
}
