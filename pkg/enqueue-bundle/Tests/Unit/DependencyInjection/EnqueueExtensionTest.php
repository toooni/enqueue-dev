<?php

namespace Enqueue\Bundle\Tests\Unit\DependencyInjection;

use Enqueue\Bundle\DependencyInjection\Configuration;
use Enqueue\Bundle\DependencyInjection\EnqueueExtension;
use Enqueue\Bundle\Tests\Unit\Mocks\FooTransportFactory;
use Enqueue\Bundle\Tests\Unit\Mocks\TransportFactoryWithoutDriverFactory;
use Enqueue\Client\Producer;
use Enqueue\Client\TraceableProducer;
use Enqueue\Null\NullContext;
use Enqueue\Null\Symfony\NullTransportFactory;
use Enqueue\Symfony\DefaultTransportFactory;
use Enqueue\Symfony\TransportFactoryInterface;
use Enqueue\Test\ClassExtensionTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class EnqueueExtensionTest extends TestCase
{
    use ClassExtensionTrait;

    public function testShouldImplementConfigurationInterface()
    {
        self::assertClassExtends(Extension::class, EnqueueExtension::class);
    }

    public function testCouldBeConstructedWithoutAnyArguments()
    {
        new EnqueueExtension();
    }

    public function testShouldRegisterDefaultAndNullTransportFactoriesInConstructor()
    {
        $extension = new EnqueueExtension();

        /** @var TransportFactoryInterface[] $factories */
        $factories = $this->readAttribute($extension, 'factories');

        $this->assertInternalType('array', $factories);
        $this->assertCount(2, $factories);

        $this->assertArrayHasKey('default', $factories);
        $this->assertInstanceOf(DefaultTransportFactory::class, $factories['default']);
        $this->assertEquals('default', $factories['default']->getName());

        $this->assertArrayHasKey('null', $factories);
        $this->assertInstanceOf(NullTransportFactory::class, $factories['null']);
        $this->assertEquals('null', $factories['null']->getName());
    }

    public function testThrowIfTransportFactoryNameEmpty()
    {
        $extension = new EnqueueExtension();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Transport factory name cannot be empty');

        $extension->addTransportFactory(new FooTransportFactory(null));
    }

    public function testThrowIfTransportFactoryWithSameNameAlreadyAdded()
    {
        $extension = new EnqueueExtension();

        $extension->addTransportFactory(new FooTransportFactory('foo'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Transport factory with such name already added. Name foo');

        $extension->addTransportFactory(new FooTransportFactory('foo'));
    }

    public function testShouldEnabledNullTransportAndSetItAsDefault()
    {
        $container = new ContainerBuilder();

        $extension = new EnqueueExtension();

        $extension->load([[
            'transport' => [
                'default' => 'null',
                'null' => true,
            ],
        ]], $container);

        self::assertTrue($container->hasAlias('enqueue.transport.default.context'));
        self::assertEquals('enqueue.transport.null.context', (string) $container->getAlias('enqueue.transport.default.context'));

        self::assertTrue($container->hasDefinition('enqueue.transport.null.context'));
        $context = $container->getDefinition('enqueue.transport.null.context');
        self::assertEquals(NullContext::class, $context->getClass());
    }

    public function testShouldUseNullTransportAsDefaultWhenExplicitlyConfigured()
    {
        $container = new ContainerBuilder();

        $extension = new EnqueueExtension();

        $extension->load([[
            'transport' => [
                'default' => 'null',
                'null' => true,
            ],
        ]], $container);

        self::assertEquals(
            'enqueue.transport.default.context',
            (string) $container->getAlias('enqueue.transport.context')
        );
        self::assertEquals(
            'enqueue.transport.null.context',
            (string) $container->getAlias('enqueue.transport.default.context')
        );
    }

    public function testShouldConfigureFooTransport()
    {
        $container = new ContainerBuilder();

        $extension = new EnqueueExtension();
        $extension->addTransportFactory(new FooTransportFactory());

        $extension->load([[
            'transport' => [
                'foo' => ['foo_param' => 'aParam'],
            ],
        ]], $container);

        self::assertTrue($container->hasDefinition('foo.connection_factory'));
        self::assertTrue($container->hasDefinition('foo.context'));
        self::assertFalse($container->hasDefinition('foo.driver'));

        $context = $container->getDefinition('foo.context');
        self::assertEquals(\stdClass::class, $context->getClass());
        self::assertEquals([['foo_param' => 'aParam']], $context->getArguments());
    }

    public function testShouldUseFooTransportAsDefault()
    {
        $container = new ContainerBuilder();

        $extension = new EnqueueExtension();
        $extension->addTransportFactory(new FooTransportFactory());

        $extension->load([[
            'transport' => [
                'default' => 'foo',
                'foo' => ['foo_param' => 'aParam'],
            ],
        ]], $container);

        self::assertEquals(
            'enqueue.transport.default.context',
            (string) $container->getAlias('enqueue.transport.context')
        );
        self::assertEquals(
            'enqueue.transport.foo.context',
            (string) $container->getAlias('enqueue.transport.default.context')
        );
    }

    public function testShouldLoadClientServicesWhenEnabled()
    {
        $container = new ContainerBuilder();

        $extension = new EnqueueExtension();
        $extension->addTransportFactory(new FooTransportFactory());

        $extension->load([[
            'client' => null,
            'transport' => [
                'default' => 'foo',
                'foo' => [
                    'foo_param' => true,
                ],
            ],
        ]], $container);

        self::assertTrue($container->hasDefinition('foo.driver'));
        self::assertTrue($container->hasDefinition('enqueue.client.config'));
        self::assertTrue($container->hasDefinition('enqueue.client.producer'));
    }

    public function testShouldNotCreateDriverIfFactoryDoesNotImplementDriverFactoryInterface()
    {
        $container = new ContainerBuilder();

        $extension = new EnqueueExtension();
        $extension->addTransportFactory(new TransportFactoryWithoutDriverFactory());

        $extension->load([[
            'client' => null,
            'transport' => [
                'default' => 'without_driver',
                'without_driver' => [],
            ],
        ]], $container);

        self::assertTrue($container->hasDefinition('without_driver.context'));
        self::assertTrue($container->hasDefinition('without_driver.connection_factory'));
        self::assertFalse($container->hasDefinition('without_driver.driver'));
    }

    public function testShouldUseProducerByDefault()
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);

        $extension = new EnqueueExtension();
        $extension->addTransportFactory(new FooTransportFactory());

        $extension->load([[
            'client' => null,
            'transport' => [
                'default' => 'foo',
                'foo' => [
                    'foo_param' => true,
                ],
            ],
        ]], $container);

        $producer = $container->getDefinition('enqueue.client.producer');
        self::assertEquals(Producer::class, $producer->getClass());
    }

    public function testShouldUseMessageProducerIfTraceableProducerOptionSetToFalseExplicitly()
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);

        $extension = new EnqueueExtension();
        $extension->addTransportFactory(new FooTransportFactory());

        $extension->load([[
            'client' => [
                'traceable_producer' => false,
            ],
            'transport' => [
                'default' => 'foo',
                'foo' => [
                    'foo_param' => true,
                ],
            ],
        ]], $container);

        $producer = $container->getDefinition('enqueue.client.producer');
        self::assertEquals(Producer::class, $producer->getClass());
    }

    public function testShouldUseTraceableMessageProducerIfTraceableProducerOptionSetToTrueExplicitly()
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', true);

        $extension = new EnqueueExtension();
        $extension->addTransportFactory(new FooTransportFactory());

        $extension->load([[
            'client' => [
                'traceable_producer' => true,
            ],
            'transport' => [
                'default' => 'foo',
                'foo' => [
                    'foo_param' => true,
                ],
            ],
        ]], $container);

        $producer = $container->getDefinition('enqueue.client.traceable_producer');
        self::assertEquals(TraceableProducer::class, $producer->getClass());
        self::assertEquals(
            ['enqueue.client.producer', null, 0],
            $producer->getDecoratedService()
        );

        self::assertInstanceOf(Reference::class, $producer->getArgument(0));
        self::assertEquals(
            'enqueue.client.traceable_producer.inner',
            (string) $producer->getArgument(0)
        );
    }

    public function testShouldLoadDelayRedeliveredMessageExtensionIfRedeliveredDelayTimeGreaterThenZero()
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', true);

        $extension = new EnqueueExtension();
        $extension->addTransportFactory(new FooTransportFactory());

        $extension->load([[
            'transport' => [
                'default' => 'foo',
                'foo' => [
                    'foo_param' => true,
                ],
            ],
            'client' => [
                'redelivered_delay_time' => 12345,
            ],
        ]], $container);

        $extension = $container->getDefinition('enqueue.client.delay_redelivered_message_extension');

        self::assertEquals(12345, $extension->getArgument(1));
    }

    public function testShouldNotLoadDelayRedeliveredMessageExtensionIfRedeliveredDelayTimeIsZero()
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', true);

        $extension = new EnqueueExtension();
        $extension->addTransportFactory(new FooTransportFactory());

        $extension->load([[
            'transport' => [
                'default' => 'foo',
                'foo' => [
                    'foo_param' => true,
                ],
            ],
            'client' => [
                'redelivered_delay_time' => 0,
            ],
        ]], $container);

        $this->assertFalse($container->hasDefinition('enqueue.client.delay_redelivered_message_extension'));
    }

    public function testShouldLoadJobServicesIfEnabled()
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', true);

        $extension = new EnqueueExtension();

        $extension->load([[
            'transport' => [],
            'job' => true,
        ]], $container);

        self::assertTrue($container->hasDefinition('enqueue.job.runner'));
    }

    public function testShouldNotLoadJobServicesIfDisabled()
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', true);

        $extension = new EnqueueExtension();

        $extension->load([[
            'transport' => [],
            'job' => false,
        ]], $container);

        self::assertFalse($container->hasDefinition('enqueue.job.runner'));
    }

    public function testShouldAllowGetConfiguration()
    {
        $extension = new EnqueueExtension();

        $configuration = $extension->getConfiguration([], new ContainerBuilder());

        self::assertInstanceOf(Configuration::class, $configuration);
    }

    public function testShouldLoadDoctrinePingConnectionExtensionServiceIfEnabled()
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', true);

        $extension = new EnqueueExtension();

        $extension->load([[
            'transport' => [],
            'extensions' => [
                'doctrine_ping_connection_extension' => true,
            ],
        ]], $container);

        self::assertTrue($container->hasDefinition('enqueue.consumption.doctrine_ping_connection_extension'));
    }

    public function testShouldNotLoadDoctrinePingConnectionExtensionServiceIfDisabled()
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', true);

        $extension = new EnqueueExtension();

        $extension->load([[
            'transport' => [],
            'extensions' => [
                'doctrine_ping_connection_extension' => false,
            ],
        ]], $container);

        self::assertFalse($container->hasDefinition('enqueue.consumption.doctrine_ping_connection_extension'));
    }

    public function testShouldLoadDoctrineClearIdentityMapExtensionServiceIfEnabled()
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', true);

        $extension = new EnqueueExtension();

        $extension->load([[
            'transport' => [],
            'extensions' => [
                'doctrine_clear_identity_map_extension' => true,
            ],
        ]], $container);

        self::assertTrue($container->hasDefinition('enqueue.consumption.doctrine_clear_identity_map_extension'));
    }

    public function testShouldNotLoadDoctrineClearIdentityMapExtensionServiceIfDisabled()
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', true);

        $extension = new EnqueueExtension();

        $extension->load([[
            'transport' => [],
            'extensions' => [
                'doctrine_clear_identity_map_extension' => false,
            ],
        ]], $container);

        self::assertFalse($container->hasDefinition('enqueue.consumption.doctrine_clear_identity_map_extension'));
    }

    public function testShouldLoadSignalExtensionServiceIfEnabled()
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', true);

        $extension = new EnqueueExtension();

        $extension->load([[
            'transport' => [],
            'extensions' => [
                'signal_extension' => true,
            ],
        ]], $container);

        self::assertTrue($container->hasDefinition('enqueue.consumption.signal_extension'));
    }

    public function testShouldNotLoadSignalExtensionServiceIfDisabled()
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', true);

        $extension = new EnqueueExtension();

        $extension->load([[
            'transport' => [],
            'extensions' => [
                'signal_extension' => false,
            ],
        ]], $container);

        self::assertFalse($container->hasDefinition('enqueue.consumption.signal_extension'));
    }

    public function testShouldLoadReplyExtensionServiceIfEnabled()
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', true);

        $extension = new EnqueueExtension();

        $extension->load([[
            'transport' => [],
            'extensions' => [
                'reply_extension' => true,
            ],
        ]], $container);

        self::assertTrue($container->hasDefinition('enqueue.consumption.reply_extension'));
    }

    public function testShouldNotLoadReplyExtensionServiceIfDisabled()
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', true);

        $extension = new EnqueueExtension();

        $extension->load([[
            'transport' => [],
            'extensions' => [
                'reply_extension' => false,
            ],
        ]], $container);

        self::assertFalse($container->hasDefinition('enqueue.consumption.reply_extension'));
    }

    public function testShouldAddJobQueueEntityMapping()
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', ['DoctrineBundle' => true]);
        $container->prependExtensionConfig('doctrine', ['dbal' => true]);

        $extension = new EnqueueExtension();

        $extension->prepend($container);

        $config = $container->getExtensionConfig('doctrine');

        $this->assertSame(['dbal' => true], $config[1]);
        $this->assertNotEmpty($config[0]['orm']['mappings']['enqueue_job_queue']);
    }

    public function testShouldNotAddJobQueueEntityMappingIfDoctrineBundleIsNotRegistered()
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', []);

        $extension = new EnqueueExtension();

        $extension->prepend($container);

        $this->assertSame([], $container->getExtensionConfig('doctrine'));
    }
}
