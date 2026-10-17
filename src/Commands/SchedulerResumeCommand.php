<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Closure;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Commands\Concerns\ChangesSchedulerState;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'scheduler:resume', description: 'Resume a paused scheduler in AWS now')]
final class SchedulerResumeCommand extends ArcoCommand
{
    use ChangesSchedulerState;

    /** @param (Closure(?string, string): AwsClients)|null $awsFactory */
    public function __construct(?Closure $awsFactory = null)
    {
        $this->awsFactory = $awsFactory ?? fn (?string $profile, string $region) => new AwsClients($profile, $region);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addSchedulerStateOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->changeSchedulerState($input, $output, pause: false);
    }
}
