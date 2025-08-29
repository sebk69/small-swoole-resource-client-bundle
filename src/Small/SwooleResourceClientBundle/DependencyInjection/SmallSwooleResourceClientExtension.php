<?php
declare(strict_types=1);

namespace Small\SwooleResourceClientBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

/**
 * @codeCoverageIgnore
 */
final class SmallSwooleResourceClientExtension extends Extension
{

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('small_swoole_resource_client.server_uri', $config['server_uri']);
        $container->setParameter('small_swoole_resource_client.api_key', $config['api_key']);

        // Register the Factory service
        $container->register('small_swoole_resource_client.factory', \Small\SwooleResourceClientBundle\Resource\Factory::class)
            ->setPublic(true)
            ->setArgument(0, '%small_swoole_resource_client.server_uri%')
            ->setArgument(1, '%small_swoole_resource_client.api_key%');
    }

    public function getAlias(): string
    {
        return 'small_swoole_resource_client';
    }
}
