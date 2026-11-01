<?php

declare(strict_types=1);

namespace HamadaEmamTech\Einvoice\Eta\Tests\Fixtures;

/** Runs the openssl CLI on a DER CMS and its detached content, so our signatures are checked by OpenSSL, not by us. */
final class OpenSsl
{
    /**
     * @param list<string> $command
     *
     * @return array{bool, string} success, and the CLI output plus anything written to -out
     */
    public static function cms(string $cms, string $content, array $command): array
    {
        $dir = sys_get_temp_dir() . '/eta-cades-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("{$dir}/sig.der", $cms);
        file_put_contents("{$dir}/content.txt", $content);
        $args = array_merge(['openssl'], $command, ['-inform', 'DER', '-in', "{$dir}/sig.der", '-content', "{$dir}/content.txt", '-out', "{$dir}/out"]);
        exec(implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $lines, $code);
        $printed = is_file("{$dir}/out") ? (string) file_get_contents("{$dir}/out") : '';
        array_map('unlink', glob("{$dir}/*") ?: []);
        rmdir($dir);

        return [$code === 0, implode("\n", $lines) . $printed];
    }

    public static function verifies(string $cms, string $content): bool
    {
        return self::cms($cms, $content, ['cms', '-verify', '-binary', '-noverify'])[0];
    }
}
