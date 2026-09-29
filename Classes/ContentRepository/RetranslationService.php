<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\ContentRepository;

use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Neos\Domain\Service\ContentContextFactory;
use Neos\Neos\Domain\Service\ContentDimensionPresetSourceInterface;
use Psr\Log\LoggerInterface;
use Sitegeist\LostInTranslation\Domain\RetranslationResult;
use Sitegeist\LostInTranslation\Domain\TranslatableProperty\TranslatablePropertyNamesFactory;

/**
 * @Flow\Scope("singleton")
 */
class RetranslationService
{
    /**
     * @Flow\InjectConfiguration(path="nodeTranslation.languageDimensionName")
     * @var string
     */
    protected $languageDimensionName;

    /**
     * @Flow\InjectConfiguration(path="nodeTranslation.retranslation.removeNodesWithoutSource")
     * @var bool
     */
    protected $removeNodesWithoutSource;

    /**
     * @Flow\InjectConfiguration(path="nodeTranslation.retranslation.synchronizeUntranslatedProperties")
     * @var bool
     */
    protected $synchronizeUntranslatedProperties;

    /**
     * @Flow\InjectConfiguration(path="nodeTranslation.retranslation.synchronizeNodePosition")
     * @var bool
     */
    protected $synchronizeNodePosition;

    /**
     * @Flow\InjectConfiguration(path="nodeTranslation.retranslation.synchronizeNodeVisibility")
     * @var bool
     */
    protected $synchronizeNodeVisibility;

    /**
     * @Flow\InjectConfiguration(path="nodeTranslation.retranslation.synchronizeNodeType")
     * @var bool
     */
    protected $synchronizeNodeType;

    /**
     * @Flow\Inject
     * @var TranslatablePropertyNamesFactory
     */
    protected $translatablePropertiesFactory;

    #[Flow\Inject('Sitegeist.LostInTranslation:TranslationLogger', false)]
    protected LoggerInterface $logger;

    public function __construct(
        protected readonly ContentDimensionPresetSourceInterface $contentDimensionPresetSource,
        protected readonly ContentContextFactory $contentContextFactory,
        protected readonly NodeTranslationService $nodeTranslationService,
    ) {
    }

    public function findFirstUpdateDateOnNodeOrDescendants(
        Node $sourceNode,
        ContentContext $sourceContext,
        ContentContext $targetContext
    ): ?\DateTimeInterface {
        /** @var ?Node $targetNode */
        $targetNode = $targetContext->getNodeByIdentifier($sourceNode->getIdentifier());
        $sourceReferenceDate = $sourceNode->getLastModificationDateTime();
        if (!$targetNode) {
            return $sourceReferenceDate;
        }

        /**
         * @var \DateTimeInterface[] $possibleModificationDates
         */
        $possibleModificationDates = [];
        $targetReferenceDate = $targetNode->getLastModificationDateTime();
        if ($targetReferenceDate < $sourceReferenceDate) {
            $possibleModificationDates[] = $sourceReferenceDate;
        }

        /**
         * @var array<string, NodeInterface> $sourceNodeByIdentifier
         */
        $sourceNodeByIdentifier = [];
        foreach ($sourceNode->getChildNodes('Neos.Neos:Content,Neos.Neos:ContentCollection') as $sourceChildNode) {
            $sourceNodeByIdentifier[$sourceChildNode->getIdentifier()] = $sourceChildNode;
            /** @var Node $sourceChildNode */
            $updateDate = $this->findFirstUpdateDateOnNodeOrDescendants(
                $sourceChildNode,
                $sourceContext,
                $targetContext
            );
            if ($updateDate) {
                $possibleModificationDates[] = $updateDate;
            }
        }

        foreach ($targetNode->getChildNodes('Neos.Neos:Content,Neos.Neos:ContentCollection') as $targetChildNode) {
            if (array_key_exists($targetChildNode->getIdentifier(), $sourceNodeByIdentifier)) {
                $sourceNode = $sourceNodeByIdentifier[$targetChildNode->getIdentifier()];
                if ($targetChildNode->getIndex() !== $sourceNode->getIndex()) {
                    $possibleModificationDates[] = $targetNode->getLastModificationDateTime();
                }
            } else {
                // we do not know the date of the deletion so we decide it was just now
                // this will be improved for neos 9
                $possibleModificationDates[] = new \DateTimeImmutable();
            }
        }

        if (count($possibleModificationDates) > 0) {
            sort($possibleModificationDates);
            return reset($possibleModificationDates);
        }

        return null;
    }

