<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\Feature\NodeVariation\Command\CreateNodeVariant;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\NodeType\NodeTypeNames;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindSubtreeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\NodeType\NodeTypeCriteria;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyNames;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;
use Neos\Neos\Utility\NodeUriPathSegmentGenerator;
use Sitegeist\LostInTranslation\ContentRepository\AuthProvider\AISystemTranslationRuntimeState;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationReadModel;
use Sitegeist\LostInTranslation\Domain\Directive\DimensionValueDirectiveFactory;
use Sitegeist\LostInTranslation\Domain\Directive\NodeTypeTranslationDirectiveFactory;
use Sitegeist\LostInTranslation\Utility\ArrayFlatteningUtility;

/**
 * Driver that brings a target-language dimension subtree back in sync with its source (reference)
 * language, by dispatching:
 *
 *  - `SetNodeProperties` for existing target variants whose translated properties are stale
 *    (per {@see \Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationProjection}).
 *  - `CreateNodeVariant` for source nodes that have no target variant yet. The
 *    {@see \Sitegeist\LostInTranslation\ContentRepository\CommandHook\TranslationCommandHook}
 *    then cascades translation onto the freshly-created variant (including tethered children).
 *
 * Stale-property commands are dispatched while {@see AISystemTranslationRuntimeState} marks the AI
 * as the actor so event metadata is attributed to the AI service, not the editor.
 */
class Retranslator
{
    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected TranslationServiceInterface $translationService;

    #[Flow\Inject]
    protected NodeTypeTranslationDirectiveFactory $nodeTypeTranslationDirectiveFactory;

    #[Flow\Inject]
    protected AISystemTranslationRuntimeState $aiSystemTranslationRuntimeState;

    #[Flow\Inject]
    protected NodeUriPathSegmentGenerator $nodeUriPathSegmentGenerator;

    #[Flow\InjectConfiguration(path: 'nodeTranslation.languageDimensionName')]
    protected string $languageDimensionName;

    #[Flow\InjectConfiguration(path: 'nodeTranslation.experimental-applyHtmlEntityDecodeAfterTranslation')]
    protected bool $experimentalApplyHtmlEntityDecodeAfterTranslation = false;

