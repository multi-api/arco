<?php declare(strict_types=1);

namespace Multiapi\Arco\Resources;

/** The kinds of AWS resource `arco resources` lists; the value is what the command and the JSON call them. */
enum ResourceType: string
{
    case Subnet = 'subnet';
    case SecurityGroup = 'security-group';
    case Endpoint = 'endpoint';
    case Bucket = 'bucket';
    case Queue = 'queue';
    case Table = 'table';
    case Certificate = 'certificate';
    case Zone = 'zone';
}
