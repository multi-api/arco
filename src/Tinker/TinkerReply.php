<?php declare(strict_types=1);

namespace Multiapi\Arco\Tinker;

use Multiapi\Arco\Console\CommandResult;

/** arco:tinker's answer to one statement, read from the console function's output. */
final readonly class TinkerReply
{
    /** No readable ARCO-TINKER line: arco-laravel missing, artisan failing to boot, arco:tinker failing before its reply, or other output. */
    public const NO_REPLY = 'Arco.NoReply';

    /**
     * @param list<string> $dropped variables that could not be kept between statements
     * @param array{class: string, message: string}|null $exception
     */
    public function __construct(
        public string $output,
        public ?string $presented,
        public ?string $context,
        public array $dropped,
        public ?array $exception,
        /** output outside the result line (warnings, deprecations) */
        public string $extra = '',
        /** whether arco:tinker answered; false on crashes and foreign output */
        public bool $replied = true,
        /** the console command's exit code (shown when arco:tinker did not answer) */
        public int $exitCode = 0,
        /** @var list<string> last lines of the function log when the invocation crashed (Sandbox.Timedout, ...) */
        public array $logTail = [],
    ) {
    }

    public static function parse(CommandResult $result): self
    {
        if ($result->crashed()) {
            return new self('', null, null, [], ['class' => (string) $result->errorType, 'message' => (string) $result->errorMessage], '', false, $result->exitCode, $result->logTail);
        }
        $data = null;
        $extra = [];
        foreach (preg_split('/\R/', $result->output) ?: [] as $line) {
            if (str_starts_with($line, TinkerSession::MARKER)) {
                $decoded = json_decode((string) base64_decode(substr($line, strlen(TinkerSession::MARKER)), true), true);
                $data = is_array($decoded) ? $decoded : null;
                continue;
            }
            $extra[] = $line;
        }
        $text = trim(implode("\n", $extra));
        if ($data === null) {
            // e.g. restore() or present() threw before the line was printed: keep the raw output.
            return new self('', null, null, [], ['class' => self::NO_REPLY, 'message' => $text], '', false, $result->exitCode);
        }

        $raw = $data['exception'] ?? null;
        $exception = is_array($raw) ? ['class' => self::text($raw['class'] ?? null), 'message' => self::text($raw['message'] ?? null)] : null;
        $dropped = array_values(array_filter(is_array($data['dropped'] ?? null) ? $data['dropped'] : [], 'is_string'));
        return new self(
            self::text($data['output'] ?? null),
            is_string($data['presented'] ?? null) ? $data['presented'] : null,
            is_string($data['context'] ?? null) ? $data['context'] : null,
            $dropped,
            $exception,
            $text,
            true,
            $result->exitCode,
        );
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
