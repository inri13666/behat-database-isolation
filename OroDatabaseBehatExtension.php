<?php

namespace Oro\BehatExtension\DatabaseBehatExtension;

use Behat\Symfony2Extension\ServiceContainer\Symfony2Extension;
use Behat\Testwork\ServiceContainer\Extension;
use Behat\Testwork\ServiceContainer\ExtensionManager;

use Doctrine\DBAL\Connection;

use Oro\Component\Database\Model\DatabaseConfigurationModel;

use Symfony\Bridge\Doctrine\ManagerRegistry;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpKernel\KernelInterface;

class OroDatabaseBehatExtension implements Extension
{
    const MYSQL_NODE = 'mysql';
    const MYSQL_BIN_NODE = 'mysql';
    const MYSQLDUMP_BIN_NODE = 'mysqldump';

    const POSTGRESQL_NODE = 'postgresql';
    const PSQL_BIN_NODE = 'psql';
    const CREATEDB_BIN_NODE = 'createdb';
    const DROPDB_BIN_NODE = 'dropdb';

    const DOCTRINE_CONNECTIONS_NODE = 'doctrine_connections';
    const TIMEOUT_NODE = 'timeout';
    const ORO_LEGACY_NODE = 'oro_legacy';

    /** @var array */
    protected $config = [];

    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container)
    {
        $this->processDoctrineConnections($container, $this->config);
        if ($container->getParameter(self::ORO_LEGACY_NODE)) {
            $this->removeOroLegacy($container);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getConfigKey()
    {
        return 'oro_db_extension';
    }

    /**
     * {@inheritdoc}
     */
    public function initialize(ExtensionManager $extensionManager)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function configure(ArrayNodeDefinition $builder)
    {
        $builder
            ->children()
                ->booleanNode(self::ORO_LEGACY_NODE)->defaultFalse()->end()
                ->arrayNode(self::DOCTRINE_CONNECTIONS_NODE)
                    ->prototype('scalar')->end()
                    ->info('Doctrine\'s connections to be isolated')
                    ->defaultValue(['default'])
                ->end()
                ->arrayNode(self::MYSQL_NODE)
                    ->addDefaultsIfNotSet()
                    ->info('Binaries paths for MySQL')
                    ->children()
                        ->scalarNode(self::MYSQL_BIN_NODE)
                            ->defaultValue('mysql')
                        ->end()
                        ->scalarNode(self::MYSQLDUMP_BIN_NODE)
                            ->defaultValue('mysqldump')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode(self::POSTGRESQL_NODE)
                    ->info('Binaries paths for PostgreSQL')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode(self::CREATEDB_BIN_NODE)
                            ->defaultValue('createdb')
                        ->end()
                        ->scalarNode(self::DROPDB_BIN_NODE)
                            ->defaultValue('dropdb')
                        ->end()
                        ->scalarNode(self::PSQL_BIN_NODE)
                            ->defaultValue('psql')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * {@inheritdoc}
     */
    public function load(ContainerBuilder $container, array $config)
    {
        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__ . '/Resources/config'));
        $loader->load('services.yml');

        $this->config = $config;
        $isLegacy = $config[self::ORO_LEGACY_NODE];
        $container->setParameter(self::ORO_LEGACY_NODE, $isLegacy);

        if ($isLegacy) {
            $loader->load('legacy_services.yml');

            if ($container->hasDefinition('oro_db_extension.isolation.test_isolation_subscriber')) {
                $container->removeDefinition('oro_db_extension.isolation.test_isolation_subscriber');
            }
        }

        $this->loadIsolators($container, $config);
    }

    /**
     * @param ContainerBuilder $container
     * @param array $config
     */
    protected function loadIsolators(ContainerBuilder $container, array $config)
    {
        if ($container->hasDefinition('oro_database.engine.pdo_mysql')) {
            $mysqlConfig = $config[self::MYSQL_NODE];
            $definition = $container->getDefinition('oro_database.engine.pdo_mysql');
            $definition->addMethodCall('setMysqlBin', [$mysqlConfig[self::MYSQL_BIN_NODE]]);
            $definition->addMethodCall('setMysqlDumpBin', [$mysqlConfig[self::MYSQLDUMP_BIN_NODE]]);
        }

        if ($container->hasDefinition('oro_database.engine.pdo_pgsql')) {
            $pgsqlConfig = $config[self::POSTGRESQL_NODE];
            $definition = $container->getDefinition('oro_database.engine.pdo_pgsql');
            $definition->addMethodCall('setDropdbBin', [$pgsqlConfig[self::DROPDB_BIN_NODE]]);
            $definition->addMethodCall('setCreatedbBin', [$pgsqlConfig[self::CREATEDB_BIN_NODE]]);
            $definition->addMethodCall('setPsqlBin', [$pgsqlConfig[self::PSQL_BIN_NODE]]);
        }
    }

    /**
     * @param ContainerBuilder $container
     * @param array $config
     */
    protected function processDoctrineConnections(ContainerBuilder $container, array $config)
    {
        $connections = [];
        /** @var KernelInterface $appKernel */
        $appKernel = $container->get(Symfony2Extension::KERNEL_ID);
        $appKernel->registerBundles();

        if ($appKernel->getContainer()->has('doctrine')) {
            /** @var ManagerRegistry $registry */
            $registry = $appKernel->getContainer()->get('doctrine');
            foreach ($config[self::DOCTRINE_CONNECTIONS_NODE] as $connectionName) {
                /** @var Connection $doctrineConnection */
                $doctrineConnection = $registry->getConnection($connectionName);
                $connection = new DatabaseConfigurationModel(
                    $doctrineConnection->getDriver()->getName(),
                    $doctrineConnection->getHost(),
                    $doctrineConnection->getPort(),
                    $doctrineConnection->getUsername(),
                    $doctrineConnection->getPassword(),
                    $doctrineConnection->getDatabase(),
                    isset($config[self::TIMEOUT_NODE]) ? $config[self::TIMEOUT_NODE] : 240
                );
                $connections[$connectionName] = $connection;
            }
        }

        $installed = $appKernel->getContainer()->hasParameter('installed');

        if (!$container->getParameter(self::ORO_LEGACY_NODE)) {
            $definition = $container->getDefinition('oro_db_extension.isolation.test_isolation_subscriber');
            $definition->replaceArgument(1, $connections);
            $definition->replaceArgument(2, $installed ? $appKernel->getContainer()->getParameter('installed') : null);
        } else {
            $definition = $container->getDefinition('oro_behat.extension.isolation.database');
            $definition->replaceArgument(1, $installed ? $appKernel->getContainer()->getParameter('installed') : 'ORO');
        }
    }

    protected function removeOroLegacy(ContainerBuilder $container)
    {
        $legacyIsolators = [
            'oro_behat_extension.isolation.windows_mysql_isolator',
            'oro_behat_extension.isolation.unix_mysql_simple_isolator',
            'oro_behat_extension.isolation.unix_pgsql_isolator',
        ];

        foreach ($legacyIsolators as $isolator) {
            if ($container->has($isolator)) {
                $container->removeDefinition($isolator);
            }
        }
    }
}
