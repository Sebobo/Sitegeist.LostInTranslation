<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection;

use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\Factory\SubscriberFactoryDependencies;
use Neos\ContentRepository\Core\Projection\ProjectionFactoryInterface;
use Neos\Flow\Annotations as Flow;
use Psr\Log\LoggerInterface;
use Sitegeist\LostInTranslation\Domain\Directive\NodeTypeTranslationDirectiveFactory;
use Sitegeist\LostInTranslation\Domain\ReferenceDimensionSpacePointResolver;

/**
 * @implements ProjectionFactoryInterface<StaleTranslationProjection>
 */
class StaleTranslationProjectionFactory implements ProjectionFactoryInterface
{
    #[Flow\Inject('Sitegeist.LostInTranslation:TranslationLogger', false)]
    protected LoggerInterface $logger;

    public function __construct(
        private readonly Connection $dbal,
        private readonly string $languageDimensionId,
        private readonly NodeTypeTranslationDirectiveFactory $nodeTypeTranslationDirectiveFactory,
    ) {
    }

    public function build(
        SubscriberFactoryDependencies $projectionFactoryDependencies,
        array $options,
    ): StaleTranslationProjection {
        return new StaleTranslationProjection(
            dbal: $this->dbal,
            tableNamePrefix: sprintf(
                'cr_%s_p_staletranslation',
                $projectionFactoryDependencies->contentRepositoryId->value,
            ),
            referenceDimensionSpacePointResolver: new ReferenceDimensionSpacePointResolver(
                allowedDimensionSubspace: $projectionFactoryDependencies->interDimensionalVariationGraph->getDimensionSpacePoints(),
                contentDimensionSource: $projectionFactoryDependencies->contentDimensionSource,
                languageDimensionId: new ContentDimensionId($this->languageDimensionId),
            ),
            nodeTypeTranslationDirectiveFactory: $this->nodeTypeTranslationDirectiveFactory,
            nodeTypeManager: $projectionFactoryDependencies->nodeTypeManager,
            logger: $this->logger,
        );
    }
}
