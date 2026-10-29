<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

/**
 * What Arco would do for one gateway endpoint service of vpc(): the result of GatewayEndpoints::plan(), read-only.
 *
 * `missing` are the environment's route tables the target (or a new endpoint) still has to get. `covering` lists
 * every endpoint of the service, whoever owns it, that already lists at least one of the environment's route tables.
 */
final readonly class EndpointPlan
{
    /** The route tables are served already: nothing to change (an Arco endpoint among them is still `target`, for the usage tag). */
    public const NONE = 'none';
    /** Add the missing route tables to `target`. */
    public const ASSOCIATE = 'associate';
    /** There is no endpoint of the service in the VPC: create one with all the route tables. */
    public const CREATE = 'create';
    /** Only endpoints that Arco does not own exist: the user decides which one to use. */
    public const DECIDE = 'decide';

    /**
     * @param list<string> $routeTables the route tables the subnets of vpc() use
     * @param list<string> $missing
     * @param list<string> $userEndpoints the endpoints of the service in the VPC that Arco does not own
     * @param list<array{id: string, arco: bool, uses: int}> $covering
     */
    public function __construct(
        public string $service,
        public string $vpcId,
        public array $routeTables,
        public array $missing,
        public string $action,
        public ?string $target,
        public bool $targetIsArco,
        public array $userEndpoints,
        public array $covering,
    ) {
    }

    /** The user chose to add the route tables to this endpoint (a user endpoint, as the answer to DECIDE). */
    public function withAssociate(string $endpointId): self
    {
        $arco = false;
        foreach ($this->covering as $endpoint) {
            if ($endpoint['id'] === $endpointId) {
                $arco = $endpoint['arco'];
            }
        }
        return new self($this->service, $this->vpcId, $this->routeTables, $this->missing, self::ASSOCIATE, $endpointId, $arco, $this->userEndpoints, $this->covering);
    }

    /** The user chose not to touch any endpoint; `missing` stays for the warning. */
    public function withSkip(): self
    {
        return new self($this->service, $this->vpcId, $this->routeTables, $this->missing, self::NONE, null, false, $this->userEndpoints, $this->covering);
    }

    /** The vpc() line that names the endpoint, to paste in the environment. */
    public function configLine(): string
    {
        return "endpoints: ['{$this->service}' => '" . ($this->target ?? 'vpce-...') . "']";
    }

    /** The command that adds the missing route tables to an endpoint by hand. */
    public function awsCommand(string $endpointId, string $region): string
    {
        return "aws ec2 modify-vpc-endpoint --region {$region} --vpc-endpoint-id {$endpointId} --add-route-table-ids " . implode(' ', $this->missing);
    }
}