    /**
     * Retranslate the subtree below `$nodeAggregateId` into `$targetDimensionSpacePoint`.
     *
     * The source DSP is derived from the target via the `referenceLanguage` config on the target
     * preset — callers do not pass it. Calling with the source language itself (no `referenceLanguage`)
     * is a legitimate no-op, returning `RetranslationResult::skipped(...)` rather than throwing.
     *
     * Best-effort: every misconfiguration returns `skipped`; callers distinguish real work from
     * no-ops via the dispatch counts on the returned {@see RetranslationResult}. Repeated invocations
     * are idempotent.
     */
    public function retranslateNode(
        ContentRepositoryId $contentRepositoryId,
        WorkspaceName $workspaceName,
        NodeAggregateId $nodeAggregateId,
        DimensionSpacePoint $targetDimensionSpacePoint,
        bool $force = false,
    ): RetranslationResult {
        $cr = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $languageDimensionId = new ContentDimensionId($this->languageDimensionName);

        $languageDimension = $cr->getContentDimensionSource()->getDimension($languageDimensionId);
        if ($languageDimension === null) {
            return RetranslationResult::skipped(sprintf(
                'language dimension "%s" not configured in CR "%s"',
                $this->languageDimensionName,
                $contentRepositoryId->value,
            ));
        }

        $resolver = new ReferenceDimensionSpacePointResolver(
            allowedDimensionSubspace: $cr->getVariationGraph()->getDimensionSpacePoints(),
            contentDimensionSource: $cr->getContentDimensionSource(),
            languageDimensionId: $languageDimensionId,
        );
        $sourceDimensionSpacePoint = $resolver->tryResolveSourceDimensionSpacePoint($targetDimensionSpacePoint);
        if ($sourceDimensionSpacePoint === null) {
            return RetranslationResult::skipped(sprintf(
                'no referenceLanguage configured for target DSP %s',
                $targetDimensionSpacePoint->toJson(),
            ));
        }

        $dimensionValueDirectiveFactory = new DimensionValueDirectiveFactory();
        $sourceDeeplLanguage = $dimensionValueDirectiveFactory->tryCreateForDimensionAndOriginDimensionSpacePoint(
            $languageDimension,
            OriginDimensionSpacePoint::fromDimensionSpacePoint($sourceDimensionSpacePoint),
        )?->deeplSourceId;
        $targetDeeplLanguage = $dimensionValueDirectiveFactory->tryCreateForDimensionAndOriginDimensionSpacePoint(
            $languageDimension,
            OriginDimensionSpacePoint::fromDimensionSpacePoint($targetDimensionSpacePoint),
        )?->deeplTargetId;
        if ($sourceDeeplLanguage === null || $targetDeeplLanguage === null) {
            return RetranslationResult::skipped(sprintf(
                'DeepL language not resolvable for source %s or target %s',
                $sourceDimensionSpacePoint->toJson(),
                $targetDimensionSpacePoint->toJson(),
            ));
        }

        $contentGraph = $cr->getContentGraph($workspaceName);
        $sourceSubgraph = $contentGraph->getSubgraph($sourceDimensionSpacePoint, NeosVisibilityConstraints::excludeRemoved());
        $targetSubgraph = $contentGraph->getSubgraph($targetDimensionSpacePoint, NeosVisibilityConstraints::excludeRemoved());

        // Scope to the current document: nested documents are out of scope for a retranslation run.
        // The entry node itself is always returned by `findSubtree`, so a document entry still gets its own properties retranslated.
        $sourceSubtree = $sourceSubgraph->findSubtree(
            $nodeAggregateId,
            FindSubtreeFilter::create(
                nodeTypes: NodeTypeCriteria::createWithAllowedNodeTypeNames(
                    NodeTypeNames::fromStringArray(['Neos.Neos:ContentCollection', 'Neos.Neos:Content'])
                ),
            ),
        );
        if ($sourceSubtree === null) {
            return RetranslationResult::skipped(sprintf(
                'source node %s not found in DSP %s',
                $nodeAggregateId->value,
                $sourceDimensionSpacePoint->toJson(),
            ));
        }

        $nodeTypeManager = $cr->getNodeTypeManager();
        $nonDocumentNodeTypeName = NodeTypeNameFactory::forDocument();

        if ($force) {
            // Force mode: retranslate the entry node (regardless of type) and all non-Document
            // descendants, ignoring the stale projection.
            $propertyCommands = [];
        } else {
            // Pre-fetch stale records keyed by aggregate id for O(1) lookup during the walk.
            // The finder takes the *source* subtree (for the node id list) but filters by the *target*
            // origin dsp hash — that's where stale records live.
            $targetOrigin = OriginDimensionSpacePoint::fromDimensionSpacePoint($targetDimensionSpacePoint);
            $staleTranslations = $cr->projectionState(StaleTranslationReadModel::class)
                ->staleTranslationFinder
                ->findBySubtree($sourceSubtree, $targetOrigin);
            $staleByNodeAggregateId = [];
            foreach ($staleTranslations as $staleTranslation) {
                $staleByNodeAggregateId[$staleTranslation->nodeAggregateId->value] = $staleTranslation;
            }
            $propertyCommands = [];
        }

        // In force mode, first ensure the entry node exists in the target, then force-retranslate
        // all its translatable properties. This is done before the descendant walk so that
        // children see the entry variant already in place.
        $variantCommands = [];
        if ($force) {
            $entryNode = $sourceSubtree->node;
            $targetEntryNode = $targetSubgraph->findNodeById($entryNode->aggregateId);
            $entryExistsInTarget = $targetEntryNode !== null && $targetEntryNode->originDimensionSpacePoint->equals($targetDimensionSpacePoint);

            if (!$entryExistsInTarget) {
                $variantCommands[] = CreateNodeVariant::create(
                    $entryNode->workspaceName,
                    $entryNode->aggregateId,
                    $entryNode->originDimensionSpacePoint,
                    OriginDimensionSpacePoint::fromDimensionSpacePoint($targetSubgraph->getDimensionSpacePoint()),
                );
            } else {
                $entryNodeType = $nodeTypeManager->getNodeType($entryNode->nodeTypeName);
                if ($entryNodeType !== null) {
                    $directive = $this->nodeTypeTranslationDirectiveFactory->createForNodeType($entryNodeType);
                    $entryPropertyNames = $directive->getPropertyNames();
                    $entryTargetOrigin = OriginDimensionSpacePoint::fromDimensionSpacePoint($targetSubgraph->getDimensionSpacePoint());
                    $command = $this->tryBuildSetNodeProperties(
                        nodeTypeManager: $nodeTypeManager,
                        sourceNode: $entryNode,
                        stalePropertyNames: $entryPropertyNames,
                        targetOrigin: $entryTargetOrigin,
                        sourceDeeplLanguage: $sourceDeeplLanguage,
                        targetDeeplLanguage: $targetDeeplLanguage,
                    );
                    if ($command !== null) {
                        $propertyCommands[] = $command;
                    }
                }
            }
        }

        // Iterative depth-first pre-order walk over the source subtree (skip the entry node in
        // force mode — it was handled above). For each node:
        //   - (stale mode) Emit SetNodeProperties if a stale record exists AND the target variant already exists.
        //   - (force mode) Emit SetNodeProperties if the node exists in target AND is not Document.
        //   - Emit CreateNodeVariant if the target variant is missing AND the node is non-tethered.
        //     Tethered descendants come along automatically with their ancestor variant.
        //
        // Children are pushed onto the stack in reverse so they pop in declaration order
        // (preserves pre-order; matches event-index assertions in the Behat tests).
        $stack = $force
            ? [...array_reverse([...$sourceSubtree->children])]
            : [$sourceSubtree];
        while ($stack !== []) {
            $currentSubtree = array_pop($stack);
            $sourceNode = $currentSubtree->node;
            $targetNode = $targetSubgraph->findNodeById($sourceNode->aggregateId);
            $existsInTarget = $targetNode !== null && $targetNode->originDimensionSpacePoint->equals($targetDimensionSpacePoint);

            if ($existsInTarget) {
                $propertyNames = null;
                $targetOrigin = null;

                if ($force) {
                    $nodeType = $nodeTypeManager->getNodeType($sourceNode->nodeTypeName);
                    if ($nodeType !== null && !$nodeType->isOfType($nonDocumentNodeTypeName)) {
                        $directive = $this->nodeTypeTranslationDirectiveFactory->createForNodeType($nodeType);
                        $propertyNames = $directive->getPropertyNames();
                        $targetOrigin = OriginDimensionSpacePoint::fromDimensionSpacePoint($targetDimensionSpacePoint);
                    }
                } else {
                    $stale = $staleByNodeAggregateId[$sourceNode->aggregateId->value] ?? null;
                    if ($stale !== null) {
                        $propertyNames = $stale->propertyNames;
                        // Use the OriginDimensionSpacePoint from the stale record, not a freshly built
                        // one — it reflects where the variant actually lives (matters for spec/gen
                        // variants).
                        $targetOrigin = $stale->originDimensionSpacePoint;
                    }
                }

                if ($propertyNames !== null && $targetOrigin !== null) {
                    $command = $this->tryBuildSetNodeProperties(
                        nodeTypeManager: $nodeTypeManager,
                        sourceNode: $sourceNode,
                        stalePropertyNames: $propertyNames,
                        targetOrigin: $targetOrigin,
                        sourceDeeplLanguage: $sourceDeeplLanguage,
                        targetDeeplLanguage: $targetDeeplLanguage,
                    );
                    if ($command !== null) {
                        $propertyCommands[] = $command;
                    }
                }
            }

            if (!$existsInTarget && !$sourceNode->classification->isTethered()) {
                $variantCommands[] = CreateNodeVariant::create(
                    $sourceNode->workspaceName,
                    $sourceNode->aggregateId,
                    $sourceNode->originDimensionSpacePoint,
                    OriginDimensionSpacePoint::fromDimensionSpacePoint($targetSubgraph->getDimensionSpacePoint()),
                );
            }

            foreach (array_reverse([...$currentSubtree->children]) as $childSubtree) {
                $stack[] = $childSubtree;
            }
        }

        // Create variants first so nodes exist in the target before we set their properties.
        foreach ($variantCommands as $command) {
            $cr->handle($command);
        }
        foreach ($propertyCommands as $command) {
            // Mark commands as "triggered by AI"
            $this->dispatchAsAi($cr, $command);
        }

        return new RetranslationResult(
            stalePropertyCommandsDispatched: count($propertyCommands),
            variantCommandsDispatched: count($variantCommands),
        );
    }