    /**
     * @param array<string,string> $targetCoordinates
     * @param bool $force Retranslate every node in the subtree regardless of modification dates,
     *                    instead of only nodes whose target is older than the source.
     */
    public function retranslateNode(
        string $nodeAggregateId,
        string $workspaceName,
        array $targetCoordinates,
        bool $force = false,
    ): RetranslationResult {
        $this->logger->debug(
            sprintf(
                'RetranslateNode: node="%s" workspace="%s" -> target %s (force="%s")',
                $nodeAggregateId,
                $workspaceName,
                \json_encode($targetCoordinates),
                $force ? 'yes' : 'no',
            )
        );

        if ($this->resolveReferenceLanguage($targetCoordinates) === null) {
            $skippedReason = sprintf(
                'no referenceLanguage configured for target language "%s"',
                $targetCoordinates[$this->languageDimensionName] ?? 'unknown'
            );
            $this->logger->warning(
                sprintf('RetranslateNode skipped: "%s"', $skippedReason)
            );

            return RetranslationResult::skipped($skippedReason);
        }

        $sourceContentContext = $this->getReferenceContentContext($workspaceName, $targetCoordinates);

        $sourceNode = $sourceContentContext->getNodeByIdentifier($nodeAggregateId);
        if (!$sourceNode) {
            $this->logger->warning(
                sprintf(
                    'RetranslateNode: no source node "%s" found for workspace "%s" and target %s',
                    $nodeAggregateId,
                    $workspaceName,
                    \json_encode($targetCoordinates),
                )
            );
            throw new \Exception('No source node found in workspace and dimension space point');
        }

        $targetContentContext = $this->getContentContext($workspaceName, $targetCoordinates, true);
        $targetNode = $targetContentContext->getNodeByIdentifier($nodeAggregateId);
        if (!$targetNode) {
            $this->logger->debug(
                sprintf(
                    'RetranslateNode: entry node "%s" missing in target, adopting source node',
                    $nodeAggregateId,
                )
            );
            // translation will be done implicitly here
            $targetContentContext->adoptNode($sourceNode);
        }

        $translatedNodes = 0;
        $adoptedNodes = 0;
        $this->translateDescendants($sourceNode, $targetContentContext, $force, $translatedNodes, $adoptedNodes);

        $this->logger->info(
            sprintf(
                'RetranslateNode complete: node="%s" force="%s" translated=%d adopted=%d',
                $nodeAggregateId,
                $force ? 'yes' : 'no',
                $translatedNodes,
                $adoptedNodes,
            )
        );

        return RetranslationResult::dispatched($translatedNodes, $adoptedNodes);
    }

