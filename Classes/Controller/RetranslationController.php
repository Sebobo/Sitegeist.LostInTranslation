<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Controller;

use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\NodeType\NodeTypeNames;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindSubtreeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\NodeType\NodeTypeCriteria;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Psr\Log\LoggerInterface;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationReadModel;
use Sitegeist\LostInTranslation\Domain\ReferenceDimensionSpacePointResolver;
use Sitegeist\LostInTranslation\Domain\Retranslator;

/**
 * HTTP entry point for the inspector "Retranslate" view in the Neos UI.
 *
 * Two endpoints, both returning JSON:
 *  - `getTranslationMetadata` reports whether the target-language subtree is in sync with its
 *    source (reference) language and also returns metadata for every specialization (language that
 *    has the current language as its reference).
 *  - `retranslateNode` delegates to {@see Retranslator} (same path as the CLI command).
 *
 * The reference language is derived from the target preset's `referenceLanguage` configuration via
 * {@see ReferenceDimensionSpacePointResolver} or from its generalization; if the target has no reference language configured
 * (e.g. the source language itself) the UI is told there's nothing to compare against.
 */
class RetranslationController extends ActionController
{
    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected Retranslator $retranslator;

    #[Flow\InjectConfiguration(path: 'nodeTranslation.languageDimensionName')]
    protected string $languageDimensionName;

    #[Flow\Inject('Sitegeist.LostInTranslation:TranslationLogger', false)]
    protected LoggerInterface $translationLogger;

    /**
     * Report whether translations for the given node into the target dimension are up to date,
     * together with metadata for every language specialization of the current dimension.
     *
     * "Specializations" are languages that have the current language configured as their reference.
     *
     * The "stale" signal is whatever {@see StaleTranslationProjection} has recorded for the
     * target origin DSP — no source/target timestamp comparison happens here.
     */
    public function getTranslationMetadataAction(
        string $nodeAggregateId,
        string $workspaceName,
        string $coordinates,
        string $contentRepositoryId = 'default',
    ): string {
        $this->translationLogger->debug(sprintf(
            'getTranslationMetadata: node="%s" workspace="%s" coordinates=%s cr="%s"',
            $nodeAggregateId,
            $workspaceName,
            $coordinates,
            $contentRepositoryId,
        ));

        $cr = $this->contentRepositoryRegistry->get(ContentRepositoryId::fromString($contentRepositoryId));

        $dimensionNames = [];
        foreach ($cr->getContentDimensionSource()->getContentDimensionsOrderedByPriority() as $contentDimension) {
            $dimensionNames[$contentDimension->id->value] = $contentDimension->configuration['label'] ?? $contentDimension->id->value;
        }

        $languageDimensionId = new ContentDimensionId($this->languageDimensionName);
        $languageDimension = $cr->getContentDimensionSource()->getDimension($languageDimensionId);
        if ($languageDimension === null) {
            return $this->jsonResponse([
                'isUpToDate' => true,
                'referenceLanguage' => null,
                'staleNodeCount' => 0,
                'specializations' => [],
            ]);
        }

        /** @var array<string, string> $coordinatesArray */
        $coordinatesArray = \json_decode($coordinates, true, flags: JSON_THROW_ON_ERROR);
        $dimensionSpacePoint = DimensionSpacePoint::fromArray($coordinatesArray);

        $resolver = new ReferenceDimensionSpacePointResolver(
            allowedDimensionSubspace: $cr->getVariationGraph()->getDimensionSpacePoints(),
            contentDimensionSource: $cr->getContentDimensionSource(),
            languageDimensionId: $languageDimensionId,
        );

        // --- Part 1: existing metadata — coordinates as TARGET, find its SOURCE ---
        $sourceDimensionSpacePoint = $resolver->tryResolveSourceDimensionSpacePoint($dimensionSpacePoint);
        $referenceLabel = null;
        $isUpToDate = true;
        $staleNodeCount = 0;

        if ($sourceDimensionSpacePoint !== null) {
            $sourceLanguageValue = $sourceDimensionSpacePoint->coordinates[$this->languageDimensionName];
            $sourceLanguageDimensionValue = $languageDimension->getValue($sourceLanguageValue);
            $configuredLabel = $sourceLanguageDimensionValue?->configuration['label'] ?? null;
            $referenceLabel = is_string($configuredLabel) ? $configuredLabel : $sourceLanguageValue;

            $this->translationLogger->debug(sprintf(
                'getTranslationMetadata: source DSP resolved to %s (label: "%s")',
                $sourceDimensionSpacePoint->toJson(),
                $referenceLabel,
            ));

            $contentGraph = $cr->getContentGraph(WorkspaceName::fromString($workspaceName));
            $sourceSubgraph = $contentGraph->getSubgraph(
                $sourceDimensionSpacePoint,
                NeosVisibilityConstraints::excludeRemoved(),
            );
            $sourceSubtree = $sourceSubgraph->findSubtree(
                NodeAggregateId::fromString($nodeAggregateId),
                FindSubtreeFilter::create(
                    nodeTypes: NodeTypeCriteria::createWithAllowedNodeTypeNames(
                        NodeTypeNames::fromStringArray(['Neos.Neos:ContentCollection', 'Neos.Neos:Content'])
                    ),
                ),
            );
            if ($sourceSubtree !== null) {
                $targetOrigin = OriginDimensionSpacePoint::fromDimensionSpacePoint($dimensionSpacePoint);
                $staleTranslations = $cr->projectionState(StaleTranslationReadModel::class)
                    ->staleTranslationFinder
                    ->findBySubtree($sourceSubtree, $targetOrigin);
                $staleNodeCount = count($staleTranslations->items);
                $isUpToDate = $staleNodeCount === 0;
            } else {
                $this->translationLogger->debug(sprintf(
                    'getTranslationMetadata: source subtree not found for node "%s"',
                    $nodeAggregateId,
                ));
            }
        } else {
            $this->translationLogger->debug(sprintf(
                'getTranslationMetadata: no source DSP resolved from %s',
                $coordinates,
            ));
        }

        // --- Part 2: specializations — coordinates as SOURCE, find its TARGETS ---
        $specializations = [];
        $targetDimensionSpacePoints = $resolver->tryResolveTargetDimensionSpacePoints($dimensionSpacePoint);
        if ($targetDimensionSpacePoints !== []) {
            $contentGraph = $cr->getContentGraph(WorkspaceName::fromString($workspaceName));
            $sourceSubgraph = $contentGraph->getSubgraph(
                $dimensionSpacePoint,
                NeosVisibilityConstraints::excludeRemoved(),
            );
            $sourceSubtree = $sourceSubgraph->findSubtree(
                NodeAggregateId::fromString($nodeAggregateId),
                FindSubtreeFilter::create(
                    nodeTypes: NodeTypeCriteria::createWithAllowedNodeTypeNames(
                        NodeTypeNames::fromStringArray(['Neos.Neos:ContentCollection', 'Neos.Neos:Content'])
                    ),
                ),
            );
            if ($sourceSubtree !== null) {
                foreach ($targetDimensionSpacePoints as $targetDimensionSpacePoint) {
                    $targetLanguageValue = $targetDimensionSpacePoint->coordinates[$this->languageDimensionName];
                    $targetLanguageDimensionValue = $languageDimension->getValue($targetLanguageValue);
                    $configuredLabel = $targetLanguageDimensionValue?->configuration['label'] ?? null;
                    $targetLabel = is_string($configuredLabel) ? $configuredLabel : $targetLanguageValue;

                    $targetOrigin = OriginDimensionSpacePoint::fromDimensionSpacePoint($targetDimensionSpacePoint);
                    $staleTranslations = $cr->projectionState(StaleTranslationReadModel::class)
                        ->staleTranslationFinder
                        ->findBySubtree($sourceSubtree, $targetOrigin);
                    $staleCount = count($staleTranslations->items);

                    $specializations[] = [
                        'targetCoordinates' => $targetDimensionSpacePoint->coordinates,
                        'targetLanguage' => ['label' => $targetLabel],
                        'staleNodeCount' => $staleCount,
                    ];
                }
            }
        }

        $this->translationLogger->debug(sprintf(
            'getTranslationMetadata result: staleCount=%d specializations=%d',
            $staleNodeCount,
            count($specializations),
        ));

        return $this->jsonResponse([
            'isUpToDate' => $isUpToDate,
            'referenceLanguage' => $referenceLabel !== null ? ['label' => $referenceLabel] : null,
            'staleNodeCount' => $staleNodeCount,
            'specializations' => $specializations,
            'dimensionNames' => $dimensionNames,
        ]);
    }

