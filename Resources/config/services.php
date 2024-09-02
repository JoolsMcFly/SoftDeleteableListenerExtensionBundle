<?php

use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/** @var \Symfony\Component\DependencyInjection\ContainerBuilder $container */
$container->setDefinition(
    'evence.softdeletale.listener.softdelete',
    new Definition(
        'Evence\Bundle\SoftDeleteableExtensionBundle\EventListener\SoftDeleteListener',
        array(
            new Reference('annotation_reader'),
            new Reference('doctrine.orm.entity_manager'),
        )
    )
)

->addMethodCall('setContainer', array(
    new Reference('service_container'),
))
->addTag('doctrine.event_listener', array(
    'event' => 'preSoftDelete',
));
