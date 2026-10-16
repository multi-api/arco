<?php declare(strict_types=1);

namespace Multiapi\Arco\Aws;

use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Throwable;

/** Recognises failures caused by missing or expired AWS credentials, anywhere in the exception chain. */
final class CredentialError
{
    private const CODES = ['ExpiredToken', 'ExpiredTokenException', 'UnrecognizedClientException', 'InvalidClientTokenId'];

    /**
     * True for an expiry code anywhere in the chain, or a CredentialsException with no other AWS error
     * under it. AssumeRoleCredentialProvider wraps every AssumeRole failure (AccessDenied from a trust
     * policy too) in a CredentialsException: those are not expired credentials.
     */
    public static function matches(Throwable $e): bool
    {
        $credentials = false;
        $otherAwsError = false;
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof AwsException) {
                if (in_array($current->getAwsErrorCode(), self::CODES, true)) {
                    return true;
                }
                $otherAwsError = true;
            }
            $credentials = $credentials || $current instanceof CredentialsException;
        }
        return $credentials && ! $otherAwsError;
    }

    /** Whether credentials could not be obtained at all, whatever the reason. */
    public static function isCredentialsFailure(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof CredentialsException) {
                return true;
            }
        }
        return false;
    }

    /** The most useful message: AWS's own, from the first AWS error in the chain, else the exception's. */
    public static function message(Throwable $e): string
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof AwsException) {
                $message = $current->getAwsErrorMessage();
                return is_string($message) && $message !== '' ? $message : $current->getMessage();
            }
        }
        return $e->getMessage();
    }

    /** `aws sso login --profile <p>` when the error or the profile's configuration points to IAM Identity Center. */
    public static function loginCommand(Throwable $e, ?string $profile): ?string
    {
        if ($profile === null) {
            return null;
        }
        $hint = SsoHint::command($e, $profile);
        if ($hint !== null) {
            return $hint;
        }
        [$configPath, $credentialsPath] = ProfileLister::defaultPaths();
        foreach (ProfileLister::list($configPath, $credentialsPath) as $candidate) {
            if ($candidate->name === $profile && $candidate->type === 'sso') {
                return "aws sso login --profile $profile";
            }
        }
        return null;
    }
}