    /**
     * Trigger {@see Retranslator::retranslateNode()} for the given node into the target dimension.
     */
    public function retranslateNodeAction(
        string $nodeAggregateId,
        string $workspaceName,
        string $targetCoordinates,
        string $contentRepositoryId = 'default',
        bool $force = false,
    ): string {
        /** @var array<string, string> $coordinatesArray */
        $coordinatesArray = \json_decode($targetCoordinates, true, flags: JSON_THROW_ON_ERROR);

        $this->translationLogger->debug(sprintf(
            'retranslateNode: node="%s" target=%s force="%s" cr="%s"',
            $nodeAggregateId,
            $targetCoordinates,
            $force ? 'yes' : 'no',
            $contentRepositoryId,
        ));

        $result = $this->retranslator->retranslateNode(
            ContentRepositoryId::fromString($contentRepositoryId),
            WorkspaceName::fromString($workspaceName),
            NodeAggregateId::fromString($nodeAggregateId),
            DimensionSpacePoint::fromArray($coordinatesArray),
            $force,
        );

        $this->translationLogger->debug(sprintf(
            'retranslateNode result: message="%s"',
            $result->skippedReason !== null
                ? sprintf('skipped: %s', $result->skippedReason)
                : ($result->isNoOp() ? 'noop' : 'success'),
        ));

        return $this->jsonResponse([
            'message' => $result->skippedReason !== null
                ? sprintf('Skipped: %s', $result->skippedReason)
                : ($result->isNoOp() ? 'No-op: nothing to retranslate' : 'Successfully translated'),
            'stalePropertyCommandsDispatched' => $result->stalePropertyCommandsDispatched,
            'variantCommandsDispatched' => $result->variantCommandsDispatched,
            'skippedReason' => $result->skippedReason,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonResponse(array $data): string
    {
        $this->response->setContentType('application/json');
        return \json_encode($data, JSON_THROW_ON_ERROR);
    }
}
