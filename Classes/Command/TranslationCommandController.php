<?php

namespace Sitegeist\LostInTranslation\Command;

use Doctrine\ORM\EntityManagerInterface;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\Eel\Exception;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Cli\Exception\StopCommandException;
use Neos\Neos\Domain\Repository\SiteRepository;
use Psr\Log\LoggerInterface;
use Sitegeist\LostInTranslation\ContentRepository\NodeTranslationService;

class TranslationCommandController extends CommandController
{
    /**
     * @Flow\InjectConfiguration(path="nodeTranslation.languageDimensionName")
     * @var string
     */
    protected $languageDimensionName;

    /**
     * @Flow\InjectConfiguration(package="Neos.ContentRepository", path="contentDimensions")
     * @var array<string, array{defaultPreset: string}>
     */
    protected $contentDimensionConfiguration;

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @Flow\Inject
     * @var NodeTranslationService
     */
    protected $nodeTranslationService;

    /**
     * @Flow\Inject
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @Flow\Inject
     * @var NodeDataRepository
     */
    protected $nodeDataRepository;

    #[Flow\Inject('Sitegeist.LostInTranslation:TranslationLogger', false)]
    protected LoggerInterface $logger;

    /**
     * @param string $nodePath The start node path to start the sync from, e.g. '/sites/example.com/home'. It must not be '/sites'.
     * @param string $from The ISO language code to take the contents for translation *from*. It must exist as a language dimension preset in the configuration.
     * @param string $to The ISO language code to translate the contents *to*. It must exist as a language dimension preset in the configuration.
     * @param string $nodeTypeFilter Expects exactly one document node type to loop through, otherwise all documents will be looped through.
     *
     * @return void
     * @throws Exception
     * @throws StopCommandException
     */
    public function syncCommand(
        string $nodePath,
        string $from,
        string $to,
        string $nodeTypeFilter = 'Neos.Neos:Document'
    ): void {
        $this->logger->debug(
            sprintf(
                'syncCommand: path="%s" from="%s" to="%s" nodeTypeFilter="%s"',
                $nodePath,
                $from,
                $to,
                $nodeTypeFilter,
            )
        );

        if ($nodePath === '/sites') {
            $this->output->outputLine(
                'The node path must not be "/sites". Please specify a valid node path, e.g. "/sites/example.com/home".'
            );
            $this->quit(1);
        }

        $sourceContext = $this->getContentContext($from);
        $rootNode = $sourceContext->getNode($nodePath);

        if (!$rootNode instanceof NodeInterface) {
            $this->logger->warning(
                sprintf(
                    'syncCommand: node path "%s" does not exist in the source context "%s"',
                    $nodePath,
                    $from,
                )
            );
            $this->output->outputLine('The node path "%s" does not exist in the source context.', [$nodePath]);
            $this->quit(1);
        }

        $nodeTypeFilter = sprintf('[instanceof %s]', $nodeTypeFilter);
        $documentNodeQuery = new FlowQuery([$rootNode]);
        $documentNodeQuery->pushOperation('find', [$nodeTypeFilter]);
        // @phpstan-ignore method.notFound
        $documentNodes = $documentNodeQuery->get();
        array_unshift($documentNodes, $rootNode);

        $this->logger->debug(sprintf('syncCommand: found %d document node(s)', sizeof($documentNodes)));

        $this->output->outputLine('Found %s document nodes', [sizeof($documentNodes)]);
        $this->output->progressStart(sizeof($documentNodes));

        /** @var NodeInterface $documentNode */
        foreach ($documentNodes as $documentNode) {
            $documentNodePath = $documentNode->getPath();
            $this->logger->debug(sprintf('syncCommand: processing document node "%s"', $documentNodePath));
            $rootNode = $this->getContentContext()->getNode($documentNodePath);
            $this->processNode($rootNode, $to);
            $this->nodeDataRepository->persistEntities();
            $this->output->progressAdvance();
        }

        $this->output->progressFinish();
        $this->logger->info(
            sprintf(
                'syncCommand complete: synced "%s" from "%s" to "%s"',
                $nodePath,
                $from,
                $to,
            )
        );
        $this->quit();
    }

    /**
     * @param NodeInterface $node
     * @param string|null $targetPresetIdentifier
     * @return void
     */
    protected function processNode(NodeInterface $node, ?string $targetPresetIdentifier = null): void
    {
        $this->nodeTranslationService->syncNode($node, 'live', $targetPresetIdentifier, true);

        foreach ($node->getChildNodes() as $childNode) {
            if ($childNode->getNodeType()->isOfType('Neos.Neos:Document') || $childNode->getNodeType()->isOfType(
                    'Neos.Neos:Shortcut'
                )) {
                continue;
            }

            $this->processNode($childNode);
        }
    }

    /**
     * @param string|null $languageDimension
     * @return Context
     */
    protected function getContentContext(?string $languageDimension = null): Context
    {
        return $this->nodeTranslationService->getContextForLanguageDimensionAndWorkspaceName(
            $languageDimension ?: $this->contentDimensionConfiguration[$this->languageDimensionName]['defaultPreset']
        );
    }
}
