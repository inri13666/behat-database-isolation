<?php

namespace Oro\BehatExtension\DatabaseBehatExtension\Subscriber;

use Behat\Behat\EventDispatcher\Event as BehatEvent;
use Behat\Testwork\EventDispatcher\Event as TestWorkEvent;

use Behat\Testwork\Specification\NoSpecificationsIterator;
use Behat\Testwork\Specification\SpecificationIterator;
use Oro\Component\Database\Model\DatabaseConfigurationModel;
use Oro\Component\Database\Service\DatabaseEngineRegistry;

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class DatabaseIsolationSubscriber implements EventSubscriberInterface
{
    const YES_PATTERN = '/^Y/i';

    /** @var string */
    protected $databaseStateIdentifier;

    /** @var array|DatabaseConfigurationModel[] */
    protected $connections;

    /** @var DatabaseEngineRegistry */
    protected $databaseEngineRegistry;

    /** @var OutputInterface */
    protected $output;

    /** @var InputInterface */
    protected $input;

    /** @var int */
    protected $featuresCount = 0;

    /**
     * DatabaseIsolationSubscriber constructor.
     *
     * @param DatabaseEngineRegistry $databaseEngineRegistry
     * @param array|DatabaseConfigurationModel[] $connections
     * @param null|string $databaseStateIdentifier
     */
    public function __construct(
        DatabaseEngineRegistry $databaseEngineRegistry,
        array $connections = [],
        $databaseStateIdentifier = null
    ) {
        $this->databaseStateIdentifier = md5($databaseStateIdentifier);
        $this->connections = $connections;
        $this->databaseEngineRegistry = $databaseEngineRegistry;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            TestWorkEvent\BeforeExerciseCompleted::BEFORE => ['beforeExercise', 0],
            BehatEvent\AfterFeatureTested::AFTER => ['afterFeature', -100],
            TestWorkEvent\AfterExerciseCompleted::AFTER => ['afterExercise', -100],
        ];
    }

    /**
     * @param TestWorkEvent\BeforeExerciseCompleted $event
     */
    public function beforeExercise(TestWorkEvent\BeforeExerciseCompleted $event)
    {
        $this->countFeatures($event);

        if (!$this->featuresCount) {
            throw new \Exception('No Features found');
        }

        $this->output->writeln('<comment>OroBehatDatabaseExtension taking place</comment>');

        foreach ($this->connections as $key => $connection) {
            $engine = $this->databaseEngineRegistry->findEngine($connection);
            $backupName = $engine->getBackupDbName($this->databaseStateIdentifier, $connection);
            if ($engine->verify($backupName, $connection)) {
                $helper = new QuestionHelper();
                $question = new ConfirmationQuestion(
                    sprintf(
                        '<question>Isolator discover that last time ' .
                        'environment was not restored properly.' . PHP_EOL
                        . 'Do you what to restore the state for "%s" connection?(Y/n)</question>',
                        $key
                    ),
                    true,
                    self::YES_PATTERN
                );

                if ($helper->ask($this->input, $this->output, $question)) {
                    $this->output->writeln(
                        sprintf('Restoring dump for connection "%s"', $key),
                        OutputInterface::VERBOSITY_VERBOSE
                    );
                    $engine->restore($this->databaseStateIdentifier, $connection);
                }

                $engine->drop($backupName, $connection);
            }

            $this->output->writeln(
                sprintf('Taking dump for connection "%s"', $key),
                OutputInterface::VERBOSITY_VERBOSE
            );

            $engine->dump($this->databaseStateIdentifier, $connection);

            $this->output->writeln(
                sprintf('Dump created with name "%s"', $backupName),
                OutputInterface::VERBOSITY_VERBOSE
            );
        }
    }

    /**
     * @param TestWorkEvent\BeforeExerciseCompleted $event
     */
    protected function countFeatures(TestWorkEvent\BeforeExerciseCompleted $event)
    {
        $iterator = $event->getSpecificationIterators();
        /** @var SpecificationIterator $exercise */
        foreach ($iterator as $exercise) {
            if (!$exercise instanceof NoSpecificationsIterator) {
                $this->featuresCount += count($exercise->getSuite()->getSetting('paths'));
            }
        }
    }

    public function afterFeature()
    {
        if (1 < $this->featuresCount) {
            foreach ($this->connections as $key => $connection) {
                $engine = $this->databaseEngineRegistry->findEngine($connection);

                $this->output->writeln(
                    sprintf('Restoring dump for connection "%s"', $key),
                    OutputInterface::VERBOSITY_VERBOSE
                );
                $engine->restore($this->databaseStateIdentifier, $connection);
            }
        }
    }

    public function afterExercise()
    {
        foreach ($this->connections as $key => $connection) {
            $engine = $this->databaseEngineRegistry->findEngine($connection);
            $backupName = $engine->getBackupDbName($this->databaseStateIdentifier, $connection);

            $this->output->writeln(
                sprintf('Dropping dump with name "%s"', $backupName),
                OutputInterface::VERBOSITY_VERBOSE
            );
            $engine->drop($backupName, $connection);
        }
    }

    /**
     * @param OutputInterface $output
     */
    public function setOutput(OutputInterface $output)
    {
        $this->output = $output;
    }

    /**
     * @param InputInterface $input
     */
    public function setInput(InputInterface $input)
    {
        $this->input = $input;
    }
}
