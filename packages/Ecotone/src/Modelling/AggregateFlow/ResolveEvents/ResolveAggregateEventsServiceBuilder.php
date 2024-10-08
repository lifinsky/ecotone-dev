<?php

declare(strict_types=1);

namespace Ecotone\Modelling\AggregateFlow\ResolveEvents;

use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\ClassDefinition;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Modelling\Attribute\AggregateEvents;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;

/**
 * licence Apache-2.0
 */
final class ResolveAggregateEventsServiceBuilder implements CompilableBuilder
{
    private InterfaceToCall $interfaceToCall;
    private bool $isCalledAggregateEventSourced = false;
    private bool $isReturningAggregate = false;
    private bool $isFactoryMethod = false;
    private ?string $aggregateMethodWithEvents = null;
    private bool $isResultAggregateEventSourced = false;

    private function __construct(ClassDefinition $aggregateClassDefinition, string $methodName, private InterfaceToCallRegistry $interfaceToCallRegistry)
    {
        $this->initialize($aggregateClassDefinition, $methodName);
    }

    public static function create(ClassDefinition $aggregateClassDefinition, string $methodName, InterfaceToCallRegistry $interfaceToCallRegistry): self
    {
        return new self($aggregateClassDefinition, $methodName, $interfaceToCallRegistry);
    }

    public function compile(MessagingContainerBuilder $builder): Definition|Reference
    {
        if ($this->isFactoryMethod) {
            if ($this->isCalledAggregateEventSourced) {
                return $this->resolveEventSourcingAggregateEventsService(true, $this->aggregateMethodWithEvents);
            }
            return $this->resolveStateBasedAggregateEventsService(true, false, $this->aggregateMethodWithEvents);
        }
        if ($this->isReturningAggregate) {
            return $this->resolveMultipleAggregateEventsService();
        } elseif ($this->isCalledAggregateEventSourced) {
            return $this->resolveEventSourcingAggregateEventsService(false, $this->aggregateMethodWithEvents);
        } else {
            return $this->resolveStateBasedAggregateEventsService(false, true, $this->aggregateMethodWithEvents);
        }
    }

    private function resolveMultipleAggregateEventsService(): Definition
    {
        if ($this->isCalledAggregateEventSourced) {
            $resolveCalledAggregateEventsService = $this->resolveEventSourcingAggregateEventsService(false, $this->aggregateMethodWithEvents);
        } else {
            $resolveCalledAggregateEventsService = $this->resolveStateBasedAggregateEventsService(false, true, $this->aggregateMethodWithEvents);
        }

        if ($this->isResultAggregateEventSourced) {
            $resultClassDefinition = ClassDefinition::createFor($this->interfaceToCall->getReturnType());
            $resolveResultAggregateEventsService = $this->resolveEventSourcingAggregateEventsService(true, $resultClassDefinition->getMethodWithAnnotation(TypeDescriptor::create(AggregateEvents::class), $this->interfaceToCallRegistry));
        } else {
            $resultClassDefinition = ClassDefinition::createFor($this->interfaceToCall->getReturnType());
            $resolveResultAggregateEventsService = $this->resolveStateBasedAggregateEventsService(true, false, $resultClassDefinition->getMethodWithAnnotation(TypeDescriptor::create(AggregateEvents::class), $this->interfaceToCallRegistry));
        }

        return new Definition(ResolveMultipleAggregateEventsService::class, [
            $resolveCalledAggregateEventsService,
            $resolveResultAggregateEventsService,
        ]);
    }

    private function resolveEventSourcingAggregateEventsService(bool $isFactoryMethod, ?string $aggregateMethodWithEvents): Definition
    {
        return new Definition(ResolveEventSourcingAggregateEventsService::class, [
            $isFactoryMethod,
            $aggregateMethodWithEvents,
        ]);
    }

    private function resolveStateBasedAggregateEventsService(bool $isFactoryMethod, bool $resolveCalledAggregate, ?string $aggregateMethodWithEvents): Definition
    {
        return new Definition(ResolveStateBasedAggregateEventsService::class, [
            $isFactoryMethod,
            $resolveCalledAggregate,
            $aggregateMethodWithEvents,
        ]);
    }

    private function initialize(ClassDefinition $aggregateClassDefinition, string $methodName)
    {
        $this->interfaceToCall = $this->interfaceToCallRegistry->getFor($aggregateClassDefinition->getClassType()->toString(), $methodName);
        $this->isCalledAggregateEventSourced = $aggregateClassDefinition->hasClassAnnotation(TypeDescriptor::create(EventSourcingAggregate::class));
        $this->aggregateMethodWithEvents = $aggregateClassDefinition->getMethodWithAnnotation(TypeDescriptor::create(AggregateEvents::class), $this->interfaceToCallRegistry);
        $this->isReturningAggregate = $this->interfaceToCall->isReturningAggregate($this->interfaceToCallRegistry);
        if ($this->isReturningAggregate) {
            $resultClassDefinition = ClassDefinition::createFor($this->interfaceToCall->getReturnType());
            $this->isResultAggregateEventSourced = $resultClassDefinition->hasClassAnnotation(TypeDescriptor::create(EventSourcingAggregate::class));
        }
        $this->isFactoryMethod = $this->interfaceToCall->isFactoryMethod();
    }
}
