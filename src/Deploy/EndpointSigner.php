<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

use Aws\Credentials\CredentialsInterface;
use Aws\Signature\SignatureV4;
use Closure;
use Multiapi\Arco\Aws\AwsClients;
use Psr\Http\Message\RequestInterface;

/**
 * Signs requests to an IamAuth Web Function endpoint with the deploy credentials: SigV4, service
 * `lambda`, the endpoint's region (HomeRegion endpoints), and the X-Amz-Content-SHA256 header Lambda
 * requires on every signed request, even without a body.
 */
final class EndpointSigner
{
    /** @param Closure(): CredentialsInterface $credentials */
    public function __construct(private readonly Closure $credentials)
    {
    }

    /** The credentials the deploy's Lambda calls use (profile, SSO, assumed role or environment). */
    public static function for(AwsClients $aws): self
    {
        return new self(static function () use ($aws): CredentialsInterface {
            $credentials = $aws->lambdaWeb()->getCredentials()->wait();
            if (! $credentials instanceof CredentialsInterface) {
                throw new \UnexpectedValueException('The deploy profile resolved no AWS credentials to sign the endpoint probe with.');
            }
            return $credentials;
        });
    }

    public function sign(RequestInterface $request, string $region): RequestInterface
    {
        $request = $request->withHeader(SignatureV4::AMZ_CONTENT_SHA256_HEADER, hash('sha256', (string) $request->getBody()));
        return (new SignatureV4('lambda', $region))->signRequest($request, ($this->credentials)());
    }
}