    /**
     * Build a translated `SetNodeProperties` for the explicit stale property list, or `null`.
     *
     * Slimmed clone of {@see \Sitegeist\LostInTranslation\ContentRepository\CommandHook\TranslationCommandHook::tryPrepareSetNodeProperties}.
     * The difference: the hook iterates ALL translatable properties (fresh variant, everything is
     * new); we iterate ONLY the explicit stale list, because editors may have manually overridden
     * other translated properties on the target side.
     *
     * Trusts the projection's invariant that stale records only exist for translation-enabled
     * node types and translatable properties — so guards on `directive->enabled` and `findByName`
     * are dropped. The `hasProperty` + empty-source guards remain: editors can blank source
     * properties between the projection write and our dispatch.
     */
    private function tryBuildSetNodeProperties(
        NodeTypeManager $nodeTypeManager,
        Node $sourceNode,
        PropertyNames $stalePropertyNames,
        OriginDimensionSpacePoint $targetOrigin,
        string $sourceDeeplLanguage,
        string $targetDeeplLanguage,
    ): ?SetNodeProperties {
        $nodeType = $nodeTypeManager->getNodeType($sourceNode->nodeTypeName);
        // Defensive: projection guarantees the node type existed when the record was written.
        // If it's since been removed, we can't resolve the connector for non-string props.
        if ($nodeType === null) {
            return null;
        }
        $directive = $this->nodeTypeTranslationDirectiveFactory->createForNodeType($nodeType);

        /** @var array<non-empty-string, string|array<non-empty-string, string>> $propertiesToTranslate */
        $propertiesToTranslate = [];
        foreach ($stalePropertyNames as $propertyName) {
            if (!$nodeType->hasProperty($propertyName->value)) {
                continue;
            }
            $sourceValue = $sourceNode->getProperty($propertyName);
            if ($sourceValue === null || (is_string($sourceValue) && trim($sourceValue) === '')) {
                continue;
            }

            $name = $propertyName->value;
            assert($name !== '');

            $translatable = $directive->translatablePropertyNames->findByName($propertyName);
            if (is_object($sourceValue) && $translatable?->translationConnector !== null) {
                $propertiesToTranslate[$name] = $translatable->translationConnector->extractTranslations($sourceValue);
            } elseif (is_string($sourceValue)) {
                $propertiesToTranslate[$name] = $sourceValue;
            }
        }

        if ($propertiesToTranslate === []) {
            return null;
        }

        // deflate → translate → enflate so DeepL sees one string per leaf, connectors get reassembled.
        $deflated = ArrayFlatteningUtility::deflate($propertiesToTranslate);
        /** @var array<non-empty-string, string> $translatedDeflated */
        $translatedDeflated = $this->translationService->translate(
            $deflated,
            $targetDeeplLanguage,
            $sourceDeeplLanguage,
        );
        if ($this->experimentalApplyHtmlEntityDecodeAfterTranslation) {
            $translatedDeflated = array_map(
                static fn (string $value): string => html_entity_decode($value),
                $translatedDeflated,
            );
        }
        $translatedProperties = ArrayFlatteningUtility::enflate($translatedDeflated);

        $propertiesToSet = [];
        foreach ($translatedProperties as $name => $translatedValue) {
            // uriPathSegment has strict charset; DeepL routinely violates it.
            if (
                $name === 'uriPathSegment'
                && is_string($translatedValue)
                && !preg_match('/^[a-z0-9\-]+$/i', $translatedValue)
            ) {
                $translatedValue = $this->nodeUriPathSegmentGenerator->generateUriPathSegment(null, $translatedValue);
            }
            $targetValue = null;
            if (is_array($translatedValue)) {
                $translatable = $directive->translatablePropertyNames->findByName($name);
                $connector = $translatable?->translationConnector;
                if ($connector !== null) {
                    $sourceValue = $sourceNode->getProperty($name);
                    if (is_object($sourceValue)) {
                        $targetValue = $connector->applyTranslations($sourceValue, $translatedValue);
                    }
                }
            } else {
                $targetValue = $translatedValue;
            }
            if ($targetValue !== null) {
                $propertiesToSet[$name] = $targetValue;
            }
        }

        if ($propertiesToSet === []) {
            return null;
        }

        return SetNodeProperties::create(
            workspaceName: $sourceNode->workspaceName,
            nodeAggregateId: $sourceNode->aggregateId,
            originDimensionSpacePoint: $targetOrigin,
            propertyValues: PropertyValuesToWrite::fromArray($propertiesToSet),
        );
    }

    /**
     * Dispatch a command with AI authorship active. `try/finally` is load-bearing: an exception
     * inside `handle()` must still reset the singleton runtime state.
     */
    private function dispatchAsAi(ContentRepository $cr, CommandInterface $command): void
    {
        $this->aiSystemTranslationRuntimeState->setActiveAIServiceId($this->translationService->getAIServiceId());
        try {
            $cr->handle($command);
        } finally {
            $this->aiSystemTranslationRuntimeState->resetActiveAIServiceId();
        }
    }
}
