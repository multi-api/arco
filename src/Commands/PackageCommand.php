<?php declare(strict_types=1);

namespace Multiapi\Arco\Commands;

use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Build\PresetRegistry;
use Multiapi\Arco\Cli\ArcoCommand;
use Multiapi\Arco\Cli\StepPrinter;
use Multiapi\Arco\Cli\UserError;
use Multiapi\Arco\Commands\Concerns\ResolvesTarget;
use Multiapi\Arco\Deploy\DeployPipeline;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'package', description: 'Build the deployment package without deploying it')]
final class PackageCommand extends ArcoCommand
{
    use ResolvesTarget;

    public function __construct(private readonly ?DeployPipeline $pipeline = null, private readonly ?PresetRegistry $presets = null)
    {
        $this->awsFactory = fn (?string $profile, string $region) => new AwsClients($profile, $region);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('env', 'e', InputOption::VALUE_REQUIRED, 'Environment to package')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Where to write the zip');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $steps = new StepPrinter($io, $this->t(...), $input->isInteractive() && $output->isDecorated());
        try {
            $config = $this->loadTarget($input, $io);
            $preset = ($this->presets ?? PresetRegistry::default())->detect($this->projectDir());
            $target = $input->getOption('output');
            $target = is_string($target) ? $target : $this->projectDir() . "/.arco/build/arco-{$config->functionName()}.zip";
            $inside = self::insideProject($this->projectDir(), $target);
            [$build, $package] = ($this->pipeline ?? DeployPipeline::default())->package(
                $config,
                $preset,
                $this->projectDir(),
                $steps,
                // A zip written into the project must not end up in the next package.
                $inside === null ? [] : [$inside],
            );
            if (! is_dir(dirname($target))) {
                @mkdir(dirname($target), 0777, true);
            }
            try {
                if (! @copy($package->path, $target)) {
                    throw new UserError($this->t('package.copy_failed', ['path' => $target]));
                }
            } finally {
                $build->cleanup();
            }
            $io->success($this->t('package.written', ['path' => $target, 'hash' => substr($package->hash, 0, 12)]));
            return self::SUCCESS;
        } catch (Throwable $e) {
            $steps->finish();
            $message = $this->describeError($e);
            if ($message === null) {
                throw $e;
            }
            $io->error($message);
            return self::FAILURE;
        }
    }

    /** The target's path relative to the project when it lies inside it. */
    private static function insideProject(string $projectDir, string $target): ?string
    {
        $absolute = str_starts_with($target, '/') ? $target : getcwd() . '/' . $target;
        $parts = [];
        foreach (explode('/', $absolute) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }
        $absolute = '/' . implode('/', $parts);
        foreach (array_unique([rtrim($projectDir, '/'), rtrim((string) realpath($projectDir), '/')]) as $root) {
            if ($root !== '' && str_starts_with($absolute, $root . '/')) {
                return substr($absolute, strlen($root) + 1);
            }
        }
        return null;
    }
}