    /**
     * @param bool $force Treat every node as stale, i.e. translate regardless of modification dates.
     * @param int $translatedNodes Incremented for every node whose translated properties were (re)applied.
     * @param int $adoptedNodes Incremented for every node created in the target because it was missing.
     */
    private function translateDescendants(
        NodeInterface $node,
        ContentContext $targetContentContext,
        bool $force = false,
        int &$translatedNodes = 0,
        int &$adoptedNodes = 0,
    ): void {
        $targetNode = $targetContentContext->getNodeByIdentifier($node->getIdentifier());
        if (!$targetNode) {
            $this->logger->debug(
                sprintf(
                    'Walk: node "%s" missing in target, adopting',
                    $node->getIdentifier(),
                )
            );
            $adoptedNodes++;
            // translation will be done implicitly here
            $targetContentContext->adoptNode($node);
        } else {
            /** @var Node $node */
            /** @var Node $targetNode */
            if ($force || $targetNode->getLastModificationDateTime() < $node->getLastModificationDateTime()) {
                $this->logger->debug(
                    sprintf(
                        'Walk: node "%s" is %s, translating (syncNodeType="%s" syncProperties="%s" syncPosition="%s" syncVisibility="%s")',
                        $node->getIdentifier(),
                        $force ? 'force-translating' : 'stale',
                        $this->synchronizeNodeType ? 'yes' : 'no',
                        $this->synchronizeUntranslatedProperties ? 'yes' : 'no',
                        $this->synchronizeNodePosition ? 'yes' : 'no',
                        $this->synchronizeNodeVisibility ? 'yes' : 'no',
                    )
                );
                $translatedNodes++;
                $this->nodeTranslationService->translateNode($node, $targetNode, $targetContentContext);
                if ($this->synchronizeNodeType && $node->getNodeType()->getName() !== $targetNode->getNodeType()->getName()) {
                    $targetNode->setNodeType($node->getNodeType());
                }
                if ($this->synchronizeUntranslatedProperties) {
                    $translatableProperties = $this->translatablePropertiesFactory->createForNodeType(
                        $node->getNodeType()
                    );
                    $sourceProperties = $node->getProperties();
                    $targetProperties = $targetNode->getProperties();
                    // set properties as in the source if no translation is configured
                    foreach ($sourceProperties as $propertyName => $value) {
                        if (!$translatableProperties->isTranslatable(
                                $propertyName
                            ) && $targetProperties[$propertyName] !== $value) {
                            $targetNode->setProperty($propertyName, $value);
                        }
                    }
                    // remove properties that are not present in the source
                    foreach ($targetProperties as $propertyName => $value) {
                        if ($sourceProperties->offsetExists($propertyName) === false) {
                            $targetNode->removeProperty($propertyName);
                        }
                    }
                }
                // sync node position
                if ($this->synchronizeNodePosition && $node->getIndex() !== $targetNode->getIndex()) {
                    $targetNode->setIndex($node->getIndex());
                }
                if ($this->synchronizeNodeVisibility) {
                    // sync visibility properties
                    if ($node->isHidden() !== $targetNode->isHidden()) {
                        $targetNode->setHidden($node->isHidden());
                    }
                    if ($node->getHiddenBeforeDateTime() !== $targetNode->getHiddenBeforeDateTime()) {
                        $targetNode->setHiddenBeforeDateTime($node->getHiddenBeforeDateTime());
                    }
                    if ($node->getHiddenAfterDateTime() !== $targetNode->getHiddenAfterDateTime()) {
                        $targetNode->setHiddenAfterDateTime($node->getHiddenAfterDateTime());
                    }
                }
            } else {
                $this->logger->debug(
                    sprintf(
                        'Walk: node "%s" is up to date, skipping',
                        $node->getIdentifier(),
                    )
                );
            }
        }

        /**
         * @var array<string, NodeInterface> $sourceNodeByIdentifier
         */
        $sourceNodeByIdentifier = [];
        foreach ($node->getChildNodes('Neos.Neos:Content,Neos.Neos:ContentCollection') as $sourceChildNode) {
            $sourceNodeByIdentifier[$sourceChildNode->getIdentifier()] = $sourceChildNode;
            $this->translateDescendants(
                $sourceChildNode,
                $targetContentContext,
                $force,
                $translatedNodes,
                $adoptedNodes
            );
        }

        if ($this->removeNodesWithoutSource) {
            if ($targetNode instanceof NodeInterface) {
                foreach (
                    $targetNode->getChildNodes(
                        'Neos.Neos:Content,Neos.Neos:ContentCollection'
                    ) as $targetChildNode
                ) {
                    if (!array_key_exists($targetChildNode->getIdentifier(), $sourceNodeByIdentifier)) {
                        $this->logger->debug(
                            sprintf(
                                'Walk: removing target node "%s" without source',
                                $targetChildNode->getIdentifier(),
                            )
                        );
                        $targetChildNode->remove();
                    }
                }
            }
        }
    }

    /**
     * @param array<string,string> $coordinates
     */
    public function getContentContext(string $workspaceName, array $coordinates, bool $withRemoved): ContentContext
    {
        $dimensions = [];
        foreach ($coordinates as $dimensionName => $dimensionValue) {
            $dimensions[$dimensionName] = $this->contentDimensionPresetSource->getAllPresets()[$dimensionName]['presets'][$dimensionValue]['values'];
        }

        /** @var ContentContext $contentContext */
        $contentContext = $this->contentContextFactory->create([
            'workspaceName' => $workspaceName,
            'dimensions' => $dimensions,
            'targetDimensions' => $coordinates,
            'invisibleContentShown' => true,
            'removedContentShown' => $withRemoved,
        ]);

        return $contentContext;
    }

    /**
     * Resolves the reference language configured for the given target language.
     *
     * @param array<string,string> $coordinates
     * @return string|null The reference language preset identifier or null if none is configured
     */
    protected function resolveReferenceLanguage(array $coordinates): ?string
    {
        $targetLanguagePreset = $this->contentDimensionPresetSource->getAllPresets()[$this->languageDimensionName]['presets'][$coordinates[$this->languageDimensionName]];

        return $targetLanguagePreset['options']['referenceLanguage'] ?? null;
    }

    /**
     * @param array<string,string> $coordinates
     */
    public function getReferenceContentContext(string $workspaceName, array $coordinates): ContentContext
    {
        $referenceLanguage = $this->resolveReferenceLanguage($coordinates);
        if ($referenceLanguage === null) {
            throw new \Exception(
                'No reference language configured for target language ' . $coordinates[$this->languageDimensionName]
            );
        }
        $referenceCoordinates = $coordinates;
        $referenceCoordinates[$this->languageDimensionName] = $referenceLanguage;

        return $this->getContentContext($workspaceName, $referenceCoordinates, false);
    }
}
