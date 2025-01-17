<?php

declare(strict_types=1);

namespace App\Command;

use PhpAmqpLib\Channel\AMQPChannel;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yiisoft\Yii\Console\ExitCode;
use Yiisoft\Yii\Queue\AMQP\QueueProviderInterface;
use Yiisoft\Yii\Queue\AMQP\Settings\QueueSettingsInterface;
use Yiisoft\Yii\Queue\Cli\LoopInterface;
use Yiisoft\Yii\Queue\Message\Message;
use Yiisoft\Yii\Queue\QueueFactory;
use Yiisoft\Yii\Queue\QueueFactoryInterface;

final class RunSimpleJobCommand extends Command
{
    public static $defaultName = 'benchmark:memory-queue';
    protected static $defaultDescription = 'Run simple jobs';

    public function __construct(
        private readonly QueueFactory $queueFactory,
        private readonly QueueProviderInterface $queueProvider,
        private readonly QueueSettingsInterface $queueSettings,
        private readonly CacheInterface $cache,
    ) {
        parent::__construct();
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $output = new SymfonyStyle($input, $output);

        $this->cache->set('canContinue', false);

        $iteration = 1_000_000;

        $output->writeln('<info>Benchmarking jobs with Yii3 Framework and Amqp</info>');

        $queue = $this->queueFactory->get('amqp');

        $output->writeln(\sprintf('<info>Pushing [%s] jobs...</info>', \number_format($iteration)));
        $bar = $output->createProgressBar($iteration);

        $fiber = new \Fiber(function (int $iteration) use ($queue, $bar) {
            for ($i = 0; $i < $iteration; $i++) {
                $bar->advance();
                $queue->push(new Message(handlerName: 'simple-job', data: ['i' => $i]));
                \Fiber::suspend();
            }
        });

        try {
            $start = \microtime(true);
            $fiber->start($iteration);

            while (!$fiber->isTerminated()) {
                $fiber->resume();
            }

            $bar->finish();
            $output->newLine();

            $output->writeln(\sprintf('<info>Pushed in [%f] seconds</info>', \microtime(true) - $start));
        } catch (\Throwable $e) {
            $output->writeln(\sprintf('<error>%s</error>', $e->getMessage()));
        }

        $this->cache->set('canContinue', true);

        $channel = $this->queueProvider->getChannel();
        $bar = $output->createProgressBar($iteration);

        $start = \microtime(true);
        $consumed = 0;
        while (($queueSize = $this->getQueueSize($channel)) > 0) {
            $bar->advance($iteration - $queueSize - $consumed);
            \usleep(50000);
            $consumed = $iteration - $queueSize;
        }

        $bar->finish();

        $output->newLine();

        $output->write(\sprintf('<info>Processed in [%f] seconds</info>', \microtime(true) - $start));

        $output->newLine();

        return ExitCode::OK;
    }

    private function getQueueSize(AMQPChannel $channel): int
    {
        $declaredQueues = $channel->queue_declare(...$this->queueSettings->getPositionalSettings());

        return $declaredQueues[1] ?? 0;
    }
}
