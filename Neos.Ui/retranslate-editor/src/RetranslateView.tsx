import React from 'react';
import { useMutation } from '@tanstack/react-query';
import { useI18n } from '@sitegeist/lostintranslation-neos-bridge';
import { endpoints } from './hooks/backend';
import { useContentInfo } from './hooks/useContentInfo';
import { useNodeInfo } from './hooks/useNodeInfo';
import { Button } from '@neos-project/react-ui-components'
import { Container, Info, LoadingContainer, Spinner, ButtonsContainer } from './components';

type RetranslateViewTarget = 'node' | 'document';

type RetranslateViewProps = {
    for: RetranslateViewTarget;
};

export const RetranslateView = ({for: target}: RetranslateViewProps) => {
    const t = useI18n();
    const nodeInfo = useNodeInfo(target);
    const { data: contentData, isLoading } = useContentInfo(nodeInfo.nodeId, nodeInfo.workspace, nodeInfo.dimensions, nodeInfo.contentRepositoryId);

    const translateMutation = useMutation({
        mutationFn: async (targetCoordinates: Record<string, string>) => {
            return endpoints().translate({
                nodeAggregateId: nodeInfo.nodeId as string,
                workspaceName: nodeInfo.workspace as string,
                targetCoordinates: JSON.stringify(targetCoordinates),
                contentRepositoryId: nodeInfo.contentRepositoryId
            });
        },
        onSuccess: () => window.location.reload()
    });

    if (isLoading) {
        return (
            <Container>
                <LoadingContainer>
                    <Spinner />
                    <Info>
                        {t('view.loading', '', {}, 'Sitegeist.LostInTranslation', 'Main')}
                    </Info>
                </LoadingContainer>
            </Container>
        );
    }

    if (!contentData || contentData.specializations.length === 0) {
        return null;
    }

    return (
        <Container>
            <ButtonsContainer>
                {contentData.specializations.map((spec) => (
                    <div key={JSON.stringify(spec.targetCoordinates)}>
                        <Info>
                            {t(
                                'view.outdated',
                                '',
                                {
                                    language: spec.targetLanguage.label,
                                    count: String(spec.staleNodeCount)
                                },
                                'Sitegeist.LostInTranslation',
                                'Main'
                            )}
                        </Info>
                        {translateMutation.isPending ? (
                            <LoadingContainer>
                                <Spinner />
                                <Info>
                                    {t('view.translating', '', {}, 'Sitegeist.LostInTranslation', 'Main')}
                                </Info>
                            </LoadingContainer>
                        ) : (
                            <Button onClick={() => translateMutation.mutate(spec.targetCoordinates)}>
                                {t('button.translate', '', {}, 'Sitegeist.LostInTranslation', 'Main')}
                            </Button>
                        )}
                    </div>
                ))}
            </ButtonsContainer>
        </Container>
    );
};
