<?php

namespace Ecotone\Dbal\Recoverability;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Dbal\Configuration\CustomDeadLetterGateway;
use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\Configuration\DbalModule;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ConsoleCommandModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeaderBuilder;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Enqueue\Dbal\DbalConnectionFactory;

#[ModuleAnnotation]
class DbalDeadLetterModule implements AnnotationModule
{
    public const HELP_COMMAND_NAME = 'ecotone:deadletter:help';
    public const LIST_COMMAND_NAME            = 'ecotone:deadletter:list';
    public const SHOW_COMMAND_NAME       = 'ecotone:deadletter:show';
    public const REPLAY_COMMAND_NAME     = 'ecotone:deadletter:replay';
    public const REPLAY_ALL_COMMAND_NAME = 'ecotone:deadletter:replayAll';
    public const DELETE_COMMAND_NAME     = 'ecotone:deadletter:delete';

    /**
     * @inheritDoc
     */
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    /**
     * @inheritDoc
     */
    public function prepare(Configuration $configuration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $isDeadLetterEnabled = false;
        $connectionFactoryReference     = DbalConnectionFactory::class;
        $customDeadLetterGateways = [];
        foreach ($extensionObjects as $extensionObject) {
            if ($extensionObject instanceof CustomDeadLetterGateway) {
                $customDeadLetterGateways[] = $extensionObject;
            }
            if ($extensionObject instanceof DbalConfiguration) {
                if (! $extensionObject->isDeadLetterEnabled()) {
                    return;
                }

                $connectionFactoryReference     = $extensionObject->getDeadLetterConnectionReference();
                $isDeadLetterEnabled = true;
            }
        }

        if (! $isDeadLetterEnabled) {
            return;
        }

        $this->registerOneTimeCommand('list', self::LIST_COMMAND_NAME, $configuration, $interfaceToCallRegistry);
        $this->registerOneTimeCommand('show', self::SHOW_COMMAND_NAME, $configuration, $interfaceToCallRegistry);
        $this->registerOneTimeCommand('reply', self::REPLAY_COMMAND_NAME, $configuration, $interfaceToCallRegistry);
        $this->registerOneTimeCommand('replyAll', self::REPLAY_ALL_COMMAND_NAME, $configuration, $interfaceToCallRegistry);
        $this->registerOneTimeCommand('delete', self::DELETE_COMMAND_NAME, $configuration, $interfaceToCallRegistry);
        $this->registerOneTimeCommand('help', self::HELP_COMMAND_NAME, $configuration, $interfaceToCallRegistry);

        $this->registerGateway(DeadLetterGateway::class, $connectionFactoryReference, false, $configuration);
        foreach ($customDeadLetterGateways as $customDeadLetterGateway) {
            $this->registerGateway($customDeadLetterGateway->getGatewayReferenceName(), $customDeadLetterGateway->getConnectionReferenceName(), true, $configuration);
        }
    }

    /**
     * @inheritDoc
     */
    public function canHandle($extensionObject): bool
    {
        return $extensionObject instanceof DbalConfiguration || $extensionObject instanceof CustomDeadLetterGateway;
    }

    /**
     * @inheritDoc
     */
    public function getRelatedReferences(): array
    {
        return [];
    }

    public function getModuleExtensions(array $serviceExtensions): array
    {
        return [];
    }

    private function registerOneTimeCommand(string $methodName, string $commandName, Configuration $configuration, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        [$messageHandlerBuilder, $oneTimeCommandConfiguration] = ConsoleCommandModule::prepareConsoleCommandForDirectObject(
            new DbalDeadLetterConsoleCommand(),
            $methodName,
            $commandName,
            true,
            $interfaceToCallRegistry
        );
        $configuration
            ->registerMessageHandler($messageHandlerBuilder)
            ->registerConsoleCommand($oneTimeCommandConfiguration);
    }

    public function getModulePackageName(): string
    {
        return DbalModule::NAME;
    }

    private function registerGateway(string $referenceName, string $connectionFactoryReference, bool $isCustomGateway, Configuration $configuration): void
    {
        if (! $isCustomGateway) {
            $configuration->registerMessageHandler(DbalDeadLetterBuilder::createStore($connectionFactoryReference));
        }

        $configuration
            ->registerMessageHandler(DbalDeadLetterBuilder::createDelete($connectionFactoryReference))
            ->registerMessageHandler(DbalDeadLetterBuilder::createShow($connectionFactoryReference))
            ->registerMessageHandler(DbalDeadLetterBuilder::createList($connectionFactoryReference))
            ->registerMessageHandler(DbalDeadLetterBuilder::createCount($connectionFactoryReference))
            ->registerMessageHandler(DbalDeadLetterBuilder::createReply($connectionFactoryReference))
            ->registerMessageHandler(DbalDeadLetterBuilder::createReplyAll($connectionFactoryReference))
            ->registerGatewayBuilder(
                GatewayProxyBuilder::create(
                    $referenceName,
                    DeadLetterGateway::class,
                    'list',
                    DbalDeadLetterBuilder::getChannelName($connectionFactoryReference, DbalDeadLetterBuilder::LIST_CHANNEL)
                )
                    ->withParameterConverters([
                        GatewayHeaderBuilder::create('limit', DbalDeadLetterBuilder::LIMIT_HEADER),
                        GatewayHeaderBuilder::create('offset', DbalDeadLetterBuilder::OFFSET_HEADER),
                    ])
            )
            ->registerGatewayBuilder(
                GatewayProxyBuilder::create(
                    $referenceName,
                    DeadLetterGateway::class,
                    'show',
                    DbalDeadLetterBuilder::getChannelName($connectionFactoryReference, DbalDeadLetterBuilder::SHOW_CHANNEL)
                )
            )
            ->registerGatewayBuilder(
                GatewayProxyBuilder::create(
                    $referenceName,
                    DeadLetterGateway::class,
                    'count',
                    DbalDeadLetterBuilder::getChannelName($connectionFactoryReference, DbalDeadLetterBuilder::COUNT_CHANNEL)
                )
            )
            ->registerGatewayBuilder(
                GatewayProxyBuilder::create(
                    $referenceName,
                    DeadLetterGateway::class,
                    'reply',
                    DbalDeadLetterBuilder::getChannelName($connectionFactoryReference, DbalDeadLetterBuilder::REPLAY_CHANNEL)
                )
            )
            ->registerGatewayBuilder(
                GatewayProxyBuilder::create(
                    $referenceName,
                    DeadLetterGateway::class,
                    'replyAll',
                    DbalDeadLetterBuilder::getChannelName($connectionFactoryReference, DbalDeadLetterBuilder::REPLAY_ALL_CHANNEL)
                )
            )
            ->registerGatewayBuilder(
                GatewayProxyBuilder::create(
                    $referenceName,
                    DeadLetterGateway::class,
                    'delete',
                    DbalDeadLetterBuilder::getChannelName($connectionFactoryReference, DbalDeadLetterBuilder::DELETE_CHANNEL)
                )
            );
    }
}
