<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Controller;

use Neos\ContentRepository\Domain\Model\Node;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Neos\Domain\Service\ContentDimensionPresetSourceInterface;
use Psr\Log\LoggerInterface;
use Sitegeist\LostInTranslation\ContentRepository\RetranslationService;

class RetranslationController extends ActionController
{
    /**
     * @Flow\InjectConfiguration(path="nodeTranslation.languageDimensionName")
     * @var string
     */
    protected $languageDimensionName;

    #[Flow\Inject('Sitegeist.LostInTranslation:TranslationLogger', false)]
    protected LoggerInterface $translationLogger;

    public function __construct(
        protected readonly ContentDimensionPresetSourceInterface $contentDimensionPresetSource,
        protected readonly RetranslationService $retranslationService,
    ) {
    }

    public function getTranslationMetadataAction(
        string $nodeAggregateId,
        string $workspaceName,
        string $coordinates
    ): string {
        $this->translationLogger->debug(
            sprintf(
                'getTranslationMetadata: node="%s" workspace="%s" coordinates=%s',
                $nodeAggregateId,
                $workspaceName,
                $coordinates,
            )
        );

        $targetCoordinates = \json_decode($coordinates, true);
        $targetLanguagePreset = $this->contentDimensionPresetSource->getAllPresets()[$this->languageDimensionName]['presets'][$targetCoordinates[$this->languageDimensionName]];
        $sourceLanguage = $targetLanguagePreset['options']['referenceLanguage'] ?? null;

        $this->translationLogger->debug(
            sprintf(
                'getTranslationMetadata: target DSP %s resolved to reference language "%s"',
                $coordinates,
                $sourceLanguage ?? 'none',
            )
        );

        $targetContentContext = $this->retranslationService->getContentContext(
            $workspaceName,
            $targetCoordinates,
            false
        );
        $targetNode = $targetContentContext->getNodeByIdentifier($nodeAggregateId);
        if (!$targetNode instanceof Node) {
            $this->translationLogger->debug(
                sprintf(
                    'getTranslationMetadata: target node "%s" not found in workspace "%s"',
                    $nodeAggregateId,
                    $workspaceName,
                )
            );
            throw new \Exception('Node not found in workspace and dimension space point');
        }

        $sourceUpdateDate = null;
        $sourceLanguagePreset = null;
        if ($sourceLanguage) {
            $sourceContentContext = $this->retranslationService->getReferenceContentContext(
                $workspaceName,
                $targetCoordinates
            );
            /** @var ?Node $sourceNode */
            $sourceNode = $sourceContentContext->getNodeByIdentifier($nodeAggregateId);
            if ($sourceNode instanceof Node) {
                $sourceUpdateDate = $this->retranslationService->findFirstUpdateDateOnNodeOrDescendants(
                    $sourceNode,
                    $sourceContentContext,
                    $targetContentContext
                );
            } else {
                $this->translationLogger->debug(
                    sprintf(
                        'getTranslationMetadata: source node "%s" not found in reference language',
                        $nodeAggregateId,
                    )
                );
            }
            $sourceLanguagePreset = $this->contentDimensionPresetSource->getAllPresets()[$this->languageDimensionName]['presets'][$sourceContentContext->getTargetDimensions()[$this->languageDimensionName]];
        }

        $this->translationLogger->debug(
            sprintf(
                'getTranslationMetadata result: isUpToDate="%s" sourceUpdateDate="%s"',
                $sourceUpdateDate === null ? 'yes' : 'no',
                $sourceUpdateDate?->format(\DateTime::ATOM) ?? 'null',
            )
        );

        /** otherwise, getNodeByIdentifier might register a new object ¯\_(ツ)_/¯ */
        $this->persistenceManager->clearState();

        return \json_encode(
            [
                'isUpToDate' => $sourceUpdateDate === null,
                'referenceLanguage' => $sourceLanguage
                    ? [
                        'label' => $sourceLanguagePreset ? $sourceLanguagePreset['label'] : null,
                        'dateModified' => $sourceUpdateDate?->format(\DateTime::ATOM),
                    ]
                    : null,
            ],
            JSON_THROW_ON_ERROR,
        );
    }

    public function retranslateNodeAction(
        string $nodeAggregateId,
        string $workspaceName,
        string $targetCoordinates,
    ): string {
        $this->translationLogger->debug(
            sprintf(
                'retranslateNode: node="%s" workspace="%s" target=%s',
                $nodeAggregateId,
                $workspaceName,
                $targetCoordinates,
            )
        );

        $targetCoordinates = \json_decode($targetCoordinates, true);
        try {
            $this->retranslationService->retranslateNode($nodeAggregateId, $workspaceName, $targetCoordinates);
        } catch (\Exception $exception) {
            $this->translationLogger->error(
                sprintf(
                    'retranslateNode failed: node="%s" workspace="%s" target=%s error="%s"',
                    $nodeAggregateId,
                    $workspaceName,
                    \json_encode($targetCoordinates),
                    $exception->getMessage(),
                )
            );
            throw $exception;
        }

        $this->translationLogger->debug(
            sprintf(
                'retranslateNode result: successfully translated node "%s"',
                $nodeAggregateId,
            )
        );

        return \json_encode(
            [
                'message' => 'Successfully translated'
            ],
            JSON_THROW_ON_ERROR,
        );
    }
}
