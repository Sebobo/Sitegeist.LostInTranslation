<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\ContentRepository\CommandHook;

use Neos\ContentRepository\Core\CommandHandler\CommandHookInterface;
use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\CommandHandler\Commands;
use Neos\ContentRepository\Core\Dimension\ContentDimension;
use Neos\ContentRepository\Core\EventStore\PublishedEvents;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\Feature\NodeVariation\Command\CreateNodeVariant;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\NodeType\TetheredNodeTypeDefinitions;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphReadModelInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\Neos\Utility\NodeUriPathSegmentGenerator;
use Psr\Log\LoggerInterface;
use Sitegeist\LostInTranslation\ContentRepository\AuthProvider\AISystemTranslationRuntimeState;
use Sitegeist\LostInTranslation\Domain\Directive\DimensionValueDirectiveFactory;
use Sitegeist\LostInTranslation\Domain\Directive\NodeTypeTranslationDirectiveFactory;
use Sitegeist\LostInTranslation\Domain\Directive\TranslatablePropertyName;
use Sitegeist\LostInTranslation\Domain\TranslationServiceInterface;
use Sitegeist\LostInTranslation\Utility\ArrayFlatteningUtility;

final class TranslationCommandHook implements CommandHookInterface
{
    public function __construct(
        private bool $enabled,
        private readonly ContentGraphReadModelInterface $contentGraphReadModel,
        private readonly NodeTypeManager $nodeTypeManager,
        private readonly NodeTypeTranslationDirectiveFactory $nodeTypeTranslationDirectiveFactory,
        private readonly DimensionValueDirectiveFactory $dimensionValueDirectiveFactory,
        private readonly TranslationServiceInterface $translationService,
        private readonly ContentDimension $languageDimension,
        private readonly AISystemTranslationRuntimeState $aiSystemTranslationRuntimeState,
        private readonly NodeUriPathSegmentGenerator $nodeUriPathSegmentGenerator,
        private readonly bool $experimentalApplyHtmlEntityDecodeAfterTranslation,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function onBeforeHandle(CommandInterface $command): CommandInterface
    {
        if ($this->enabled === false) {
            return $command;
        }

        return $command;
    }

    public function onAfterHandle(CommandInterface $command, PublishedEvents $events): Commands
    {
        $this->aiSystemTranslationRuntimeState->resetActiveAIServiceId();
        if ($this->enabled === false) {
            return Commands::createEmpty();
        }

        if ($command instanceof CreateNodeVariant) {
            $this->aiSystemTranslationRuntimeState->setActiveAIServiceId($this->translationService->getAIServiceId());
            return $this->createNodeVariantCommandWasHandled($command);
        } else {
            return Commands::createEmpty();
        }
    }

    public function createNodeVariantCommandWasHandled(CreateNodeVariant $command): Commands
    {
        $sourceLanguageDirective = $this->dimensionValueDirectiveFactory->tryCreateForDimensionAndOriginDimensionSpacePoint(
            $this->languageDimension,
            $command->sourceOrigin
        );
        $targetLanguageDirective = $this->dimensionValueDirectiveFactory->tryCreateForDimensionAndOriginDimensionSpacePoint(
            $this->languageDimension,
            $command->targetOrigin
        );

        $sourceDeeplLanguage = $sourceLanguageDirective?->deeplSourceId;
        $targetDeeplLanguage = $targetLanguageDirective?->deeplTargetId;

        $this->logger?->debug(sprintf(
            'TranslationHook: CreateNodeVariant for node "%s", sourceOSP=%s targetOSP=%s',
            $command->nodeAggregateId->value,
            $command->sourceOrigin->toJson(),
            $command->targetOrigin->toJson(),
        ));

        if ($sourceDeeplLanguage === null || $targetDeeplLanguage === null) {
            $this->logger?->debug(sprintf(
                'TranslationHook: DeepL language not configured for source=%s or target=%s',
                $command->sourceOrigin->toJson(),
                $command->targetOrigin->toJson(),
            ));
            return Commands::createEmpty();
        }

        $this->logger?->debug(sprintf(
            'TranslationHook: DeepL source="%s" target="%s"',
            $sourceDeeplLanguage,
            $targetDeeplLanguage,
        ));

        $command->targetOrigin->getCoordinate($this->languageDimension->id);
        $sourceSubgraph = $this->contentGraphReadModel
            ->getContentGraph($command->workspaceName)
            ->getSubgraph($command->sourceOrigin->toDimensionSpacePoint(), VisibilityConstraints::withoutRestrictions());
        $sourceNode = $sourceSubgraph->findNodeById($command->nodeAggregateId);
        if ($sourceNode === null) {
            $this->logger?->debug(sprintf(
                'TranslationHook: source node "%s" not found in subgraph',
                $command->nodeAggregateId->value,
            ));
            return Commands::createEmpty();
        }

        $nodeType = $this->nodeTypeManager->getNodeType($sourceNode->nodeTypeName);
        if ($nodeType === null) {
            $this->logger?->debug(sprintf(
                'TranslationHook: node type not found for node "%s"',
                $command->nodeAggregateId->value,
            ));
            return Commands::createEmpty();
        }

        $additionalCommands = [];
        $additionalCommands[] = $this->tryPrepareSetNodeProperties(
            command: $command,
            sourceNode: $sourceNode,
            nodeType: $nodeType,
            sourceDeeplLanguage: $sourceDeeplLanguage,
            targetDeeplLanguage: $targetDeeplLanguage,
        );
        $additionalCommands = array_merge(
            $additionalCommands,
            $this->handleTetheredChildren(
                command: $command,
                sourceDeeplLanguage: $sourceDeeplLanguage,
                targetDeeplLanguage: $targetDeeplLanguage,
                nodeAggregateId: $sourceNode->aggregateId,
                tetheredNodeTypeDefinitions: $nodeType->tetheredNodeTypeDefinitions,
                subgraph: $sourceSubgraph,
            )
        );
        $additionalCommands = array_filter($additionalCommands);

        return Commands::fromArray($additionalCommands);
    }

    /**
     * @return array<int,SetNodeProperties|null>
     */
    private function handleTetheredChildren(
        CreateNodeVariant $command,
        string $sourceDeeplLanguage,
        string $targetDeeplLanguage,
        NodeAggregateId $nodeAggregateId,
        TetheredNodeTypeDefinitions $tetheredNodeTypeDefinitions,
        ContentSubgraphInterface $subgraph,
    ): array {
        $commands = [];
        foreach ($tetheredNodeTypeDefinitions as $tetheredNodeTypeDefinition) {
            $tetheredChildNode = $subgraph->findNodeByPath(
                path: $tetheredNodeTypeDefinition->name,
                startingNodeAggregateId: $nodeAggregateId
            );
            if ($tetheredChildNode) {
                $tetheredChildNodeType = $this->nodeTypeManager->getNodeType($tetheredChildNode->nodeTypeName);
                if ($tetheredChildNodeType) {
                    $commands[] = $this->tryPrepareSetNodeProperties(
                        command: $command,
                        sourceNode: $tetheredChildNode,
                        nodeType: $tetheredChildNodeType,
                        sourceDeeplLanguage: $sourceDeeplLanguage,
                        targetDeeplLanguage: $targetDeeplLanguage,
                    );
                    $commands = array_merge(
                        $commands,
                        $this->handleTetheredChildren(
                            command: $command,
                            sourceDeeplLanguage: $sourceDeeplLanguage,
                            targetDeeplLanguage: $targetDeeplLanguage,
                            nodeAggregateId: $tetheredChildNode->aggregateId,
                            tetheredNodeTypeDefinitions: $tetheredChildNodeType->tetheredNodeTypeDefinitions,
                            subgraph: $subgraph,
                        )
                    );
                }
            }
        }

        return $commands;
    }

    private function tryPrepareSetNodeProperties(
        CreateNodeVariant $command,
        Node $sourceNode,
        NodeType $nodeType,
        string $sourceDeeplLanguage,
        string $targetDeeplLanguage,
    ): ?SetNodeProperties {
        $translationDirective = $this->nodeTypeTranslationDirectiveFactory->createForNodeType($nodeType);

        if ($translationDirective->enabled === false) {
            $this->logger?->debug(sprintf(
                'TranslationHook: translation disabled for nodeType "%s" on node "%s"',
                $nodeType->name->value,
                $sourceNode->aggregateId->value,
            ));
            return null;
        }

        /** @var array<non-empty-string, string|array<non-empty-string, string>> $propertiesToTranslate */
        $propertiesToTranslate = [];
        foreach ($translationDirective->translatablePropertyNames as $translatablePropertyName) {
            if ($sourceNode->hasProperty($translatablePropertyName->propertyName)) {
                $propertyName = $translatablePropertyName->propertyName->value;
                $sourceValue = $sourceNode->getProperty($translatablePropertyName->propertyName);
                if (empty($sourceValue) || (is_string($sourceValue) && trim($sourceValue) === '')) {
                    $this->logger?->debug(sprintf(
                        'TranslationHook: empty source for property "%s" on node "%s"',
                        $propertyName,
                        $sourceNode->aggregateId->value,
                    ));
                    continue;
                }
                assert($propertyName !== '');
                if (is_object($sourceValue) && ($connector = $translatablePropertyName->translationConnector)) {
                    $propertiesToTranslate[$propertyName] = $connector->extractTranslations($sourceValue);
                } elseif (is_string($sourceValue)) {
                    $propertiesToTranslate[$propertyName] = $sourceValue;
                }
            }
        }

        if (empty($propertiesToTranslate)) {
            $this->logger?->debug(sprintf(
                'TranslationHook: no translatable properties with values for node "%s"',
                $sourceNode->aggregateId->value,
            ));
            return null;
        }

        $this->logger?->debug(sprintf(
            'TranslationHook: translating %d properties for node "%s"',
            count($propertiesToTranslate),
            $sourceNode->aggregateId->value,
        ));

        if (count($propertiesToTranslate) > 0) {
            $propertiesToTranslateDeflated = ArrayFlatteningUtility::deflate($propertiesToTranslate);
            /** @var array<non-empty-string, string> $translatedPropertiesDeflated */
            $translatedPropertiesDeflated = $this->translationService->translate(
                $propertiesToTranslateDeflated,
                $targetDeeplLanguage,
                $sourceDeeplLanguage,
            );
            if ($this->experimentalApplyHtmlEntityDecodeAfterTranslation) {
                $translatedPropertiesDeflated = array_map(
                    fn(string $value): string => html_entity_decode($value),
                    $translatedPropertiesDeflated
                );
            }
            $translatedProperties = ArrayFlatteningUtility::enflate($translatedPropertiesDeflated);
        } else {
            $translatedProperties = [];
        }

        if (empty($translatedProperties)) {
            $this->logger?->debug(sprintf(
                'TranslationHook: translated properties empty after API call for node "%s"',
                $sourceNode->aggregateId->value,
            ));
            return null;
        }

        $propertiesToSet = [];
        foreach ($translatedProperties as $propertyName => $translatedValue) {
            $targetValue = null;
            // Make sure the uriPathSegment is valid
            if ($propertyName === 'uriPathSegment' && is_string($translatedValue) && !preg_match('/^[a-z0-9\-]+$/i', $translatedValue)) {
                $translatedValue = $this->nodeUriPathSegmentGenerator->generateUriPathSegment(null, $translatedValue);
            }
            if (is_array($translatedValue)) {
                $translatablePropertyName = $translationDirective->translatablePropertyNames->findByName($propertyName);
                if (
                    $translatablePropertyName instanceof TranslatablePropertyName
                    && $connector = $translatablePropertyName->translationConnector
                ) {
                    $sourceValue = $sourceNode->getProperty($propertyName);
                    if (is_object($sourceValue)) {
                        $targetValue = $connector->applyTranslations($sourceValue, $translatedValue);
                    }
                }
            } else {
                $targetValue = $translatedValue;
            }
            if ($targetValue !== null) {
                $propertiesToSet[$propertyName] = $targetValue;
            }
        }

        if (empty($propertiesToSet)) {
            $this->logger?->debug(sprintf(
                'TranslationHook: no properties to set after reassembly for node "%s"',
                $sourceNode->aggregateId->value,
            ));
            return null;
        }

        $this->logger?->debug(sprintf(
            'TranslationHook: SetNodeProperties for node "%s" with %d properties',
            $sourceNode->aggregateId->value,
            count($propertiesToSet),
        ));

        return SetNodeProperties::create(
            workspaceName: $command->workspaceName,
            nodeAggregateId: $sourceNode->aggregateId,
            originDimensionSpacePoint: $command->targetOrigin,
            propertyValues: PropertyValuesToWrite::fromArray($propertiesToSet),
        );
    }
}
