<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy\Domains;

use Multiapi\Arco\Config\AppConfig;

/** The CloudFormation template that puts an environment's endpoint behind CloudFront. */
final class DomainStack
{
    public const CACHING_DISABLED = '4135ea2d-6df8-44a3-9df3-4b5a84be39ad';
    public const CACHING_OPTIMIZED = '658327ea-f89d-4fab-a63d-7e88639e58f6';
    public const ALL_VIEWER_EXCEPT_HOST_HEADER = 'b689b0a8-53d0-40ab-baf2-68738e2966ac';
    /** "Always sign, preserve viewer authorization"; CloudFront rejects always, never and no-override for Web Function origins. */
    public const SIGNING_BEHAVIOR = 'always-amz-auth';
    private const ORIGIN_ID = 'arco-endpoint';
    private const FUNCTION_CODE = <<<'JS'
        function handler(event) {
          var request = event.request;
          if (request.headers.host) {
            request.headers['x-forwarded-host'] = { value: request.headers.host.value };
          } else {
            delete request.headers['x-forwarded-host'];
          }
          return request;
        }
        JS;

    public static function stackName(AppConfig $config): string
    {
        return substr('arco-' . $config->functionName(), 0, 128);
    }

    /** CloudFront function names are unique per account and at most 64 characters. */
    public static function functionName(AppConfig $config): string
    {
        return self::accountName($config, 'host');
    }

    /** Origin access control names follow the same rules as function names. */
    public static function originAccessName(AppConfig $config): string
    {
        return self::accountName($config, 'oac');
    }

    private static function accountName(AppConfig $config, string $suffix): string
    {
        $name = 'arco-' . $config->functionName() . '-' . $suffix;
        return strlen($name) <= 64 ? $name : substr($name, 0, 55) . '-' . substr(md5($name), 0, 8);
    }

    /**
     * @param list<string> $cachedPaths path patterns CloudFront may cache (CachesPaths)
     * @param bool $originAccess sign origin requests (RevisionFactory::protectsEndpoint()); false only behind an emulator
     * @return array<string, mixed>
     */
    public static function template(AppConfig $config, string $endpointDomain, string $certificateArn, array $cachedPaths, bool $originAccess = true): array
    {
        $function = ['EventType' => 'viewer-request', 'FunctionARN' => ['Fn::GetAtt' => ['ForwardHost', 'FunctionARN']]];
        $distribution = [
            'Enabled' => true,
            'Comment' => "Arco {$config->app}/{$config->environment}",
            'Aliases' => $config->domains,
            'ViewerCertificate' => [
                'AcmCertificateArn' => $certificateArn,
                'SslSupportMethod' => 'sni-only',
                'MinimumProtocolVersion' => 'TLSv1.2_2021',
            ],
            'HttpVersion' => 'http2and3',
            'IPV6Enabled' => true,
            'PriceClass' => 'PriceClass_All',
            'Origins' => [[
                'Id' => self::ORIGIN_ID,
                'DomainName' => $endpointDomain,
                'CustomOriginConfig' => [
                    'OriginProtocolPolicy' => 'https-only',
                    'HTTPSPort' => 443,
                    'OriginSSLProtocols' => ['TLSv1.2'],
                    'OriginReadTimeout' => min($config->timeout + 2, 60),
                    'OriginKeepaliveTimeout' => 5,
                ],
            ]],
            'DefaultCacheBehavior' => [
                'TargetOriginId' => self::ORIGIN_ID,
                'ViewerProtocolPolicy' => 'redirect-to-https',
                'AllowedMethods' => ['GET', 'HEAD', 'OPTIONS', 'PUT', 'PATCH', 'POST', 'DELETE'],
                'CachedMethods' => ['GET', 'HEAD'],
                'CachePolicyId' => self::CACHING_DISABLED,
                'OriginRequestPolicyId' => self::ALL_VIEWER_EXCEPT_HOST_HEADER,
                'Compress' => true,
                'FunctionAssociations' => [$function],
            ],
        ];
        if ($cachedPaths !== []) {
            $distribution['CacheBehaviors'] = array_map(fn (string $path) => [
                'PathPattern' => $path,
                'TargetOriginId' => self::ORIGIN_ID,
                'ViewerProtocolPolicy' => 'redirect-to-https',
                'AllowedMethods' => ['GET', 'HEAD'],
                'CachedMethods' => ['GET', 'HEAD'],
                'CachePolicyId' => self::CACHING_OPTIMIZED,
                'Compress' => true,
                'FunctionAssociations' => [$function],
            ], $cachedPaths);
        }

        $forwardHost = [
            'Type' => 'AWS::CloudFront::Function',
            'Properties' => [
                'Name' => self::functionName($config),
                'AutoPublish' => true,
                'FunctionCode' => self::FUNCTION_CODE,
                'FunctionConfig' => [
                    'Comment' => 'Arco: pass the viewer host to the app as X-Forwarded-Host',
                    'Runtime' => 'cloudfront-js-2.0',
                ],
            ],
        ];
        $resources = ['ForwardHost' => $forwardHost];
        if ($originAccess) {
            // Signs every origin request: with domain() the endpoint is IamAuth and only this distribution may invoke it.
            $distribution['Origins'][0]['OriginAccessControlId'] = ['Fn::GetAtt' => ['OriginAccess', 'Id']];
            $resources['OriginAccess'] = self::originAccess($config);
        }
        $resources['Distribution'] = ['Type' => 'AWS::CloudFront::Distribution', 'Properties' => ['DistributionConfig' => $distribution]];

        return [
            'AWSTemplateFormatVersion' => '2010-09-09',
            'Description' => "Arco custom domains for {$config->app}/{$config->environment}",
            'Resources' => $resources,
            'Outputs' => [
                'DistributionId' => ['Value' => ['Ref' => 'Distribution']],
                'DistributionDomain' => ['Value' => ['Fn::GetAtt' => ['Distribution', 'DomainName']]],
                // Lets remove find the aliases even after domain() changed in arco.php.
                'Aliases' => ['Value' => implode(',', $config->domains)],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function originAccess(AppConfig $config): array
    {
        return [
            'Type' => 'AWS::CloudFront::OriginAccessControl',
            'Properties' => [
                'OriginAccessControlConfig' => [
                    'Name' => self::originAccessName($config),
                    'Description' => "Arco: signs requests to {$config->app}/{$config->environment}",
                    'OriginAccessControlOriginType' => 'lambda',
                    // The only behaviour Web Function origins accept: signs, and keeps the viewer's Authorization.
                    'SigningBehavior' => self::SIGNING_BEHAVIOR,
                    // HomeRegion endpoints are regional: SigV4, not SigV4a.
                    'SigningProtocol' => 'sigv4',
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $template */
    public static function body(array $template): string
    {
        return json_encode($template, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
