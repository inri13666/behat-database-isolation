<?php

namespace Oro\BehatExtension\DatabaseBehatExtension\Legacy;

use Behat\Testwork\EventDispatcher\Event\BeforeExerciseCompleted;
use Behat\Testwork\Specification\NoSpecificationsIterator;
use Behat\Testwork\Specification\SpecificationIterator;
use Oro\Bundle\TestFrameworkBundle\Behat\Isolation;
use Oro\Component\Database\Engine\DatabaseEngineInterface;
use Oro\Component\Database\Model\DatabaseConfigurationModel;
use Oro\Component\Database\Service\DatabaseEngineRegistry;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OroDatabaseBehatIsolator implements Isolation\IsolatorInterface, EventSubscriberInterface
{
    use ContainerAwareTrait;

    /** @var DatabaseEngineRegistry */
    protected $databaseEngineRegistry;

    /** @var DatabaseConfigurationModel */
    protected $configuration;

    /** @var string */
    protected $sid;

    /** @var DatabaseEngineInterface */
    protected $engine;

    /** @var int */
    protected $featuresCount = 0;

    /**
     * @param DatabaseEngineRegistry $databaseEngineRegistry
     * @param $installed
     */
    public function __construct(DatabaseEngineRegistry $databaseEngineRegistry, $installed)
    {
        $this->databaseEngineRegistry = $databaseEngineRegistry;
        $this->sid = md5($installed);
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            BeforeExerciseCompleted::BEFORE => ['countFeatures', 5],
        ];
    }

    /**
     * @param BeforeExerciseCompleted $event
     */
    public function countFeatures(BeforeExerciseCompleted $event)
    {
        $iterator = $event->getSpecificationIterators();
        /** @var SpecificationIterator $exercise */
        foreach ($iterator as $exercise) {
            if (!$exercise instanceof NoSpecificationsIterator) {
                $this->featuresCount += count($exercise->getSuite()->getSetting('paths'));
            }
        }
    }

    /**
     * @return DatabaseConfigurationModel
     */
    protected function getConfiguration()
    {
        $container = $this->container;
        if (!$this->configuration) {
            $this->configuration = new DatabaseConfigurationModel();
            $this->configuration
                ->setDriver($container->getParameter('database_driver'))
                ->setHost($container->getParameter('database_host'))
                ->setPort($container->getParameter('database_port'))
                ->setDbName($container->getParameter('database_name'))
                ->setUser($container->getParameter('database_user'))
                ->setPassword($container->getParameter('database_password'));
        }

        return $this->configuration;
    }

    /**
     * @return DatabaseEngineInterface
     */
    protected function findCurrentDatabaseEngine()
    {
        if (!$this->engine) {
            $this->engine = $this->databaseEngineRegistry->findEngine($this->getConfiguration());
        }

        return $this->engine;
    }

    /**
     * {@inheritdoc}
     */
    public function start(Isolation\Event\BeforeStartTestsEvent $event)
    {
        $event->writeln('<info>[OroDatabaseBehatIsolator] Dumping current application database</info>');
        $this->findCurrentDatabaseEngine()->dump($this->sid, $this->getConfiguration());
        $event->writeln('<info>[OroDatabaseBehatIsolator] Dump created</info>');
    }

    /**
     * {@inheritdoc}
     */
    public function beforeTest(Isolation\Event\BeforeIsolatedTestEvent $event)
    {
        // Do Nothing
    }

    /**
     * {@inheritdoc}
     */
    public function afterTest(Isolation\Event\AfterIsolatedTestEvent $event)
    {
        if (1 < $this->featuresCount) {
            $event->writeln('<info>[OroDatabaseBehatIsolator] Restoring dump database before next feature</info>');
            $this->findCurrentDatabaseEngine()->restore($this->sid, $this->getConfiguration());
            $event->writeln('<info>[OroDatabaseBehatIsolator] finished</info>');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function terminate(Isolation\Event\AfterFinishTestsEvent $event)
    {
        $event->writeln('<info>[OroDatabaseBehatIsolator] Restoring dump kernel cache</info>');
        $config = $this->getConfiguration();
        $isolator = $this->findCurrentDatabaseEngine();
        $isolator->restore($this->sid, $config);
        $isolator->drop($isolator->getBackupDbName($this->sid, $config), $config);
        $event->writeln('<info>[OroDatabaseBehatIsolator] finished</info>');
    }

    /**
     * {@inheritdoc}
     */
    public function isApplicable(ContainerInterface $container)
    {
        $this->setContainer($container);
        try {
            $this->findCurrentDatabaseEngine();

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function restoreState(Isolation\Event\RestoreStateEvent $event)
    {
        $config = $this->getConfiguration();
        $isolator = $this->findCurrentDatabaseEngine();
        $event->writeln('<info>Begin to restore the state of Db...</info>');
        if ($isolator->verify($isolator->getBackupDbName($this->sid, $config), $config)) {
            $event->writeln('<info>Drop/Create Db</info>');
            $isolator->restore($this->sid, $config);
            $isolator->drop(
                $isolator->getBackupDbName($this->sid, $config),
                $config
            );
            $event->writeln('<info>Db was restored from dump</info>');
        } else {
            $event->writeln('<info>Db was not restored from dump</info>');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isOutdatedState()
    {
        $isolator = $this->findCurrentDatabaseEngine();

        $config = $this->getConfiguration();

        return $isolator->verify($isolator->getBackupDbName($this->sid, $config), $config);
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return "OroDatabaseBehatIsolator";
    }

    /**
     * {@inheritdoc}
     */
    public function getTag()
    {
        return 'database';
    }
}
