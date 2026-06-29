<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\Dimension\ContentDimensionSourceInterface;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePointSet;
use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
final readonly class ReferenceDimensionSpacePointResolver
{
    public function __construct(
        private DimensionSpacePointSet $allowedDimensionSubspace,
        private ContentDimensionSourceInterface $contentDimensionSource,
        private ContentDimensionId $languageDimensionId,
    ) {
    }

    public function tryResolveTargetDimensionSpacePoint(DimensionSpacePoint $dimensionSpacePoint): ?DimensionSpacePoint
    {
        $languageDimension = $this->contentDimensionSource->getDimension($this->languageDimensionId);
        if ($languageDimension === null) {
            return null;
        }

        $targetLanguageValue = $dimensionSpacePoint->coordinates[$this->languageDimensionId->value] ?? null;
        if ($targetLanguageValue === null) {
            return null;
        }

        foreach ($languageDimension->values as $targetLanguage) {
            $referenceLanguage = $targetLanguage->configuration['referenceLanguage'] ?? null;
            if ($referenceLanguage === null && !$targetLanguage->specializationDepth->isZero()) {
                $referenceLanguage = $languageDimension->getGeneralization($targetLanguage)?->value;
            }
            if ($referenceLanguage === $targetLanguageValue) {
                $coordinates = $dimensionSpacePoint->coordinates;
                $coordinates[$this->languageDimensionId->value] = $targetLanguage->value;
                $targetDimensionSpacePoint = DimensionSpacePoint::fromArray($coordinates);

                return $this->allowedDimensionSubspace->contains($targetDimensionSpacePoint)
                    ? $targetDimensionSpacePoint
                    : null;
            }
        }

        return null;
    }

    /**
     * @return array<int, DimensionSpacePoint>
     */
    public function tryResolveTargetDimensionSpacePoints(DimensionSpacePoint $dimensionSpacePoint): array
    {
        $languageDimension = $this->contentDimensionSource->getDimension($this->languageDimensionId);
        if ($languageDimension === null) {
            return [];
        }

        $sourceLanguageValue = $dimensionSpacePoint->coordinates[$this->languageDimensionId->value] ?? null;
        if ($sourceLanguageValue === null) {
            return [];
        }

        $targets = [];
        foreach ($this->allowedDimensionSubspace as $targetDimensionSpacePoint) {
            $targetLanguageValue = $targetDimensionSpacePoint->coordinates[$this->languageDimensionId->value] ?? null;
            if ($targetLanguageValue === null) {
                continue;
            }

            $targetLanguage = $languageDimension->getValue($targetLanguageValue);
            if ($targetLanguage === null) {
                continue;
            }

            $referenceLanguage = $targetLanguage->configuration['referenceLanguage'] ?? null;
            if ($referenceLanguage === null && !$targetLanguage->specializationDepth->isZero()) {
                $referenceLanguage = $languageDimension->getGeneralization($targetLanguage)?->value;
            }
            if ($referenceLanguage === $sourceLanguageValue) {
                $targets[] = $targetDimensionSpacePoint;
            }
        }

        return $targets;
    }

    public function tryResolveSourceDimensionSpacePoint(DimensionSpacePoint $dimensionSpacePoint): ?DimensionSpacePoint
    {
        $languageDimension = $this->contentDimensionSource->getDimension($this->languageDimensionId);
        if ($languageDimension === null) {
            return null;
        }

        $languageValue = $dimensionSpacePoint->coordinates[$this->languageDimensionId->value] ?? null;
        if ($languageValue === null) {
            return null;
        }

        $language = $languageDimension->getValue($languageValue);
        $sourceLanguageValue = $language->configuration['referenceLanguage'] ?? null;
        if (($sourceLanguageValue === null) && !$language->specializationDepth->isZero()) {
            $sourceLanguageValue = $languageDimension->getGeneralization($language)?->value;
        }

        if ($sourceLanguageValue === null) {
            return null;
        }

        $coordinates = $dimensionSpacePoint->coordinates;
        $coordinates[$this->languageDimensionId->value] = $sourceLanguageValue;
        $sourceDimensionSpacePoint = DimensionSpacePoint::fromArray($coordinates);

        return $this->allowedDimensionSubspace->contains($sourceDimensionSpacePoint)
            ? $sourceDimensionSpacePoint
            : null;
    }
}
